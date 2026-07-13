<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use JsonException;
use Throwable;

final class RedisUpgradeGateRepository implements UpgradeGateRepository, UpgradeDrainGateRepository
{
    private const MIRROR_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current == ARGV[2] then
    return {1, current}
end
local revision = -1
if current then
    local ok, value = pcall(cjson.decode, current)
    if not ok or type(value) ~= 'table' or type(value.revision) ~= 'number' then
        if tonumber(ARGV[1]) == -2 then
            redis.call('SET', KEYS[1], ARGV[2])
            return {1, ARGV[2]}
        end
        return {-1, current}
    end
    revision = value.revision
end
if revision ~= tonumber(ARGV[1]) then
    return {0, current or ''}
end
redis.call('SET', KEYS[1], ARGV[2])
return {1, ARGV[2]}
LUA;

    /** @var Closure():int */
    private readonly Closure $clock;

    /** @var Closure():int */
    private readonly Closure $generationSource;

    private readonly ?RedisServerIncarnation $incarnation;

    public function __construct(
        private readonly object $redis,
        private readonly FileUpgradeCheckpointRepository $checkpoints,
        private readonly string $namespace,
        ?Closure $clock = null,
        ?RedisServerIncarnation $incarnation = null,
        ?Closure $generationSource = null,
    ) {
        if (preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $this->namespace) !== 1
            || (!$this->redis instanceof UpgradeRedisConnectionFactory
                && (!method_exists($this->redis, 'get') || !method_exists($this->redis, 'eval')))) {
            throw new UpgradeStateConflict('UPGRADE_GATE_CONFIG_INVALID');
        }
        $this->clock = $clock ?? static fn(): int => time();
        $this->incarnation = $this->redis instanceof UpgradeRedisConnectionFactory
            ? null
            : ($incarnation ?? new RedisServerIncarnation($this->redis));
        $this->generationSource = $generationSource ?? static fn(): int => random_int(1, 2_147_483_647);
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->checkpoints->withLock(function (): UpgradeGateSnapshot {
            [$snapshot, $redisRevision] = $this->loadReconciledLocked();
            $this->mirror($snapshot, $redisRevision);

            return $snapshot;
        });
    }

    public function compareAndSet(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        string $jobId,
    ): UpgradeGateSnapshot {
        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $expectedState, $nextState, $jobId): UpgradeGateSnapshot {
            $this->assertExpected($current, $expectedRevision, $expectedState, $jobId);
            if (!$expectedState->permitsGenericTransitionTo($nextState)
                || $current->uncertain
                || ($expectedState === UpgradeState::Normal && $current->platformSyncPending)) {
                throw new UpgradeStateConflict();
            }
            $clock = $this->clock;

            return $this->copy(
                $current,
                state: $nextState,
                revision: $current->revision + 1,
                jobId: $current->jobId ?? $jobId,
                failureCode: str_starts_with($nextState->value, 'failed_') ? strtoupper($nextState->value) : null,
                updatedAt: $clock(),
            );
        });
    }

    public function returnToNormal(
        int $expectedRevision,
        UpgradeState $terminalState,
        string $jobId,
        bool $platformSyncPending,
    ): UpgradeGateSnapshot {
        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $terminalState, $jobId, $platformSyncPending): UpgradeGateSnapshot {
            $this->assertExpected($current, $expectedRevision, $terminalState, $jobId);
            if (!in_array($terminalState, [UpgradeState::Completed, UpgradeState::Cancelled, UpgradeState::FailedPreApply], true)) {
                throw new UpgradeStateConflict();
            }
            $clock = $this->clock;

            return $this->copy(
                $current,
                state: UpgradeState::Normal,
                revision: $current->revision + 1,
                jobId: null,
                platformSyncPending: $platformSyncPending,
                failureCode: null,
                updatedAt: $clock(),
            );
        });
    }

    public function enterBackingUpAfterDrain(int $expectedRevision, string $jobId): UpgradeGateSnapshot
    {
        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $jobId): UpgradeGateSnapshot {
            $this->assertExpected($current, $expectedRevision, UpgradeState::Paused, $jobId);
            if ($current->uncertain) {
                throw new UpgradeStateConflict();
            }
            $clock = $this->clock;

            return $this->copy(
                $current,
                state: UpgradeState::BackingUp,
                revision: $current->revision + 1,
                updatedAt: $clock(),
            );
        });
    }

    public function advanceRuntimeFence(
        int $expectedRevision,
        UpgradeRuntimeIdentity $current,
        UpgradeRuntimeIdentity $target,
        string $jobId,
    ): UpgradeGateSnapshot {
        return $this->mutate(function (UpgradeGateSnapshot $snapshot) use ($expectedRevision, $current, $target, $jobId): UpgradeGateSnapshot {
            if ($snapshot->revision !== $expectedRevision || !$snapshot->acceptsRuntime($current)
                || $snapshot->jobId !== $jobId
                || !in_array($snapshot->state, [UpgradeState::Applying, UpgradeState::AwaitingDeployment, UpgradeState::FailedMaintenance], true)) {
                throw new UpgradeStateConflict();
            }
            $clock = $this->clock;

            return new UpgradeGateSnapshot(
                $snapshot->state,
                $snapshot->revision + 1,
                $snapshot->jobId,
                $target->version,
                $target->deploymentId,
                $target->storageLayoutVersion,
                $target->storageLayoutGeneration,
                $snapshot->deploymentEpoch + 1,
                $snapshot->activityGeneration,
                $snapshot->redisIncarnation,
                $snapshot->uncertain,
                $snapshot->taintedBoots,
                $snapshot->platformSyncPending,
                $snapshot->failureCode,
                $clock(),
                $snapshot->uncertainRevision,
                $snapshot->replacementBarrierRevision,
                $snapshot->taintedBootsOverflow,
            );
        });
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $jobId, $failureCode): UpgradeGateSnapshot {
            if ($current->revision !== $expectedRevision || $current->jobId !== $jobId) {
                throw new UpgradeStateConflict();
            }
            $clock = $this->clock;

            return $this->copy(
                $current,
                state: UpgradeState::FailedMaintenance,
                revision: $current->revision + 1,
                uncertain: true,
                uncertainRevision: $current->uncertainRevision ?? $current->revision + 1,
                failureCode: $failureCode,
                updatedAt: $clock(),
            );
        });
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        if (!array_is_list($taintedBoots) || count($taintedBoots) > 10_000) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_UNCERTAINTY_INVALID');
        }
        foreach ($taintedBoots as $boot) {
            if (!is_string($boot) || preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $boot) !== 1) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_UNCERTAINTY_INVALID');
            }
        }

        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $taintedBoots): UpgradeGateSnapshot {
            if ($current->revision !== $expectedRevision) {
                throw new UpgradeStateConflict();
            }
            $all = array_values(array_unique([...$current->taintedBoots, ...$taintedBoots]));
            sort($all, SORT_STRING);
            $overflow = $current->taintedBootsOverflow || count($all) > 100;
            $merged = array_slice($all, 0, 100);
            sort($merged, SORT_STRING);
            if ($current->uncertain && $merged === $current->taintedBoots
                && $overflow === $current->taintedBootsOverflow) {
                return $current;
            }
            $clock = $this->clock;

            return $this->copy(
                $current,
                state: $current->state === UpgradeState::Normal ? UpgradeState::Normal : UpgradeState::FailedMaintenance,
                revision: $current->revision + 1,
                uncertain: true,
                taintedBoots: $merged,
                uncertainRevision: $current->uncertainRevision ?? $current->revision + 1,
                taintedBootsOverflow: $overflow,
                failureCode: 'ACTIVITY_TRACKING_UNCERTAIN',
                updatedAt: $clock(),
            );
        });
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        $runtime = $this->runtimeFromRecord($runtimeRecord);
        $bootRegistrationRevision = $runtimeRecord['boot_registration_revision'] ?? null;
        $activityGeneration = $runtimeRecord['activity_generation'] ?? null;
        $redisIncarnation = $runtimeRecord['redis_incarnation'] ?? null;
        if (($runtimeRecord['schema_version'] ?? null) !== 2 || ($runtimeRecord['state'] ?? null) !== 'active'
            || !is_int($bootRegistrationRevision) || !is_int($activityGeneration) || !is_string($redisIncarnation)
            || !is_bool($runtimeRecord['identity_fenced'] ?? null)) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_REGISTRATION_INVALID');
        }

        return $this->mutate(function (UpgradeGateSnapshot $current) use (
            $expectedRevision,
            $runtimeRecord,
            $runtime,
            $bootRegistrationRevision,
            $activityGeneration,
            $redisIncarnation,
        ): UpgradeGateSnapshot {
            if ($current->revision !== $expectedRevision) {
                throw new UpgradeStateConflict();
            }
            if (!$current->uncertain) {
                return $current;
            }
            $cleanReplacement = ($runtimeRecord['identity_fenced'] ?? true) === false
                && $runtime->isCleanReplacementFor(
                    $current,
                    $bootRegistrationRevision,
                    $activityGeneration,
                    $redisIncarnation,
                );
            if ($cleanReplacement || in_array($runtime->key(), $current->taintedBoots, true)) {
                return $current;
            }

            $all = [...$current->taintedBoots, $runtime->key()];
            sort($all, SORT_STRING);
            $overflow = $current->taintedBootsOverflow || count($all) > 100;
            $clock = $this->clock;

            return $this->copy(
                $current,
                revision: $current->revision + 1,
                taintedBoots: array_slice(array_values(array_unique($all)), 0, 100),
                taintedBootsOverflow: $overflow,
                updatedAt: $clock(),
            );
        });
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $redisIncarnation) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
        }

        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $redisIncarnation): UpgradeGateSnapshot {
            if ($current->revision !== $expectedRevision || $current->state !== UpgradeState::Normal
                || !$current->uncertain || $current->replacementBarrierRevision !== null) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
            }
            $clock = $this->clock;
            $nextRevision = $current->revision + 1;

            return $this->copy(
                $current,
                revision: $nextRevision,
                activityGeneration: $this->nextActivityGeneration($current->activityGeneration),
                redisIncarnation: $redisIncarnation,
                replacementBarrierRevision: $nextRevision,
                updatedAt: $clock(),
            );
        });
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        if (preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $ownerKey) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
        }

        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $ownerKey): UpgradeGateSnapshot {
            if ($current->revision !== $expectedRevision || !$current->uncertain
                || $current->replacementBarrierRevision === null) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
            }
            if (!in_array($ownerKey, $current->taintedBoots, true)) {
                return $current;
            }
            $remaining = array_values(array_filter(
                $current->taintedBoots,
                static fn(string $value): bool => $value !== $ownerKey,
            ));
            $clock = $this->clock;

            return $this->copy(
                $current,
                revision: $current->revision + 1,
                taintedBoots: $remaining,
                updatedAt: $clock(),
            );
        });
    }

    public function clearActivityUncertainty(
        int $expectedRevision,
        array $requiredRoles,
        array $cleanRoleRecords,
    ): UpgradeGateSnapshot
    {
        return $this->mutate(function (UpgradeGateSnapshot $current) use ($expectedRevision, $requiredRoles, $cleanRoleRecords): UpgradeGateSnapshot {
            if ($current->revision !== $expectedRevision || $current->state !== UpgradeState::Normal
                || !$current->uncertain || $current->replacementBarrierRevision === null
                || $current->taintedBoots !== [] || $current->taintedBootsOverflow
                || !$this->validCleanRoleRecords($current, $requiredRoles, $cleanRoleRecords)) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
            }
            $clock = $this->clock;

            return $this->copy(
                $current,
                revision: $current->revision + 1,
                uncertain: false,
                uncertainRevision: null,
                replacementBarrierRevision: null,
                failureCode: null,
                updatedAt: $clock(),
            );
        });
    }

    /** @param Closure(UpgradeGateSnapshot):UpgradeGateSnapshot $callback */
    private function mutate(Closure $callback): UpgradeGateSnapshot
    {
        return $this->checkpoints->withLock(function () use ($callback): UpgradeGateSnapshot {
            [$current, $redisRevision] = $this->loadReconciledLocked();
            $this->mirror($current, $redisRevision);
            $next = $callback($current);
            $this->checkpoints->write($next);
            $this->mirror($next, $current->revision);

            return $next;
        });
    }

    /** @return array{UpgradeGateSnapshot,int} */
    private function loadReconciledLocked(): array
    {
        $existing = $this->checkpoints->readActive();
        try {
            if ($this->redis instanceof UpgradeRedisConnectionFactory) {
                [$liveIncarnation, $redisSnapshot] = $this->readFactoryRedisState();
            } else {
                $liveIncarnation = $this->currentIncarnation();
                $redisSnapshot = $this->readRedisSnapshot($this->redis);
            }
        } catch (UpgradeStateConflict $exception) {
            if ($existing === null || $exception->getMessage() !== 'UPGRADE_REDIS_GATE_INVALID') {
                throw $exception;
            }
            $snapshot = $this->recoverInvalidRedisGate($existing);
            $this->checkpoints->write($snapshot);

            return [$snapshot, -2];
        }
        if ($existing === null) {
            if ($redisSnapshot !== null) {
                throw new UpgradeStateConflict('UPGRADE_CHECKPOINT_MISSING');
            }
            $snapshot = $this->checkpoints->initialize($liveIncarnation);
            $this->checkpoints->write($snapshot);

            return [$snapshot, -1];
        }

        $snapshot = $existing;
        if ($redisSnapshot === null || $redisSnapshot->redisIncarnation !== $liveIncarnation) {
            $snapshot = $this->recoverAfterRedisLoss($snapshot, $liveIncarnation);
        } elseif (!$this->sameSnapshot($snapshot, $redisSnapshot)
            && $redisSnapshot->revision >= $snapshot->revision) {
            $revision = max($snapshot->revision, $redisSnapshot->revision) + 1;
            $snapshot = $snapshot->jobId === null
                ? $this->copy(
                    $snapshot,
                    revision: $revision,
                    uncertain: true,
                    uncertainRevision: $snapshot->uncertainRevision ?? $revision,
                    failureCode: 'REDIS_GATE_DIVERGED',
                    updatedAt: ($this->clock)(),
                )
                : $this->failedMaintenance($snapshot, $revision, 'REDIS_GATE_DIVERGED');
            $this->checkpoints->write($snapshot);
        }

        return [$snapshot, $redisSnapshot?->revision ?? -1];
    }

    private function recoverAfterRedisLoss(UpgradeGateSnapshot $snapshot, string $liveIncarnation): UpgradeGateSnapshot
    {
        if ($snapshot->uncertain && hash_equals($snapshot->redisIncarnation, $liveIncarnation)) {
            return $snapshot;
        }
        $clock = $this->clock;
        if ($snapshot->state->hasIrreversibleSideEffects()) {
            $recovered = $this->failedMaintenance($snapshot, $snapshot->revision + 1, 'REDIS_STATE_LOST');
        } else {
            $recovered = $this->copy(
                $snapshot,
                revision: $snapshot->revision + 1,
                redisIncarnation: $liveIncarnation,
                uncertain: true,
                uncertainRevision: $snapshot->uncertainRevision ?? $snapshot->revision + 1,
                failureCode: 'REDIS_STATE_LOST',
                updatedAt: $clock(),
            );
        }
        if (!hash_equals($recovered->redisIncarnation, $liveIncarnation)) {
            $recovered = $this->copy(
                $recovered,
                redisIncarnation: $liveIncarnation,
                updatedAt: $clock(),
            );
        }
        $this->checkpoints->write($recovered);

        return $recovered;
    }

    private function recoverInvalidRedisGate(UpgradeGateSnapshot $snapshot): UpgradeGateSnapshot
    {
        if ($snapshot->uncertain) {
            return $snapshot;
        }
        $revision = $snapshot->revision + 1;
        if ($snapshot->state->hasIrreversibleSideEffects()) {
            return $this->failedMaintenance($snapshot, $revision, 'REDIS_GATE_INVALID');
        }

        return $this->copy(
            $snapshot,
            revision: $revision,
            uncertain: true,
            uncertainRevision: $revision,
            failureCode: 'REDIS_GATE_INVALID',
            updatedAt: ($this->clock)(),
        );
    }

    private function failedMaintenance(UpgradeGateSnapshot $current, int $revision, string $failureCode): UpgradeGateSnapshot
    {
        $clock = $this->clock;

        return $this->copy(
            $current,
            state: UpgradeState::FailedMaintenance,
            revision: $revision,
            uncertain: true,
            uncertainRevision: $current->uncertainRevision ?? $revision,
            failureCode: $failureCode,
            updatedAt: $clock(),
        );
    }

    private function assertExpected(UpgradeGateSnapshot $current, int $revision, UpgradeState $state, string $jobId): void
    {
        $expectedJob = $state === UpgradeState::Normal ? null : $jobId;
        if ($current->revision !== $revision || $current->state !== $state || $current->jobId !== $expectedJob
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new UpgradeStateConflict();
        }
    }

    /** @return array{string,?UpgradeGateSnapshot} */
    private function readFactoryRedisState(): array
    {
        $redis = $this->redis->create();
        try {
            $incarnation = new RedisServerIncarnation($redis);
            $before = $incarnation->connectionIdentity();
            $snapshot = $this->readRedisSnapshot($redis);
            $after = $incarnation->connectionIdentity();
            if (!$before->equals($after)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_CHANGED');
            }

            return [$before->runId, $snapshot];
        } finally {
            $this->closeOwned($redis, true);
        }
    }

    private function readRedisSnapshot(object $redis): ?UpgradeGateSnapshot
    {
        try {
            $raw = $redis->get($this->key());
            if ($raw === false || $raw === null) {
                return null;
            }
            if (!is_string($raw) || strlen($raw) > 65536) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_GATE_INVALID');
            }
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (!is_object($decoded)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_GATE_INVALID');
            }

            return UpgradeGateSnapshot::fromDocument($decoded);
        } catch (JsonException) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_GATE_INVALID');
        } catch (UpgradeStateConflict $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    private function mirror(UpgradeGateSnapshot $snapshot, int $expectedRevision): void
    {
        $owned = $this->redis instanceof UpgradeRedisConnectionFactory;
        $redis = $this->connection();
        try {
            $before = $owned ? (new RedisServerIncarnation($redis))->connectionIdentity() : null;
            if ($before !== null && !hash_equals($snapshot->redisIncarnation, $before->runId)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_CHANGED');
            }
            $encoded = json_encode($snapshot->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $result = $redis->eval(
                self::MIRROR_SCRIPT,
                [$this->key(), $expectedRevision, $encoded],
                1,
            );
            if (!is_array($result) || ($result[0] ?? null) !== 1 || ($result[1] ?? null) !== $encoded) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_GATE_CONFLICT');
            }
            if ($before !== null && !$before->equals((new RedisServerIncarnation($redis))->connectionIdentity())) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_CHANGED');
            }
        } catch (JsonException) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_GATE_INVALID');
        } catch (UpgradeStateConflict $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_GATE_UNAVAILABLE');
        } finally {
            $this->closeOwned($redis, $owned);
        }
    }

    private function sameSnapshot(UpgradeGateSnapshot $left, UpgradeGateSnapshot $right): bool
    {
        return $left->toDocument() == $right->toDocument();
    }

    private function key(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:gate';
    }

    private function currentIncarnation(): string
    {
        return ($this->incarnation ?? new RedisServerIncarnation($this->connection()))->current();
    }

    private function connection(): object
    {
        return $this->redis instanceof UpgradeRedisConnectionFactory
            ? $this->redis->create()
            : $this->redis;
    }

    private function closeOwned(object $redis, bool $owned): void
    {
        if (!$owned || !method_exists($redis, 'close')) {
            return;
        }
        try {
            $redis->close();
        } catch (Throwable) {
        }
    }

    /** @param list<string> $requiredRoles @param list<array<string,mixed>> $records */
    private function validCleanRoleRecords(UpgradeGateSnapshot $gate, array $requiredRoles, array $records): bool
    {
        if (!array_is_list($requiredRoles) || $requiredRoles === [] || count($requiredRoles) > 3
            || !array_is_list($records) || $records === [] || count($records) > 1000) {
            return false;
        }
        $requiredRoles = array_values(array_unique($requiredRoles));
        sort($requiredRoles, SORT_STRING);
        foreach ($requiredRoles as $role) {
            if (!is_string($role) || !in_array($role, ['http', 'queue', 'cron'], true)) {
                return false;
            }
        }
        $expectedKeys = [
            'schema_version', 'state', 'runtime_instance_id', 'boot_id', 'role', 'app_version', 'deployment_id',
            'storage_layout_version', 'storage_layout_generation', 'observed_deployment_epoch',
            'boot_registration_revision', 'activity_generation', 'redis_incarnation',
            'queues', 'cron_enabled', 'observed_gate_revision', 'identity_fenced', 'paused_ack_revision',
            'slot_id', 'registered_at', 'last_seen_at', 'retired_at',
        ];
        $roles = [];
        foreach ($records as $record) {
            if (!is_array($record) || array_keys($record) !== $expectedKeys
                || ($record['schema_version'] ?? null) !== 2 || ($record['state'] ?? null) !== 'active'
                || !is_string($record['runtime_instance_id'] ?? null)
                || !is_string($record['boot_id'] ?? null)
                || !is_string($record['role'] ?? null)
                || !in_array($record['role'], ['http', 'queue', 'cron'], true)
                || ($record['app_version'] ?? null) !== $gate->requiredRuntimeVersion
                || ($record['deployment_id'] ?? null) !== $gate->requiredDeploymentId
                || ($record['storage_layout_version'] ?? null) !== $gate->requiredStorageLayoutVersion
                || ($record['storage_layout_generation'] ?? null) !== $gate->requiredStorageLayoutGeneration
                || ($record['observed_deployment_epoch'] ?? null) !== $gate->deploymentEpoch
                || !is_int($record['boot_registration_revision'] ?? null)
                || $record['boot_registration_revision'] < (int) $gate->replacementBarrierRevision
                || ($record['activity_generation'] ?? null) !== $gate->activityGeneration
                || ($record['redis_incarnation'] ?? null) !== $gate->redisIncarnation
                || !is_int($record['observed_gate_revision'] ?? null)
                || $record['observed_gate_revision'] < $record['boot_registration_revision']
                || ($record['identity_fenced'] ?? null) !== false
                || !is_array($record['queues'] ?? null) || !array_is_list($record['queues'])
                || !is_bool($record['cron_enabled'] ?? null)
                || ($record['paused_ack_revision'] ?? null) !== null
                || !is_string($record['slot_id'] ?? null)
                || !is_int($record['registered_at'] ?? null) || !is_int($record['last_seen_at'] ?? null)
                || $record['last_seen_at'] < $record['registered_at']
                || ($record['retired_at'] ?? null) !== null) {
                return false;
            }
            try {
                $runtime = UpgradeRuntimeInstance::fromArray([
                    'runtime_instance_id' => $record['runtime_instance_id'],
                    'boot_id' => $record['boot_id'],
                    'role' => $record['role'],
                    'app_version' => $record['app_version'],
                    'deployment_id' => $record['deployment_id'],
                    'storage_layout_version' => $record['storage_layout_version'],
                    'storage_layout_generation' => $record['storage_layout_generation'],
                    'observed_deployment_epoch' => $record['observed_deployment_epoch'],
                ]);
                if (!$runtime->matchesGateSnapshot($gate)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
            $roles[$record['role']] = true;
        }

        foreach ($requiredRoles as $role) {
            if (!isset($roles[$role])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $record */
    private function runtimeFromRecord(array $record): UpgradeRuntimeInstance
    {
        try {
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
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_REGISTRATION_INVALID');
        }
    }

    private function copy(
        UpgradeGateSnapshot $value,
        ?UpgradeState $state = null,
        ?int $revision = null,
        string|null|false $jobId = false,
        ?int $activityGeneration = null,
        ?string $redisIncarnation = null,
        ?bool $uncertain = null,
        ?array $taintedBoots = null,
        int|null|false $uncertainRevision = false,
        int|null|false $replacementBarrierRevision = false,
        ?bool $taintedBootsOverflow = null,
        ?bool $platformSyncPending = null,
        string|null|false $failureCode = false,
        ?int $updatedAt = null,
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            $state ?? $value->state,
            $revision ?? $value->revision,
            $jobId === false ? $value->jobId : $jobId,
            $value->requiredRuntimeVersion,
            $value->requiredDeploymentId,
            $value->requiredStorageLayoutVersion,
            $value->requiredStorageLayoutGeneration,
            $value->deploymentEpoch,
            $activityGeneration ?? $value->activityGeneration,
            $redisIncarnation ?? $value->redisIncarnation,
            $uncertain ?? $value->uncertain,
            $taintedBoots ?? $value->taintedBoots,
            $platformSyncPending ?? $value->platformSyncPending,
            $failureCode === false ? $value->failureCode : $failureCode,
            $updatedAt ?? $value->updatedAt,
            $uncertainRevision === false ? $value->uncertainRevision : $uncertainRevision,
            $replacementBarrierRevision === false ? $value->replacementBarrierRevision : $replacementBarrierRevision,
            $taintedBootsOverflow ?? $value->taintedBootsOverflow,
        );
    }

    private function nextActivityGeneration(int $current): int
    {
        $source = $this->generationSource;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $generation = $source();
            if (is_int($generation) && $generation >= 1 && $generation <= 2_147_483_647
                && $generation !== $current) {
                return $generation;
            }
        }

        throw new UpgradeStateConflict('UPGRADE_ACTIVITY_GENERATION_UNAVAILABLE');
    }
}
