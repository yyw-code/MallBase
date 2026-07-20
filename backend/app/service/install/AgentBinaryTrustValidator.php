<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use RuntimeException;
use Throwable;

/**
 * 校验固定 Agent 活动二进制的最小执行信任边界。
 *
 * 发布候选及其签名由 Agent 自身校验；PHP 只允许执行 Agent 用户拥有的
 * 固定 active/mallbase-agent 文件，且 PHP 用户不能替换其目录或二进制。
 */
final class AgentBinaryTrustValidator
{
    private const ACTIVE_BINARY_NAME = 'mallbase-agent';

    /** @var Closure(string):bool */
    private readonly Closure $readOnlyMountProof;

    /** @var Closure(string):bool */
    private readonly Closure $ancestorImmutabilityProof;

    /** @var Closure(string):bool */
    private readonly Closure $capabilityAbsentProof;

    public function __construct(
        private readonly int $expectedOwnerUid,
        private readonly int $phpEuid,
        ?Closure $readOnlyMountProof = null,
        ?Closure $ancestorImmutabilityProof = null,
        ?Closure $capabilityAbsentProof = null,
    ) {
        if ($this->expectedOwnerUid < 0 || $this->phpEuid < 0 || $this->expectedOwnerUid === $this->phpEuid) {
            $this->fail();
        }
        $this->readOnlyMountProof = $readOnlyMountProof ?? $this->nativeReadOnlyMountProof(...);
        $this->ancestorImmutabilityProof = $ancestorImmutabilityProof ?? $this->nativeAncestorImmutabilityProof(...);
        $this->capabilityAbsentProof = $capabilityAbsentProof ?? $this->nativeCapabilityAbsentProof(...);
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('agent.agent_uid', -1),
            (int) config('agent.php_euid', -1),
        );
    }

    public function validate(string $binaryPath): void
    {
        try {
            if ($binaryPath === '' || !str_starts_with($binaryPath, DIRECTORY_SEPARATOR)
                || str_contains($binaryPath, "\0") || $binaryPath !== $this->cleanAbsolutePath($binaryPath)
                || basename($binaryPath) !== self::ACTIVE_BINARY_NAME
                || basename(dirname($binaryPath)) !== 'active') {
                $this->fail();
            }

            $directory = dirname($binaryPath);
            $directoryStat = $this->lstat($directory);
            $binaryStat = $this->lstat($binaryPath);
            $this->assertDirectory($directoryStat, $this->expectedOwnerUid, 0750);
            $this->assertRegular($binaryStat, $this->expectedOwnerUid, 0755);

            $mountProof = $this->readOnlyMountProof;
            $ancestorProof = $this->ancestorImmutabilityProof;
            $capabilityProof = $this->capabilityAbsentProof;
            $readOnlyNoSuidMount = $mountProof($directory);
            if (!$ancestorProof($directory)
                || (!$readOnlyNoSuidMount && !$capabilityProof($binaryPath))) {
                $this->fail();
            }

            [, $binaryOpened] = $this->hashPinned($binaryPath);
            $this->assertSameIdentity($binaryStat, $binaryOpened);
            $this->assertSameIdentity($directoryStat, $this->lstat($directory));
            $this->assertSameIdentity($binaryStat, $this->lstat($binaryPath));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'AGENT_BINARY_UNTRUSTED') {
                throw $exception;
            }
            $this->fail();
        } catch (Throwable) {
            $this->fail();
        }
    }

    private function cleanAbsolutePath(string $path): string
    {
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return '';
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    /** @param array<string|int, mixed> $stat */
    private function assertDirectory(array $stat, int $owner, int $mode): void
    {
        if (($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 07777) !== $mode
            || $stat['uid'] !== $owner || ($stat['nlink'] ?? 0) < 2) {
            $this->fail();
        }
    }

    /** @param array<string|int, mixed> $stat */
    private function assertRegular(array $stat, int $owner, int $mode): void
    {
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== $mode
            || $stat['uid'] !== $owner || ($stat['nlink'] ?? 0) !== 1) {
            $this->fail();
        }
    }

    private function nativeAncestorImmutabilityProof(string $directory): bool
    {
        if ($this->phpEuid === 0) {
            return false;
        }
        $groups = function_exists('posix_getgroups') ? posix_getgroups() : [];
        $groups = is_array($groups) ? array_map('intval', $groups) : [];
        if (function_exists('posix_getegid')) {
            $groups[] = (int) posix_getegid();
            $groups = array_values(array_unique($groups));
        }
        for ($current = $directory; ; $current = dirname($current)) {
            $stat = $this->lstat($current);
            if (($stat['mode'] & 0170000) !== 0040000) {
                return false;
            }
            $mode = $stat['mode'] & 0777;
            $writable = $stat['uid'] === $this->phpEuid
                || (in_array((int) $stat['gid'], $groups, true) && ($mode & 0020) !== 0)
                || ($mode & 0002) !== 0;
            if ($writable) {
                return false;
            }
            if ($current === DIRECTORY_SEPARATOR) {
                break;
            }
        }

        return true;
    }

    /** @return array{0:string,1:array<string|int,mixed>} */
    private function hashPinned(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            $this->fail();
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened)) {
                $this->fail();
            }
            $context = hash_init('sha256');
            if (hash_update_stream($context, $handle) === false) {
                $this->fail();
            }
            $digest = hash_final($context);
            $after = fstat($handle);
            if (!is_array($after)) {
                $this->fail();
            }
            $this->assertSameIdentity($opened, $after);

            return [$digest, $opened];
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string|int, mixed> */
    private function lstat(string $path): array
    {
        $stat = @lstat($path);
        if (!is_array($stat)) {
            $this->fail();
        }

        return $stat;
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function assertSameIdentity(array $left, array $right): void
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                $this->fail();
            }
        }
    }

    private function nativeReadOnlyMountProof(string $directory): bool
    {
        $raw = @file_get_contents('/proc/self/mountinfo');
        if (!is_string($raw) || strlen($raw) > 4 * 1024 * 1024) {
            return false;
        }
        foreach (explode("\n", $raw) as $line) {
            $parts = explode(' ', $line);
            $separator = array_search('-', $parts, true);
            if (!is_int($separator) || $separator < 6) {
                continue;
            }
            $mountPoint = strtr($parts[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            if ($mountPoint !== $directory) {
                continue;
            }
            $mountOptions = explode(',', $parts[5]);
            $superOptions = isset($parts[$separator + 3]) ? explode(',', $parts[$separator + 3]) : [];
            $options = array_values(array_unique([...$mountOptions, ...$superOptions]));

            return in_array('ro', $options, true) && in_array('nosuid', $options, true);
        }

        return false;
    }

    private function nativeCapabilityAbsentProof(string $path): bool
    {
        if (!function_exists('xattr_list')) {
            return false;
        }

        $attributes = @xattr_list($path);
        if (!is_array($attributes)) {
            return false;
        }
        foreach ($attributes as $attribute) {
            if (!is_string($attribute)) {
                return false;
            }
        }

        return !in_array('security.capability', $attributes, true);
    }

    private function fail(): never
    {
        throw new RuntimeException('AGENT_BINARY_UNTRUSTED');
    }
}
