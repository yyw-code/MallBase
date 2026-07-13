<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use RuntimeException;
use Throwable;

/**
 * 校验固定 Agent 二进制及其同目录校验清单的不可替换信任边界。
 */
final class AgentBinaryTrustValidator
{
    private const MAX_CHECKSUM_BYTES = 64 * 1024;
    private const ALLOWED_BINARIES = [
        'mallbase-agent-linux-amd64',
        'mallbase-agent-linux-arm64',
    ];

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
                || str_contains($binaryPath, "\0") || $binaryPath !== $this->cleanAbsolutePath($binaryPath)) {
                $this->fail();
            }
            $basename = basename($binaryPath);
            if (!in_array($basename, self::ALLOWED_BINARIES, true)) {
                $this->fail();
            }
            $directory = dirname($binaryPath);
            $checksumsPath = $directory . DIRECTORY_SEPARATOR . 'checksums.sha256';

            $directoryStat = $this->lstat($directory);
            $this->assertDirectory($directoryStat, $this->expectedOwnerUid, 0555);
            $binaryStat = $this->lstat($binaryPath);
            $checksumStat = $this->lstat($checksumsPath);
            $this->assertRegular($binaryStat, $this->expectedOwnerUid, 0555);
            $this->assertRegular($checksumStat, $this->expectedOwnerUid, 0444);

            $mountProof = $this->readOnlyMountProof;
            $ancestorProof = $this->ancestorImmutabilityProof;
            $capabilityProof = $this->capabilityAbsentProof;
            $readOnlyNoSuidMount = $mountProof($directory);
            if (!$ancestorProof($directory)
                || (!$readOnlyNoSuidMount && !$capabilityProof($binaryPath))) {
                $this->fail();
            }

            [$checksumBytes, $checksumOpened] = $this->readPinned($checksumsPath, self::MAX_CHECKSUM_BYTES);
            $this->assertSameIdentity($checksumStat, $checksumOpened);
            $expected = $this->parseChecksum($checksumBytes, $basename);
            [$binaryHash, $binaryOpened] = $this->hashPinned($binaryPath);
            $this->assertSameIdentity($binaryStat, $binaryOpened);
            if (!hash_equals($expected, $binaryHash)) {
                $this->fail();
            }

            $this->assertSameIdentity($directoryStat, $this->lstat($directory));
            $this->assertSameIdentity($binaryStat, $this->lstat($binaryPath));
            $this->assertSameIdentity($checksumStat, $this->lstat($checksumsPath));
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
    private function readPinned(string $path, int $maximum): array
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
            $bytes = stream_get_contents($handle, $maximum + 1);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $maximum || !feof($handle)) {
                $this->fail();
            }
            $after = fstat($handle);
            if (!is_array($after)) {
                $this->fail();
            }
            $this->assertSameIdentity($opened, $after);

            return [$bytes, $opened];
        } finally {
            fclose($handle);
        }
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

    private function parseChecksum(string $bytes, string $basename): string
    {
        $found = null;
        $seen = [];
        $lines = explode("\n", $bytes);
        foreach ($lines as $index => $line) {
            if ($line === '' && $index === count($lines) - 1) {
                continue;
            }
            if (preg_match('/^([0-9a-f]{64})  (mallbase-agent-linux-(?:amd64|arm64))$/D', $line, $match) !== 1
                || isset($seen[$match[2]])) {
                $this->fail();
            }
            $seen[$match[2]] = true;
            if ($match[2] === $basename) {
                $found = $match[1];
            }
        }
        if (!is_string($found)) {
            $this->fail();
        }

        return $found;
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
