<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use Throwable;

final class UpgradeDrainCoordinator
{
    /** @var Closure():int */
    private readonly Closure $clock;

    /** @var Closure(int):void */
    private readonly Closure $sleeper;

    public function __construct(
        private readonly UpgradeGateRepository&UpgradeDrainGateRepository $gate,
        private readonly UpgradeActivityTracker $activity,
        private readonly QueueInspector $queues,
        private readonly UpgradeDrainCheckpointRepository $checkpoints,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
        private readonly int $ackTimeoutSeconds = 20,
    ) {
        if ($this->ackTimeoutSeconds < 1 || $this->ackTimeoutSeconds > 60) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CONFIG_INVALID');
        }
        $this->clock = $clock ?? static fn(): int => time();
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function begin(string $jobId, int $expectedRevision): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        $this->assertGate($snapshot, $jobId, $expectedRevision, UpgradeState::ReadyToDrain);
        $clock = $this->clock;
        $this->checkpoints->recordDrainStarted($jobId, $expectedRevision + 1, $clock());

        return $this->transition(
            $expectedRevision,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            $jobId,
        );
    }

    public function inspect(): UpgradeBlockerSnapshot
    {
        $snapshot = $this->gate->snapshot();
        $allowed = $snapshot->jobId !== null && $snapshot->state === UpgradeState::Paused
            ? $this->checkpoints->deferredJobs($snapshot->jobId)
            : [];

        return $this->inspectSnapshot($snapshot, $allowed, $snapshot->state === UpgradeState::Paused);
    }

    public function tryPause(int $expectedRevision, bool $delayedCompatible): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        if ($snapshot->jobId === null) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
        $this->assertGate($snapshot, $snapshot->jobId, $expectedRevision, UpgradeState::Draining);

        $before = $this->inspectSnapshot($snapshot, [], false);
        if ($before->activity->uncertain || $before->activity->activeTotal() !== 0
            || $before->queues->ready !== [] || $before->queues->reserved !== []
            || $before->queues->unsupported !== []) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_BLOCKED');
        }
        if ($before->queues->delayed !== [] && !$delayedCompatible) {
            throw new UpgradeStateConflict('UPGRADE_DELAYED_QUEUE_INCOMPATIBLE');
        }

        $deferred = $this->normalizeEntries($before->queues->delayed);
        $this->checkpoints->recordDeferredJobs($snapshot->jobId, $expectedRevision, $deferred);
        $paused = $this->transition(
            $expectedRevision,
            UpgradeState::Draining,
            UpgradeState::Paused,
            $snapshot->jobId,
        );
        $this->waitForWorkerAcks($paused, $deferred);

        return $paused;
    }

    public function confirmPaused(int $expectedRevision): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        if ($snapshot->jobId === null) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
        $this->assertGate($snapshot, $snapshot->jobId, $expectedRevision, UpgradeState::Paused);
        $inspection = $this->inspectSnapshot(
            $snapshot,
            $this->checkpoints->deferredJobs($snapshot->jobId),
            true,
        );
        if (!$inspection->safe) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_NOT_SAFE');
        }

        return $snapshot;
    }

    public function confirmAndEnterBackingUp(int $expectedRevision): UpgradeGateSnapshot
    {
        $paused = $this->confirmPaused($expectedRevision);
        if ($paused->jobId === null) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }

        try {
            return $this->gate->enterBackingUpAfterDrain($paused->revision, $paused->jobId);
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
    }

    public function resumeDrain(int $expectedRevision): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        if ($snapshot->jobId === null) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
        $this->assertGate($snapshot, $snapshot->jobId, $expectedRevision, UpgradeState::Paused);

        return $this->transition(
            $expectedRevision,
            UpgradeState::Paused,
            UpgradeState::Draining,
            $snapshot->jobId,
        );
    }

    /** @param list<array{connection:string,queue:string,job_id:string}> $allowedDeferred */
    private function inspectSnapshot(
        UpgradeGateSnapshot $gate,
        array $allowedDeferred,
        bool $requireWorkerAcks,
    ): UpgradeBlockerSnapshot {
        try {
            $activity = $this->activity->snapshot();
            $inventory = $this->queues->inventory();
            $missingAcks = $requireWorkerAcks ? $this->missingWorkerAcks($gate) : [];
        } catch (Throwable $exception) {
            if ($exception instanceof UpgradeStateConflict) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_DRAIN_BLOCKED');
        }

        $allowedDeferred = $this->normalizeEntries($allowedDeferred);
        $readyBlockers = $this->withoutAllowed($inventory->ready, $allowedDeferred);
        $delayedBlockers = $this->withoutAllowed($inventory->delayed, $allowedDeferred);
        $effectiveInventory = new UpgradeQueueInventory(
            $readyBlockers,
            $inventory->reserved,
            $delayedBlockers,
            $inventory->unsupported,
        );
        $safe = $gate->state === UpgradeState::Paused
            && !$activity->uncertain
            && $activity->activeTotal() === 0
            && $effectiveInventory->ready === []
            && $effectiveInventory->reserved === []
            && $effectiveInventory->delayed === []
            && $effectiveInventory->unsupported === []
            && $missingAcks === [];

        return new UpgradeBlockerSnapshot(
            $gate->state,
            $gate->revision,
            $activity,
            $effectiveInventory,
            $missingAcks,
            $allowedDeferred,
            $safe,
        );
    }

    /** @return list<string> */
    private function missingWorkerAcks(UpgradeGateSnapshot $gate): array
    {
        $missing = [];
        foreach ($this->activity->liveWorkers() as $worker) {
            if (($worker['expired'] ?? false) === true) {
                continue;
            }
            $workerId = $worker['worker_id'] ?? null;
            if (!is_string($workerId) || preg_match('~^[0-9A-Za-z_.:/-]{1,255}$~D', $workerId) !== 1) {
                throw new UpgradeStateConflict('UPGRADE_DRAIN_BLOCKED');
            }
            $ack = $worker['paused_ack'] ?? null;
            $acked = is_array($ack)
                ? ($ack['gate_revision'] ?? null) === $gate->revision
                : ($worker['paused_revision'] ?? null) === $gate->revision;
            if (!$acked) {
                $missing[] = $workerId;
            }
        }
        sort($missing, SORT_STRING);

        return array_values(array_unique($missing));
    }

    /** @param list<array{connection:string,queue:string,job_id:string}> $allowedDeferred */
    private function waitForWorkerAcks(UpgradeGateSnapshot $paused, array $allowedDeferred): void
    {
        $clock = $this->clock;
        $sleeper = $this->sleeper;
        $deadline = $clock() + $this->ackTimeoutSeconds;
        $maximumIterations = $this->ackTimeoutSeconds * 10 + 1;
        for ($iteration = 0; $iteration < $maximumIterations; $iteration++) {
            $latest = $this->gate->snapshot();
            if ($latest->revision !== $paused->revision || $latest->state !== UpgradeState::Paused
                || $latest->jobId !== $paused->jobId) {
                throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
            }
            $inspection = $this->inspectSnapshot($latest, $allowedDeferred, true);
            if ($inspection->missingWorkerAcks === []) {
                return;
            }
            if ($clock() >= $deadline) {
                return;
            }
            $sleeper(100_000);
        }
    }

    private function assertGate(
        UpgradeGateSnapshot $snapshot,
        string $jobId,
        int $expectedRevision,
        UpgradeState $expectedState,
    ): void {
        if ($snapshot->revision !== $expectedRevision || $snapshot->state !== $expectedState
            || $snapshot->jobId !== $jobId) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
    }

    private function transition(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        string $jobId,
    ): UpgradeGateSnapshot {
        try {
            return $this->gate->compareAndSet($expectedRevision, $expectedState, $nextState, $jobId);
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
    }

    /** @param list<array{connection:string,queue:string,job_id:string}> $entries
     *  @return list<array{connection:string,queue:string,job_id:string}>
     */
    private function normalizeEntries(array $entries): array
    {
        new UpgradeQueueInventory([], [], $entries);
        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$this->entryKey($entry)] = $entry;
        }
        ksort($indexed, SORT_STRING);

        return array_values($indexed);
    }

    /** @param list<array{connection:string,queue:string,job_id:string}> $entries
     *  @param list<array{connection:string,queue:string,job_id:string}> $allowed
     *  @return list<array{connection:string,queue:string,job_id:string}>
     */
    private function withoutAllowed(array $entries, array $allowed): array
    {
        $allowedKeys = [];
        foreach ($allowed as $entry) {
            $allowedKeys[$this->entryKey($entry)] = true;
        }

        return array_values(array_filter(
            $entries,
            fn(array $entry): bool => !isset($allowedKeys[$this->entryKey($entry)]),
        ));
    }

    /** @param array{connection:string,queue:string,job_id:string} $entry */
    private function entryKey(array $entry): string
    {
        return $entry['connection'] . "\0" . $entry['queue'] . "\0" . $entry['job_id'];
    }
}
