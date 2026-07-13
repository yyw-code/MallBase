<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeActivityTracker
{
    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease;

    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease;

    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease;

    /** @param list<string> $queues */
    public function beginQueuePop(
        string $workerId,
        string $connectorType,
        array $queues,
        string $executionAttemptId,
        UpgradeRuntimeInstance $owner,
    ): ?UpgradeActivityLease;

    public function bindQueueJob(
        UpgradeActivityLease $popLease,
        string $connection,
        string $queue,
        string $jobId,
    ): UpgradeActivityLease;

    public function snapshot(): UpgradeActivitySnapshot;

    /** @param list<string> $queues */
    public function heartbeatWorker(
        string $workerId,
        string $connectorType,
        array $queues,
        UpgradeRuntimeInstance $owner,
        int $ttl,
    ): void;

    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void;

    /** @return list<array<string,mixed>> */
    public function liveWorkers(): array;

    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void;

    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void;
}
