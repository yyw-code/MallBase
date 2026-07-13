<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use RuntimeException;

final readonly class FileUpgradeRuntimeRetirementEvidenceStore implements UpgradeRuntimeRetirementEvidenceStore
{
    private const PHASES = [
        'observing' => 0,
        'prepared' => 1,
        'registry_retired' => 2,
        'gate_retired' => 3,
        'committed' => 4,
    ];

    public function __construct(private UpgradeSharedFileStore $files)
    {
    }

    public function observe(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool
    {
        $fingerprint = $this->staleFingerprint($runtimeRecord, $redisRecord, $now, $windowSeconds);
        $ownerKey = $this->ownerKey($runtimeRecord);

        return $this->files->withRuntimeRegistryLock(function () use (
            $ownerKey,
            $fingerprint,
            $runtimeRecord,
            $now,
            $windowSeconds,
        ): bool {
            $document = $this->read();
            $current = $document['observations'][$ownerKey] ?? null;
            if ($fingerprint === null) {
                if (is_array($current) && ($current['state'] ?? null) !== 'observing') {
                    throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_MANUAL_RECOVERY_REQUIRED');
                }
                unset($document['observations'][$ownerKey]);
                $this->write($document);

                return false;
            }
            if (!is_array($current) || ($current['fingerprint'] ?? null) !== $fingerprint) {
                if (is_array($current) && ($current['state'] ?? null) !== 'observing') {
                    throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_MANUAL_RECOVERY_REQUIRED');
                }
                $document['observations'][$ownerKey] = $this->observation(
                    'observing',
                    $fingerprint,
                    $now,
                    $now,
                    null,
                    null,
                );
                $this->bumpAndWrite($document);

                return false;
            }
            $this->assertObservation($ownerKey, $current);
            if (($current['state'] ?? null) !== 'observing') {
                if (!$this->sameRuntimeRecord((array) $current['runtime_record'], $runtimeRecord)) {
                    throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_MANUAL_RECOVERY_REQUIRED');
                }

                return true;
            }
            $current['last_checked_at'] = $now;
            $document['observations'][$ownerKey] = $current;
            $this->bumpAndWrite($document);

            return $now - $current['first_stale_at'] >= $windowSeconds;
        });
    }

    public function prepareIfUnchanged(
        array $runtimeRecord,
        ?array $redisRecord,
        int $now,
        int $windowSeconds,
    ): bool {
        $fingerprint = $this->staleFingerprint($runtimeRecord, $redisRecord, $now, $windowSeconds);
        if ($fingerprint === null) {
            return false;
        }
        $ownerKey = $this->ownerKey($runtimeRecord);

        return $this->files->withRuntimeRegistryLock(function () use (
            $ownerKey,
            $fingerprint,
            $runtimeRecord,
            $now,
            $windowSeconds,
        ): bool {
            $document = $this->read();
            $current = $document['observations'][$ownerKey] ?? null;
            if (!is_array($current) || ($current['fingerprint'] ?? null) !== $fingerprint) {
                return false;
            }
            $this->assertObservation($ownerKey, $current);
            if ($current['state'] !== 'observing') {
                if (!$this->sameRuntimeRecord((array) $current['runtime_record'], $runtimeRecord)) {
                    throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_MANUAL_RECOVERY_REQUIRED');
                }

                return true;
            }
            if ($now - $current['first_stale_at'] < $windowSeconds) {
                return false;
            }
            $document['observations'][$ownerKey] = $this->observation(
                'prepared',
                $fingerprint,
                $current['first_stale_at'],
                $now,
                $now,
                $this->normalizeRuntimeRecord($runtimeRecord),
            );
            $this->bumpAndWrite($document);

            return true;
        });
    }

    public function pending(): array
    {
        return $this->files->withRuntimeRegistryLock(function (): array {
            $document = $this->read();
            $pending = [];
            foreach ($document['observations'] as $ownerKey => $observation) {
                $this->assertObservation($ownerKey, $observation);
                if (!in_array($observation['state'], ['prepared', 'registry_retired', 'gate_retired'], true)) {
                    continue;
                }
                $pending[] = [
                    'owner_key' => $ownerKey,
                    'state' => $observation['state'],
                    'runtime_record' => $observation['runtime_record'],
                    'retired_at' => $observation['retired_at'],
                ];
            }
            usort($pending, static fn(array $left, array $right): int => $left['owner_key'] <=> $right['owner_key']);

            return $pending;
        });
    }

    public function advance(string $ownerKey, string $expectedState, string $nextState, int $now): void
    {
        if (!isset(self::PHASES[$expectedState], self::PHASES[$nextState])
            || self::PHASES[$nextState] !== self::PHASES[$expectedState] + 1
            || $expectedState === 'observing' || $now < 0 || $now > 4_102_444_800) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
        $this->assertOwnerKey($ownerKey);
        $this->files->withRuntimeRegistryLock(function () use ($ownerKey, $expectedState, $nextState, $now): void {
            $document = $this->read();
            $current = $document['observations'][$ownerKey] ?? null;
            if (!is_array($current)) {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
            }
            $this->assertObservation($ownerKey, $current);
            $currentPhase = self::PHASES[$current['state']];
            if ($currentPhase >= self::PHASES[$nextState]) {
                return;
            }
            if ($current['state'] !== $expectedState) {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
            }
            $current['state'] = $nextState;
            $current['last_checked_at'] = $now;
            $document['observations'][$ownerKey] = $current;
            $this->bumpAndWrite($document);
        });
    }

    public function reset(string $ownerKey): void
    {
        $this->assertOwnerKey($ownerKey);
        $this->files->withRuntimeRegistryLock(function () use ($ownerKey): void {
            $document = $this->read();
            $current = $document['observations'][$ownerKey] ?? null;
            if (is_array($current) && !in_array($current['state'] ?? null, ['observing', 'committed'], true)) {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_MANUAL_RECOVERY_REQUIRED');
            }
            if ($current !== null) {
                unset($document['observations'][$ownerKey]);
                $this->bumpAndWrite($document);
            }
        });
    }

    /** @return array{schema_version:int,revision:int,observations:array<string,array<string,mixed>>} */
    private function read(): array
    {
        $raw = $this->files->readJson('runtime_retirement_evidence');
        if ($raw === null) {
            return ['schema_version' => 2, 'revision' => 0, 'observations' => []];
        }
        $document = get_object_vars($raw);
        if (array_keys($document) !== ['schema_version', 'revision', 'observations']
            || $document['schema_version'] !== 2 || !is_int($document['revision']) || $document['revision'] < 0
            || !is_object($document['observations'])) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
        $observations = [];
        foreach (get_object_vars($document['observations']) as $ownerKey => $rawObservation) {
            if (!is_string($ownerKey) || !is_object($rawObservation)) {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
            }
            $observation = get_object_vars($rawObservation);
            if (is_object($observation['runtime_record'] ?? null)) {
                $observation['runtime_record'] = get_object_vars($observation['runtime_record']);
            }
            $this->assertObservation($ownerKey, $observation);
            $observations[$ownerKey] = $observation;
        }

        return [
            'schema_version' => 2,
            'revision' => $document['revision'],
            'observations' => $observations,
        ];
    }

    /** @param array{schema_version:int,revision:int,observations:array<string,array<string,mixed>>} $document */
    private function write(array $document): void
    {
        $document['observations'] = (object) $document['observations'];
        $this->files->writeJson('runtime_retirement_evidence', (object) $document);
    }

    /** @param array{schema_version:int,revision:int,observations:array<string,array<string,mixed>>} $document */
    private function bumpAndWrite(array $document): void
    {
        $document['revision']++;
        $this->write($document);
    }

    /** @return array<string,mixed> */
    private function observation(
        string $state,
        string $fingerprint,
        int $firstStaleAt,
        int $lastCheckedAt,
        ?int $retiredAt,
        ?array $runtimeRecord,
    ): array {
        return [
            'state' => $state,
            'fingerprint' => $fingerprint,
            'first_stale_at' => $firstStaleAt,
            'last_checked_at' => $lastCheckedAt,
            'retired_at' => $retiredAt,
            'runtime_record' => $runtimeRecord,
        ];
    }

    /** @param array<string,mixed> $observation */
    private function assertObservation(string $ownerKey, array $observation): void
    {
        $this->assertOwnerKey($ownerKey);
        if (array_keys($observation) !== [
            'state', 'fingerprint', 'first_stale_at', 'last_checked_at', 'retired_at', 'runtime_record',
        ] || !isset(self::PHASES[$observation['state'] ?? ''])
            || !is_string($observation['fingerprint'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $observation['fingerprint']) !== 1
            || !is_int($observation['first_stale_at'] ?? null) || !is_int($observation['last_checked_at'] ?? null)
            || $observation['first_stale_at'] < 0 || $observation['last_checked_at'] < $observation['first_stale_at']) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
        $observing = $observation['state'] === 'observing';
        if ($observing !== ($observation['retired_at'] === null)
            || $observing !== ($observation['runtime_record'] === null)) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
        if (!$observing) {
            if (!is_int($observation['retired_at']) || $observation['retired_at'] < 0
                || !is_array($observation['runtime_record']) || array_is_list($observation['runtime_record'])) {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
            }
            $runtime = $this->normalizeRuntimeRecord($observation['runtime_record']);
            if ($runtime !== $observation['runtime_record'] || $this->ownerKey($runtime) !== $ownerKey) {
                throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
            }
        }
    }

    /** @param array<string,mixed> $runtimeRecord @return array<string,mixed> */
    private function normalizeRuntimeRecord(array $runtimeRecord): array
    {
        $expected = [
            'schema_version', 'state', 'runtime_instance_id', 'boot_id', 'role', 'app_version', 'deployment_id',
            'storage_layout_version', 'storage_layout_generation', 'observed_deployment_epoch',
            'boot_registration_revision', 'activity_generation', 'redis_incarnation', 'queues', 'cron_enabled',
            'observed_gate_revision', 'identity_fenced', 'paused_ack_revision', 'slot_id', 'registered_at',
            'last_seen_at', 'retired_at',
        ];
        if (array_keys($runtimeRecord) !== $expected || ($runtimeRecord['schema_version'] ?? null) !== 2
            || !in_array($runtimeRecord['state'] ?? null, ['active', 'retired'], true)
            || !is_string($runtimeRecord['slot_id'] ?? null) || !is_int($runtimeRecord['last_seen_at'] ?? null)) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
        $runtime = UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $runtimeRecord['runtime_instance_id'] ?? null,
            'boot_id' => $runtimeRecord['boot_id'] ?? null,
            'role' => $runtimeRecord['role'] ?? null,
            'app_version' => $runtimeRecord['app_version'] ?? null,
            'deployment_id' => $runtimeRecord['deployment_id'] ?? null,
            'storage_layout_version' => $runtimeRecord['storage_layout_version'] ?? null,
            'storage_layout_generation' => $runtimeRecord['storage_layout_generation'] ?? null,
            'observed_deployment_epoch' => $runtimeRecord['observed_deployment_epoch'] ?? null,
        ]);
        unset($runtime);

        return $runtimeRecord;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameRuntimeRecord(array $left, array $right): bool
    {
        $left = $this->normalizeRuntimeRecord($left);
        $right = $this->normalizeRuntimeRecord($right);
        foreach (['state', 'retired_at'] as $mutable) {
            unset($left[$mutable], $right[$mutable]);
        }

        return $left === $right;
    }

    /** @param array<string,mixed> $runtimeRecord @param array<string,mixed>|null $redisRecord */
    private function staleFingerprint(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): ?string
    {
        if ($now < 0 || $windowSeconds < 1 || $windowSeconds > 300
            || !is_int($runtimeRecord['last_seen_at'] ?? null)
            || $runtimeRecord['last_seen_at'] + $windowSeconds >= $now) {
            return null;
        }
        if ($redisRecord !== null && (!is_int($redisRecord['last_seen_at'] ?? null)
                || !is_int($redisRecord['expires_at'] ?? null)
                || $redisRecord['expires_at'] >= $now)) {
            return null;
        }
        $material = [
            'owner_key' => $this->ownerKey($runtimeRecord),
            'file_last_seen_at' => $runtimeRecord['last_seen_at'],
            'file_observed_gate_revision' => $runtimeRecord['observed_gate_revision'] ?? null,
            'redis_last_seen_at' => $redisRecord['last_seen_at'] ?? null,
            'redis_heartbeat_seq' => $redisRecord['heartbeat_seq'] ?? null,
            'redis_incarnation' => $redisRecord['redis_incarnation'] ?? null,
        ];
        try {
            return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
    }

    /** @param array<string,mixed> $record */
    private function ownerKey(array $record): string
    {
        $runtime = UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
            'boot_id' => $record['boot_id'] ?? null,
            'role' => $record['role'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'deployment_id' => $record['deployment_id'] ?? null,
            'storage_layout_version' => $record['storage_layout_version'] ?? null,
            'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
            'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
        ]);

        return $runtime->key();
    }

    private function assertOwnerKey(string $ownerKey): void
    {
        if (preg_match('/^[0-9a-f-]{36}:[0-9a-f-]{36}:(http|queue|cron)$/D', $ownerKey) !== 1) {
            throw new RuntimeException('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
    }
}
