<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;

final class UpgradeRuntimeOwnerLock
{
    private bool $released = false;

    /** @param resource $slotHandle @param array<string|int,mixed> $slotIdentity */
    private function __construct(
        private $slotHandle,
        private readonly string $slotPath,
        private readonly array $slotIdentity,
        public readonly string $slotId,
    ) {
    }

    /** @param Closure(string):bool|null $trustProof */
    public static function acquire(
        string $allocationPath,
        string $slotPath,
        string $slotId,
        int $timeoutMilliseconds = 2000,
        ?Closure $trustProof = null,
    ): self {
        self::validateInputs($allocationPath, $slotPath, $slotId, $timeoutMilliseconds, $trustProof);
        $allocation = self::openVerified($allocationPath, 'allocation', $trustProof);
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        try {
            if (!self::lockUntil($allocation['handle'], LOCK_EX, $deadline)) {
                throw new RuntimeException('UPGRADE_RUNTIME_ALLOCATION_BUSY');
            }
            $slot = self::openVerified($slotPath, $slotId, $trustProof);
            try {
                if (!@flock($slot['handle'], LOCK_SH | LOCK_NB)) {
                    throw new RuntimeException('UPGRADE_RUNTIME_SLOT_BUSY');
                }
                self::assertNamedIdentity($slotPath, $slot['identity']);
            } catch (\Throwable $exception) {
                @fclose($slot['handle']);
                throw $exception;
            }
        } finally {
            @flock($allocation['handle'], LOCK_UN);
            @fclose($allocation['handle']);
        }

        return new self($slot['handle'], $slotPath, $slot['identity'], $slotId);
    }

    /**
     * @param Closure():array<string,string> $candidateProvider slot_id => absolute path，按确定性优先级排序
     * @param Closure(string):void $publish
     * @param Closure(string):bool|null $trustProof
     */
    public static function acquireFromPool(
        string $allocationPath,
        Closure $candidateProvider,
        Closure $publish,
        int $timeoutMilliseconds = 2000,
        ?Closure $trustProof = null,
    ): self {
        self::validateInputs(
            $allocationPath,
            dirname($allocationPath) . DIRECTORY_SEPARATOR . 'candidate.lock',
            'candidate',
            $timeoutMilliseconds,
            $trustProof,
            false,
        );
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;

        do {
            $allocation = self::openVerified($allocationPath, 'allocation', $trustProof);
            $allocationLocked = false;
            $slot = null;
            $slotTransferred = false;
            try {
                if (!@flock($allocation['handle'], LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $allocationLocked = true;
                $candidates = $candidateProvider();
                if (!is_array($candidates) || $candidates === [] || count($candidates) > 4096) {
                    throw new RuntimeException('UPGRADE_RUNTIME_LOCK_POOL_EXHAUSTED');
                }
                $slotId = null;
                $slotPath = null;
                foreach ($candidates as $candidateId => $candidatePath) {
                    if (!is_string($candidateId) || !is_string($candidatePath)) {
                        throw new RuntimeException('UPGRADE_RUNTIME_LOCK_POOL_INVALID');
                    }
                    self::validateInputs(
                        $allocationPath,
                        $candidatePath,
                        $candidateId,
                        $timeoutMilliseconds,
                        $trustProof,
                    );
                    $candidate = self::openVerified($candidatePath, $candidateId, $trustProof);
                    if (@flock($candidate['handle'], LOCK_SH | LOCK_NB)) {
                        $slotId = $candidateId;
                        $slotPath = $candidatePath;
                        $slot = $candidate;
                        break;
                    }
                    @fclose($candidate['handle']);
                }
                if ($slot === null || $slotId === null || $slotPath === null) {
                    // 所有候选都繁忙；不持有 allocation 等待槽位。
                    continue;
                }
                self::assertNamedIdentity($slotPath, $slot['identity']);
                try {
                    $publish($slotId);
                    self::assertNamedIdentity($slotPath, $slot['identity']);
                } catch (\Throwable $exception) {
                    @flock($slot['handle'], LOCK_UN);
                    throw $exception;
                }

                $lock = new self($slot['handle'], $slotPath, $slot['identity'], $slotId);
                $slotTransferred = true;

                return $lock;
            } finally {
                if (!$slotTransferred && $slot !== null && is_resource($slot['handle'])) {
                    @flock($slot['handle'], LOCK_UN);
                    @fclose($slot['handle']);
                }
                if ($allocationLocked) {
                    @flock($allocation['handle'], LOCK_UN);
                }
                @fclose($allocation['handle']);
            }
        } while (self::waitForRetry($deadline));

        throw new RuntimeException('UPGRADE_RUNTIME_SLOT_BUSY');
    }

    /** @param Closure():void $retire @param Closure(string):bool|null $trustProof */
    public static function tryRetire(
        string $allocationPath,
        string $slotPath,
        string $slotId,
        Closure $retire,
        ?Closure $trustProof = null,
    ): bool {
        self::validateInputs($allocationPath, $slotPath, $slotId, 1, $trustProof);
        $allocation = self::openVerified($allocationPath, 'allocation', $trustProof);
        if (!@flock($allocation['handle'], LOCK_EX | LOCK_NB)) {
            @fclose($allocation['handle']);

            return false;
        }
        try {
            $slot = self::openVerified($slotPath, $slotId, $trustProof);
            try {
                if (!@flock($slot['handle'], LOCK_EX | LOCK_NB)) {
                    return false;
                }
                self::assertNamedIdentity($slotPath, $slot['identity']);
                $retire();

                return true;
            } finally {
                @flock($slot['handle'], LOCK_UN);
                @fclose($slot['handle']);
            }
        } finally {
            @flock($allocation['handle'], LOCK_UN);
            @fclose($allocation['handle']);
        }
    }

    public function verifyStillCanonical(): void
    {
        if ($this->released || !is_resource($this->slotHandle)) {
            throw new RuntimeException('UPGRADE_RUNTIME_SLOT_RELEASED');
        }
        self::assertNamedIdentity($this->slotPath, $this->slotIdentity);
        $descriptor = fstat($this->slotHandle);
        if (!is_array($descriptor)) {
            throw new RuntimeException('UPGRADE_RUNTIME_SLOT_REPLACED');
        }
        self::assertIdentity($descriptor, $this->slotIdentity);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        if (is_resource($this->slotHandle)) {
            @flock($this->slotHandle, LOCK_UN);
            @fclose($this->slotHandle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /** @param Closure(string):bool|null $trustProof */
    private static function validateInputs(
        string $allocationPath,
        string $slotPath,
        string $slotId,
        int $timeoutMilliseconds,
        ?Closure $trustProof,
        bool $validateTrust = true,
    ): void {
        foreach ([$allocationPath, $slotPath] as $path) {
            if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR) || str_contains($path, "\0")
                || str_contains($path, '/./') || str_contains($path, '/../')) {
                throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
            }
        }
        if ($allocationPath === $slotPath || preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $slotId) !== 1
            || $timeoutMilliseconds < 1 || $timeoutMilliseconds > 60_000
            || ($validateTrust && $trustProof !== null
                && (!$trustProof($allocationPath) || !$trustProof($slotPath)))) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
        }
    }

    /** @param Closure(string):bool|null $trustProof @return array{handle:resource,identity:array<string|int,mixed>} */
    private static function openVerified(string $path, string $expectedId, ?Closure $trustProof): array
    {
        if ($trustProof === null && !self::nativeTrustProof($path)) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_UNTRUSTED');
        }
        $named = @lstat($path);
        if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000 || ($named['mode'] & 07777) !== 0444
            || ($named['nlink'] ?? 0) !== 1 || ($named['size'] ?? 0) < 1 || $named['size'] > 4096) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
        }
        $descriptor = fstat($handle);
        if (!is_array($descriptor)) {
            @fclose($handle);
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
        }
        self::assertIdentity($descriptor, $named);
        $raw = stream_get_contents($handle, 4097);
        if (!is_string($raw) || strlen($raw) > 4096) {
            @fclose($handle);
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
        }
        $document = json_decode($raw, true);
        $expectedKinds = $expectedId === 'allocation'
            ? ['allocation', 'runtime_lifetime']
            : ['runtime_lifetime'];
        if (!is_array($document) || array_keys($document) !== ['schema_version', 'kind', 'slot_id']
            || ($document['schema_version'] ?? null) !== 1
            || !in_array($document['kind'] ?? null, $expectedKinds, true)
            || ($document['slot_id'] ?? null) !== $expectedId) {
            @fclose($handle);
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_INVALID');
        }

        return ['handle' => $handle, 'identity' => $named];
    }

    /** @param resource $handle */
    private static function lockUntil($handle, int $operation, int $deadline): bool
    {
        do {
            if (@flock($handle, $operation | LOCK_NB)) {
                return true;
            }
            usleep(5_000);
        } while (hrtime(true) < $deadline);

        return false;
    }

    private static function waitForRetry(int $deadline): bool
    {
        if (hrtime(true) >= $deadline) {
            return false;
        }
        usleep(5_000);

        return hrtime(true) < $deadline;
    }

    /** @param array<string|int,mixed> $identity */
    private static function assertNamedIdentity(string $path, array $identity): void
    {
        $current = @lstat($path);
        if (!is_array($current)) {
            throw new RuntimeException('UPGRADE_RUNTIME_SLOT_REPLACED');
        }
        self::assertIdentity($current, $identity);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function assertIdentity(array $left, array $right): void
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                throw new RuntimeException('UPGRADE_RUNTIME_SLOT_REPLACED');
            }
        }
    }

    private static function nativeTrustProof(string $path): bool
    {
        $parent = dirname($path);
        $parentStat = @lstat($parent);
        if (!is_array($parentStat) || ($parentStat['mode'] & 0170000) !== 0040000
            || (($parentStat['mode'] & 0022) !== 0)) {
            return false;
        }
        $mountInfo = @file_get_contents('/proc/self/mountinfo');
        if (!is_string($mountInfo)) {
            return false;
        }
        foreach (explode("\n", $mountInfo) as $line) {
            $parts = explode(' ', $line);
            $separator = array_search('-', $parts, true);
            if (!is_int($separator) || $separator < 6) {
                continue;
            }
            $mountPoint = strtr($parts[4], ['\\040' => ' ', '\\134' => '\\']);
            if ($mountPoint === $parent && in_array('ro', explode(',', $parts[5]), true)) {
                return true;
            }
        }

        return false;
    }
}
