<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use Throwable;

final class RedisUpgradeActivityTracker implements UpgradeActivityTracker, UpgradeActivityLedgerInitializer
{
    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly UpgradeActivityLedgerBackend $ledger,
        private readonly UpgradeGateRepository $gate,
        private readonly RedisServerIncarnation $incarnation,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function initializeLedger(): void
    {
        $gate = $this->gate->snapshot();
        $runId = $this->incarnation->current();
        if ($gate->redisIncarnation !== $runId || $gate->uncertain) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
        $this->ledger->initialize($gate->activityGeneration, $runId);
    }

    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        return $this->begin('http', $requestId, $owner, [
            UpgradeState::Normal, UpgradeState::Preparing, UpgradeState::ReadyToDrain,
        ]);
    }

    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        return $this->begin('callback', $requestId, $owner, [
            UpgradeState::Normal, UpgradeState::Preparing, UpgradeState::ReadyToDrain,
            UpgradeState::Draining, UpgradeState::Reconciling,
        ]);
    }

    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        return $this->begin('cron', $taskId, $owner, [
            UpgradeState::Normal, UpgradeState::Preparing, UpgradeState::ReadyToDrain,
        ]);
    }

    public function beginQueuePop(
        string $workerId,
        string $connectorType,
        array $queues,
        string $executionAttemptId,
        UpgradeRuntimeInstance $owner,
    ): ?UpgradeActivityLease {
        if (!$this->validName($workerId) || !$this->validName($connectorType)
            || !$this->validUuid($executionAttemptId) || $owner->role !== 'queue'
            || !array_is_list($queues) || $queues === [] || count($queues) > 100) {
            return null;
        }
        foreach ($queues as $queue) {
            if (!$this->validName($queue)) {
                return null;
            }
        }
        $gate = $this->gate->snapshot();
        if ($gate->uncertain) {
            $persisted = $this->recordUncertainty($gate, [$this->bootKey($owner)]);

            return $gate->state === UpgradeState::Normal && $persisted ? UpgradeActivityLease::untracked() : null;
        }
        if (!$this->acceptsOwner($gate, $owner)) {
            return null;
        }
        $clock = $this->clock;
        $payload = [
            'kind' => 'queue',
            'phase' => 'pop_in_progress',
            'execution_attempt_id' => $executionAttemptId,
            'worker_id' => $workerId,
            'connector_type' => $connectorType,
            'queues' => array_values($queues),
            'owner' => $owner->toArray(),
            'gate_epoch' => $gate->deploymentEpoch,
            'started_at' => $clock(),
        ];
        $entryId = 'queue:' . $executionAttemptId;
        try {
            $token = $this->ledger->begin($gate, $entryId, $payload, [
                UpgradeState::Normal, UpgradeState::Preparing, UpgradeState::ReadyToDrain, UpgradeState::Draining,
            ]);
        } catch (Throwable) {
            $persisted = $this->recordUncertainty($gate, [$this->bootKey($owner)]);

            return $gate->state === UpgradeState::Normal && $persisted ? UpgradeActivityLease::untracked() : null;
        }
        if ($token === null) {
            return null;
        }

        return $this->trackedLease(
            $entryId,
            $token,
            $executionAttemptId,
            $gate->activityGeneration,
            $gate->redisIncarnation,
        );
    }

    public function bindQueueJob(
        UpgradeActivityLease $popLease,
        string $connection,
        string $queue,
        string $jobId,
    ): UpgradeActivityLease {
        if ($popLease->untracked || !$this->validName($connection) || !$this->validName($queue) || !$this->validName($jobId)
            || $popLease->executionAttemptId === '') {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_BIND_INVALID');
        }
        try {
            $gate = $this->gate->snapshot();
            $runId = $this->incarnation->current();
            if ($gate->redisIncarnation !== $runId) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_CHANGED');
            }
            $entries = $this->ledger->snapshot($gate->activityGeneration, $runId);
        } catch (Throwable) {
            $this->recordCurrentUncertainty([]);
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
        $payload = null;
        foreach ($entries as $entry) {
            if (($entry['execution_attempt_id'] ?? null) === $popLease->executionAttemptId
                && ($entry['phase'] ?? null) === 'pop_in_progress') {
                $payload = $entry;
                break;
            }
        }
        if (!is_array($payload)) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_BIND_INVALID');
        }
        $payload['phase'] = 'bound';
        $payload['connection'] = $connection;
        $payload['queue'] = $queue;
        $payload['job_id'] = $jobId;
        $clock = $this->clock;
        $payload['bound_at'] = $clock();
        try {
            $token = $this->ledger->bind(
                $popLease->activityGeneration,
                $popLease->redisIncarnation,
                $popLease->entryId,
                $popLease->token(),
                $payload,
            );
        } catch (Throwable) {
            $this->recordCurrentUncertainty([]);
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
        if (!is_string($token)) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_BIND_CONFLICT');
        }
        $popLease->replaceToken($token);

        return $popLease;
    }

    public function snapshot(): UpgradeActivitySnapshot
    {
        $gate = $this->gate->snapshot();
        try {
            $runId = $this->incarnation->current();
            if ($gate->redisIncarnation !== $runId) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_CHANGED');
            }
            $entries = $this->ledger->snapshot($gate->activityGeneration, $runId);
        } catch (Throwable) {
            $this->recordUncertainty($gate, []);

            return new UpgradeActivitySnapshot(0, 0, 0, 0, 0, true, [['code' => 'ACTIVITY_TRACKING_UNCERTAIN']]);
        }
        $counts = ['http' => 0, 'callback' => 0, 'cron' => 0, 'pop' => 0, 'queue' => 0];
        foreach ($entries as $entry) {
            $kind = $entry['kind'] ?? null;
            if ($kind === 'http' || $kind === 'callback' || $kind === 'cron') {
                $counts[$kind]++;
            } elseif ($kind === 'queue' && ($entry['phase'] ?? null) === 'pop_in_progress') {
                $counts['pop']++;
            } elseif ($kind === 'queue' && ($entry['phase'] ?? null) === 'bound') {
                $counts['queue']++;
            } else {
                $this->recordUncertainty($gate, []);

                return new UpgradeActivitySnapshot(0, 0, 0, 0, 0, true, [['code' => 'ACTIVITY_LEDGER_INVALID']]);
            }
        }

        return new UpgradeActivitySnapshot(
            $counts['http'], $counts['callback'], $counts['cron'], $counts['pop'], $counts['queue'], false,
        );
    }

    /** @param list<string> $queues */
    public function heartbeatWorker(
        string $workerId,
        string $connectorType,
        array $queues,
        UpgradeRuntimeInstance $owner,
        int $ttl,
    ): void {
        $gate = $this->gate->snapshot();
        if ($gate->uncertain) {
            $this->recordUncertainty($gate, [$this->bootKey($owner)]);
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
        if (!$this->validName($workerId) || !$this->validName($connectorType) || $ttl < 1 || $ttl > 60
            || $owner->role !== 'queue' || !$this->acceptsOwner($gate, $owner)
            || !array_is_list($queues) || count($queues) > 100) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
        }
        foreach ($queues as $queue) {
            if (!$this->validName($queue)) {
                throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
            }
        }
        $clock = $this->clock;
        $now = $clock();
        try {
            $this->ledger->heartbeatWorker($gate, $workerId, [
                'worker_id' => $workerId,
                'connector_type' => $connectorType,
                'queues' => array_values($queues),
                ...$owner->toArray(),
                'owner_key' => $owner->key(),
                'activity_generation' => $gate->activityGeneration,
                'redis_incarnation' => $gate->redisIncarnation,
                'gate_revision' => $gate->revision,
                'paused_revision' => null,
                'last_seen_at' => $now,
                'expires_at' => $now + $ttl,
            ]);
        } catch (Throwable) {
            $this->recordUncertainty($gate, [$this->bootKey($owner)]);
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
    }

    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void
    {
        if (!$this->validName($workerId) || $owner->role !== 'queue' || $revision < 0 || $ttl < 1 || $ttl > 60) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_ACK_INVALID');
        }
        $gate = $this->gate->snapshot();
        if ($gate->uncertain || $gate->revision !== $revision || $gate->state !== UpgradeState::Paused) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_ACK_INVALID');
        }
        $clock = $this->clock;
        if (!$this->acceptsOwner($gate, $owner)) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_ACK_INVALID');
        }
        $this->ledger->ackPaused($gate, $workerId, $owner, $revision, $clock() + $ttl);
    }

    /** @return list<array<string,mixed>> */
    public function liveWorkers(): array
    {
        $gate = $this->gate->snapshot();
        if ($gate->uncertain) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
        $clock = $this->clock;

        return $this->ledger->liveWorkers($gate, $clock());
    }

    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void
    {
        try {
            $gate = $this->gate->snapshot();
            $this->ledger->reconcileQueue($gate->activityGeneration, $gate->redisIncarnation, $inventory, $owners);
        } catch (Throwable) {
            $this->recordCurrentUncertainty([]);
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
    }

    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void
    {
        try {
            $gate = $this->gate->snapshot();
            $this->ledger->reconcileOrphans($gate->activityGeneration, $gate->redisIncarnation, $owners);
        } catch (Throwable) {
            $this->recordCurrentUncertainty([]);
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
    }

    private function begin(string $kind, string $id, UpgradeRuntimeInstance $owner, array $states): ?UpgradeActivityLease
    {
        if (!$this->validName($id) || ($kind === 'http' || $kind === 'callback') && $owner->role !== 'http'
            || $kind === 'cron' && $owner->role !== 'cron') {
            return null;
        }
        $gate = $this->gate->snapshot();
        if ($gate->uncertain) {
            $persisted = $this->recordUncertainty($gate, [$this->bootKey($owner)]);

            return $gate->state === UpgradeState::Normal && $persisted ? UpgradeActivityLease::untracked() : null;
        }
        if (!$this->acceptsOwner($gate, $owner)) {
            return null;
        }
        $clock = $this->clock;
        $entryId = $kind . ':' . $id;
        $payload = [
            'kind' => $kind,
            'id' => $id,
            'owner' => $owner->toArray(),
            'gate_epoch' => $gate->deploymentEpoch,
            'started_at' => $clock(),
        ];
        try {
            $token = $this->ledger->begin($gate, $entryId, $payload, $states);
        } catch (Throwable) {
            $persisted = $this->recordUncertainty($gate, [$this->bootKey($owner)]);

            return $gate->state === UpgradeState::Normal && $persisted ? UpgradeActivityLease::untracked() : null;
        }
        if ($token === null) {
            return null;
        }

        return $this->trackedLease($entryId, $token, '', $gate->activityGeneration, $gate->redisIncarnation);
    }

    private function trackedLease(
        string $entryId,
        string $token,
        string $executionAttemptId = '',
        int $activityGeneration = 0,
        string $redisIncarnation = '',
    ): UpgradeActivityLease
    {
        return new UpgradeActivityLease($entryId, $token, function (string $releaseEntry, string $releaseToken) use ($activityGeneration, $redisIncarnation): void {
            try {
                $this->ledger->release($activityGeneration, $redisIncarnation, $releaseEntry, $releaseToken);
            } catch (Throwable) {
                if (!$this->recordCurrentUncertainty([])) {
                    throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
                }
            }
        }, false, $executionAttemptId, $activityGeneration, $redisIncarnation);
    }

    private function acceptsOwner(UpgradeGateSnapshot $gate, UpgradeRuntimeInstance $owner): bool
    {
        return $gate->acceptsRuntime($owner->identity)
            && $owner->observedDeploymentEpoch === $gate->deploymentEpoch;
    }

    /** @param list<string> $taintedBoots */
    private function recordCurrentUncertainty(array $taintedBoots): bool
    {
        try {
            return $this->recordUncertainty($this->gate->snapshot(), $taintedBoots);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $taintedBoots */
    private function recordUncertainty(UpgradeGateSnapshot $gate, array $taintedBoots): bool
    {
        try {
            $recorded = $this->gate->recordActivityUncertainty($gate->revision, $taintedBoots);

            return $recorded->uncertain
                && ($recorded->taintedBootsOverflow || array_diff($taintedBoots, $recorded->taintedBoots) === []);
        } catch (Throwable) {
            try {
                $latest = $this->gate->snapshot();
                $missing = array_values(array_diff($taintedBoots, $latest->taintedBoots));
                if (!$latest->uncertain || $missing !== []) {
                    $latest = $this->gate->recordActivityUncertainty($latest->revision, $taintedBoots);
                }
                return $latest->uncertain
                    && ($latest->taintedBootsOverflow || array_diff($taintedBoots, $latest->taintedBoots) === []);
            } catch (Throwable) {
            }
        }

        return false;
    }

    private function bootKey(UpgradeRuntimeInstance $owner): string
    {
        return $owner->key();
    }

    private function validName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $value) === 1;
    }

    private function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
