<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;
use Throwable;

final class FileUpgradeRuntimeRegistry implements UpgradeRuntimeRegistry, UpgradeRuntimeRecordLookup
{
    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(private readonly UpgradeSharedFileStore $files, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function register(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        string $slotId,
    ): array {
        $this->validateMutableFields($queues, $gate, false, null);
        if (preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $slotId) !== 1) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }

        return $this->files->withRuntimeRegistryLock(function () use ($instance, $queues, $cronEnabled, $gate, $slotId): array {
            $existing = $this->read($instance);
            if ($existing !== null) {
                $this->assertIdentity($existing, $instance);
                if (($existing['state'] ?? null) === 'retired') {
                    throw new RuntimeException('UPGRADE_RUNTIME_RETIRED');
                }
                if (($existing['slot_id'] ?? null) !== $slotId) {
                    throw new RuntimeException('UPGRADE_RUNTIME_SLOT_CONFLICT');
                }

                return $existing;
            }
            $clock = $this->clock;
            $now = $clock();
            $identityFenced = !$instance->matchesGateSnapshot($gate)
                || ($gate->replacementBarrierRevision !== null
                    && !$instance->isCleanReplacementFor(
                        $gate,
                        $gate->revision,
                        $gate->activityGeneration,
                        $gate->redisIncarnation,
                    ));
            $record = $this->record(
                $instance,
                $queues,
                $cronEnabled,
                $gate->revision,
                $identityFenced,
                null,
                $slotId,
                $now,
                $now,
                null,
                $gate->revision,
                $gate->activityGeneration,
                $gate->redisIncarnation,
            );
            $this->write($instance, $record);

            return $record;
        });
    }

    public function heartbeat(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        bool $identityFenced,
        ?int $pausedAckRevision,
    ): array {
        $this->validateMutableFields($queues, $gate, $identityFenced, $pausedAckRevision);

        return $this->files->withRuntimeRegistryLock(function () use (
            $instance,
            $queues,
            $cronEnabled,
            $gate,
            $identityFenced,
            $pausedAckRevision,
        ): array {
            $existing = $this->read($instance);
            if ($existing === null) {
                throw new RuntimeException('UPGRADE_RUNTIME_NOT_REGISTERED');
            }
            $this->assertIdentity($existing, $instance);
            if (($existing['state'] ?? null) !== 'active') {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIRED');
            }
            if ($gate->revision < (int) $existing['observed_gate_revision']) {
                throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
            }
            $identityFenced = (bool) $existing['identity_fenced']
                || $identityFenced
                || !$instance->matchesGateSnapshot($gate)
                || (int) $existing['activity_generation'] !== $gate->activityGeneration
                || !hash_equals((string) $existing['redis_incarnation'], $gate->redisIncarnation)
                || ($gate->replacementBarrierRevision !== null
                    && !$instance->isCleanReplacementFor(
                        $gate,
                        (int) $existing['boot_registration_revision'],
                        (int) $existing['activity_generation'],
                        (string) $existing['redis_incarnation'],
                    ));
            if ($identityFenced) {
                $pausedAckRevision = null;
            }
            $clock = $this->clock;
            $record = $this->record(
                $instance,
                $queues,
                $cronEnabled,
                $gate->revision,
                $identityFenced,
                $pausedAckRevision,
                (string) $existing['slot_id'],
                (int) $existing['registered_at'],
                $clock(),
                null,
                (int) $existing['boot_registration_revision'],
                (int) $existing['activity_generation'],
                (string) $existing['redis_incarnation'],
            );
            $this->write($instance, $record);

            return $record;
        });
    }

    public function active(): array
    {
        return $this->files->withRuntimeRegistryLock(function (): array {
            $result = [];
            foreach ($this->files->listRuntimeInstances() as $fileName) {
                $document = $this->files->readRuntimeInstance($fileName);
                if ($document === null) {
                    throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
                }
                $record = $this->decode($document);
                if ($record['state'] === 'active') {
                    $result[] = $record;
                }
            }

            return $result;
        });
    }

    public function findByOwnerKey(string $ownerKey): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}:[0-9a-f-]{36}:(http|queue|cron)$/D', $ownerKey) !== 1) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }

        return $this->files->withRuntimeRegistryLock(function () use ($ownerKey): ?array {
            $matched = null;
            foreach ($this->files->listRuntimeInstances() as $fileName) {
                $document = $this->files->readRuntimeInstance($fileName);
                if ($document === null) {
                    throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
                }
                $record = $this->decode($document);
                $runtime = $this->instanceFromRecord($record);
                if ($runtime->key() !== $ownerKey) {
                    continue;
                }
                if ($matched !== null) {
                    throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
                }
                $matched = $record;
            }

            return $matched;
        });
    }

    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array
    {
        if ($retiredAt < 0 || $retiredAt > 4_102_444_800) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }

        return $this->files->withRuntimeRegistryLock(function () use ($instance, $retiredAt): array {
            $existing = $this->read($instance);
            if ($existing === null) {
                throw new RuntimeException('UPGRADE_RUNTIME_NOT_REGISTERED');
            }
            $this->assertIdentity($existing, $instance);
            if ($existing['state'] === 'retired') {
                if ($existing['retired_at'] !== $retiredAt) {
                    throw new RuntimeException('UPGRADE_RUNTIME_RETIRE_CONFLICT');
                }

                return $existing;
            }
            $existing['state'] = 'retired';
            $existing['retired_at'] = $retiredAt;
            $this->write($instance, $existing);

            return $existing;
        });
    }

    /** @param list<string> $queues */
    private function validateMutableFields(
        array $queues,
        UpgradeGateSnapshot $gate,
        bool $identityFenced,
        ?int $pausedAckRevision,
    ): void
    {
        $this->validateQueues($queues);
        if ($pausedAckRevision !== null && ($identityFenced
                || $pausedAckRevision !== $gate->revision || !$gate->state->pausesQueuePop())) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }
    }

    /** @param list<string> $queues */
    private function validateQueues(array $queues): void
    {
        if (!array_is_list($queues) || count($queues) > 100) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }
        foreach ($queues as $queue) {
            if (!is_string($queue) || preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $queue) !== 1) {
                throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function read(UpgradeRuntimeInstance $instance): ?array
    {
        try {
            $document = $this->files->readRuntimeInstance($this->fileName($instance));

            return $document === null ? null : $this->decode($document);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'UPGRADE_')) {
                throw $exception;
            }
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }
    }

    /** @param array<string,mixed> $record */
    private function write(UpgradeRuntimeInstance $instance, array $record): void
    {
        $this->files->writeRuntimeInstance($this->fileName($instance), (object) $record);
    }

    /** @return array<string,mixed> */
    private function decode(object $document): array
    {
        $value = get_object_vars($document);
        $expected = [
            'schema_version', 'state', 'runtime_instance_id', 'boot_id', 'role', 'app_version', 'deployment_id',
            'storage_layout_version', 'storage_layout_generation', 'observed_deployment_epoch',
            'boot_registration_revision', 'activity_generation', 'redis_incarnation',
            'queues', 'cron_enabled', 'observed_gate_revision', 'identity_fenced', 'paused_ack_revision',
            'slot_id', 'registered_at', 'last_seen_at', 'retired_at',
        ];
        if (array_keys($value) !== $expected || ($value['schema_version'] ?? null) !== 2
            || !in_array($value['state'] ?? null, ['active', 'retired'], true)
            || !is_string($value['runtime_instance_id'] ?? null) || !is_string($value['boot_id'] ?? null)
            || !is_string($value['role'] ?? null) || !is_string($value['app_version'] ?? null)
            || !is_string($value['deployment_id'] ?? null) || !is_int($value['storage_layout_version'] ?? null)
            || !is_int($value['storage_layout_generation'] ?? null) || !is_int($value['observed_deployment_epoch'] ?? null)
            || !is_int($value['boot_registration_revision'] ?? null) || !is_int($value['activity_generation'] ?? null)
            || !is_string($value['redis_incarnation'] ?? null)
            || !is_array($value['queues'] ?? null) || !array_is_list($value['queues'])
            || !is_bool($value['cron_enabled'] ?? null) || !is_int($value['observed_gate_revision'] ?? null)
            || !is_bool($value['identity_fenced'] ?? null)
            || (!is_null($value['paused_ack_revision'] ?? null) && !is_int($value['paused_ack_revision']))
            || !is_string($value['slot_id'] ?? null) || !is_int($value['registered_at'] ?? null)
            || !is_int($value['last_seen_at'] ?? null) || (!is_null($value['retired_at'] ?? null) && !is_int($value['retired_at']))) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }
        $instance = UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $value['runtime_instance_id'],
            'boot_id' => $value['boot_id'],
            'role' => $value['role'],
            'app_version' => $value['app_version'],
            'deployment_id' => $value['deployment_id'],
            'storage_layout_version' => $value['storage_layout_version'],
            'storage_layout_generation' => $value['storage_layout_generation'],
            'observed_deployment_epoch' => $value['observed_deployment_epoch'],
        ]);
        unset($instance);
        $this->validateQueues($value['queues']);
        if ($value['boot_registration_revision'] < 0
            || $value['activity_generation'] < 1
            || preg_match('/^[0-9a-f]{40}$/D', $value['redis_incarnation']) !== 1
            || $value['observed_gate_revision'] < $value['boot_registration_revision']
            || ($value['paused_ack_revision'] !== null
                && ($value['identity_fenced'] || $value['paused_ack_revision'] > $value['observed_gate_revision']))
            || preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $value['slot_id']) !== 1
            || $value['registered_at'] < 0 || $value['last_seen_at'] < $value['registered_at']
            || ($value['state'] === 'active' && $value['retired_at'] !== null)
            || ($value['state'] === 'retired' && $value['retired_at'] === null)) {
            throw new RuntimeException('UPGRADE_RUNTIME_RECORD_INVALID');
        }

        return $value;
    }

    /** @param array<string,mixed> $record */
    private function assertIdentity(array $record, UpgradeRuntimeInstance $instance): void
    {
        foreach ($instance->toArray() as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                throw new RuntimeException('UPGRADE_RUNTIME_IDENTITY_CONFLICT');
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function instanceFromRecord(array $record): UpgradeRuntimeInstance
    {
        return UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
            'boot_id' => $record['boot_id'] ?? null,
            'role' => $record['role'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'deployment_id' => $record['deployment_id'] ?? null,
            'storage_layout_version' => $record['storage_layout_version'] ?? null,
            'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
            'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
        ]);
    }

    /** @param list<string> $queues @return array<string,mixed> */
    private function record(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        int $observedGateRevision,
        bool $identityFenced,
        ?int $pausedAckRevision,
        string $slotId,
        int $registeredAt,
        int $lastSeenAt,
        ?int $retiredAt,
        int $bootRegistrationRevision,
        int $activityGeneration,
        string $redisIncarnation,
    ): array {
        return [
            'schema_version' => 2,
            'state' => $retiredAt === null ? 'active' : 'retired',
            ...$instance->toArray(),
            'boot_registration_revision' => $bootRegistrationRevision,
            'activity_generation' => $activityGeneration,
            'redis_incarnation' => $redisIncarnation,
            'queues' => array_values($queues),
            'cron_enabled' => $cronEnabled,
            'observed_gate_revision' => $observedGateRevision,
            'identity_fenced' => $identityFenced,
            'paused_ack_revision' => $pausedAckRevision,
            'slot_id' => $slotId,
            'registered_at' => $registeredAt,
            'last_seen_at' => $lastSeenAt,
            'retired_at' => $retiredAt,
        ];
    }

    private function fileName(UpgradeRuntimeInstance $instance): string
    {
        return $instance->runtimeInstanceId . '-' . $instance->bootId . '-' . $instance->role . '.json';
    }
}
