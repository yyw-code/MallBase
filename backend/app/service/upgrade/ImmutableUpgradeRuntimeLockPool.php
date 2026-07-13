<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class ImmutableUpgradeRuntimeLockPool implements UpgradeRuntimeLockPool
{
    /** @var array<string,string> */
    private array $slots;

    /**
     * @param array<string,string> $slots slot_id => immutable file name
     * @param Closure(string):bool|null $trustProof
     */
    public function __construct(
        private string $allocationPath,
        private string $slotDirectory,
        array $slots,
        private ?Closure $trustProof = null,
        private int $allocationTimeoutMilliseconds = 2000,
    ) {
        if ($this->allocationPath === '' || $this->slotDirectory === ''
            || !str_starts_with($this->allocationPath, DIRECTORY_SEPARATOR)
            || !str_starts_with($this->slotDirectory, DIRECTORY_SEPARATOR)
            || $this->allocationTimeoutMilliseconds < 1 || $this->allocationTimeoutMilliseconds > 60_000
            || $slots === []) {
            throw new InvalidArgumentException('UPGRADE_RUNTIME_LOCK_POOL_INVALID');
        }
        foreach ($slots as $slotId => $fileName) {
            if (!is_string($slotId) || preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $slotId) !== 1
                || !is_string($fileName) || preg_match('/^[0-9A-Za-z_.-]{1,128}\.lock$/D', $fileName) !== 1) {
                throw new InvalidArgumentException('UPGRADE_RUNTIME_LOCK_POOL_INVALID');
            }
        }
        $this->slots = $slots;
    }

    /** @param Closure(string):bool|null $trustProof */
    public static function fromManifest(
        string $manifestPath,
        ?Closure $trustProof = null,
        int $allocationTimeoutMilliseconds = 2000,
    ): self {
        $manifest = self::readManifest($manifestPath, $trustProof);
        $directory = dirname($manifestPath);
        $allocation = $manifest['allocation'];
        $slots = [];
        foreach ($manifest['slots'] as $slot) {
            $slots[$slot['slot_id']] = $slot['file_name'];
        }

        return new self(
            $directory . DIRECTORY_SEPARATOR . $allocation['file_name'],
            $directory,
            $slots,
            $trustProof,
            $allocationTimeoutMilliseconds,
        );
    }

    /** @param Closure(string):void $publish */
    public function acquireForRegistration(
        UpgradeRuntimeInstance $instance,
        UpgradeRuntimeRegistry $registry,
        Closure $publish,
    ): UpgradeRuntimeOwnerLock {
        return UpgradeRuntimeOwnerLock::acquireFromPool(
            $this->allocationPath,
            fn(): array => $this->registrationCandidates($instance, $registry->active()),
            $publish,
            $this->allocationTimeoutMilliseconds,
            $this->trustProof,
        );
    }

    public function tryRetire(array $runtimeRecord, Closure $retire): bool
    {
        $slotId = $runtimeRecord['slot_id'] ?? null;
        if (!is_string($slotId) || !isset($this->slots[$slotId])) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_LOCK_ASSIGNMENT_INVALID');
        }

        return UpgradeRuntimeOwnerLock::tryRetire(
            $this->allocationPath,
            $this->slotDirectory . DIRECTORY_SEPARATOR . $this->slots[$slotId],
            $slotId,
            $retire,
            $this->trustProof,
        );
    }

    /**
     * @param list<array<string,mixed>> $runtimeRecords
     * @return array<string,string>
     */
    private function registrationCandidates(UpgradeRuntimeInstance $instance, array $runtimeRecords): array
    {
        $slotOwners = [];
        $assignedSlot = null;
        foreach ($runtimeRecords as $record) {
            $slotId = $record['slot_id'] ?? null;
            if (!is_string($slotId) || !isset($this->slots[$slotId])) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_LOCK_ASSIGNMENT_INVALID');
            }
            try {
                $owner = UpgradeRuntimeInstance::fromArray([
                    'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
                    'boot_id' => $record['boot_id'] ?? null,
                    'role' => $record['role'] ?? null,
                    'app_version' => $record['app_version'] ?? null,
                    'deployment_id' => $record['deployment_id'] ?? null,
                    'storage_layout_version' => $record['storage_layout_version'] ?? null,
                    'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
                    'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
                ]);
            } catch (Throwable) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_LOCK_ASSIGNMENT_INVALID');
            }
            $ownerKey = $owner->key();
            if (isset($slotOwners[$slotId]) && $slotOwners[$slotId] !== $ownerKey) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_LOCK_ASSIGNMENT_INVALID');
            }
            $slotOwners[$slotId] = $ownerKey;
            if ($ownerKey === $instance->key()) {
                if ($owner->toArray() !== $instance->toArray()
                    || ($assignedSlot !== null && $assignedSlot !== $slotId)) {
                    throw new UpgradeStateConflict('UPGRADE_RUNTIME_LOCK_ASSIGNMENT_INVALID');
                }
                $assignedSlot = $slotId;
            }
        }

        if ($assignedSlot !== null) {
            return [$assignedSlot => $this->slotPath($assignedSlot)];
        }

        $slotIds = array_keys($this->slots);
        $offset = hexdec(substr(hash('sha256', $instance->key()), 0, 8)) % count($slotIds);
        $candidates = [];
        for ($index = 0, $count = count($slotIds); $index < $count; $index++) {
            $slotId = $slotIds[($offset + $index) % $count];
            if (!isset($slotOwners[$slotId])) {
                $candidates[$slotId] = $this->slotPath($slotId);
            }
        }
        if ($candidates === []) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_LOCK_POOL_EXHAUSTED');
        }

        return $candidates;
    }

    private function slotPath(string $slotId): string
    {
        return $this->slotDirectory . DIRECTORY_SEPARATOR . $this->slots[$slotId];
    }

    /**
     * @param Closure(string):bool|null $trustProof
     * @return array{
     *   schema_version:int,
     *   kind:string,
     *   allocation:array{file_name:string,mode:int,slot_id:string,type:string},
     *   slots:list<array{file_name:string,mode:int,slot_id:string,type:string}>
     * }
     */
    private static function readManifest(string $manifestPath, ?Closure $trustProof): array
    {
        if ($manifestPath === '' || !str_starts_with($manifestPath, DIRECTORY_SEPARATOR)
            || str_contains($manifestPath, "\0") || str_contains($manifestPath, '/../')
            || str_contains($manifestPath, '/./')
            || ($trustProof !== null && !$trustProof($manifestPath))
            || ($trustProof === null && !self::nativeManifestTrustProof($manifestPath))) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_UNTRUSTED');
        }
        $named = @lstat($manifestPath);
        if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 07777) !== 0444 || ($named['nlink'] ?? 0) !== 1
            || ($named['size'] ?? 0) < 1 || $named['size'] > 65536) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
        }
        $handle = @fopen($manifestPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
        }
        try {
            $descriptor = fstat($handle);
            if (!is_array($descriptor)) {
                throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
            }
            self::assertSameIdentity($named, $descriptor);
            $raw = stream_get_contents($handle, 65537);
            if (!is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
            }
            self::assertSameIdentity($named, @lstat($manifestPath));
        } finally {
            @fclose($handle);
        }

        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
        }
        if (!is_array($manifest) || array_is_list($manifest)
            || !self::hasExactKeys($manifest, ['allocation', 'kind', 'schema_version', 'slots'])
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['kind'] ?? null) !== 'runtime_lifetime_lock_pool'
            || !is_array($manifest['allocation'] ?? null) || array_is_list($manifest['allocation'])
            || !is_array($manifest['slots'] ?? null) || !array_is_list($manifest['slots'])
            || $manifest['slots'] === [] || count($manifest['slots']) > 4096) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
        }

        $allocation = self::validateManifestEntry($manifest['allocation'], 'allocation');
        $slots = [];
        $slotIds = [];
        $fileNames = [$allocation['file_name'] => true];
        foreach ($manifest['slots'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
            }
            $slot = self::validateManifestEntry($entry, 'runtime_lifetime');
            if (isset($slotIds[$slot['slot_id']]) || isset($fileNames[$slot['file_name']])) {
                throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
            }
            $slotIds[$slot['slot_id']] = true;
            $fileNames[$slot['file_name']] = true;
            $slots[] = $slot;
        }

        return [
            'schema_version' => 1,
            'kind' => 'runtime_lifetime_lock_pool',
            'allocation' => $allocation,
            'slots' => $slots,
        ];
    }

    /** @param array<string,mixed> $entry @return array{file_name:string,mode:int,slot_id:string,type:string} */
    private static function validateManifestEntry(array $entry, string $expectedType): array
    {
        if (!self::hasExactKeys($entry, ['file_name', 'mode', 'slot_id', 'type'])
            || ($entry['type'] ?? null) !== $expectedType || ($entry['mode'] ?? null) !== 0444
            || !is_string($entry['file_name'] ?? null)
            || preg_match('/^[0-9A-Za-z_.-]{1,128}\.lock$/D', $entry['file_name']) !== 1
            || !is_string($entry['slot_id'] ?? null)
            || preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $entry['slot_id']) !== 1
            || ($expectedType === 'allocation' && $entry['slot_id'] !== 'allocation')
            || ($expectedType !== 'allocation' && $entry['slot_id'] === 'allocation')) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
        }

        return [
            'file_name' => $entry['file_name'],
            'mode' => $entry['mode'],
            'slot_id' => $entry['slot_id'],
            'type' => $entry['type'],
        ];
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }

    /** @param array<string|int,mixed>|false $right */
    private static function assertSameIdentity(array $left, array|false $right): void
    {
        if (!is_array($right)) {
            throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_INVALID');
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                throw new RuntimeException('UPGRADE_RUNTIME_LOCK_MANIFEST_REPLACED');
            }
        }
    }

    private static function nativeManifestTrustProof(string $path): bool
    {
        $parent = dirname($path);
        $parentStat = @lstat($parent);
        if (!is_array($parentStat) || ($parentStat['mode'] & 0170000) !== 0040000
            || ($parentStat['mode'] & 0022) !== 0) {
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
