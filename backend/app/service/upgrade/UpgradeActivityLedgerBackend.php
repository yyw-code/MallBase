<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeActivityLedgerBackend
{
    public function initialize(int $generation, string $serverRunId): void;

    /** @param array<string,mixed> $payload @param list<UpgradeState> $allowedStates */
    public function begin(UpgradeGateSnapshot $gate, string $entryId, array $payload, array $allowedStates): ?string;

    /** @param array<string,mixed> $payload */
    public function bind(int $generation, string $serverRunId, string $entryId, string $token, array $payload): ?string;

    public function release(int $generation, string $serverRunId, string $entryId, string $token): void;

    /** @return list<array<string,mixed>> */
    public function snapshot(int $generation, string $serverRunId): array;

    /** @param array<string,mixed> $worker */
    public function heartbeatWorker(UpgradeGateSnapshot $gate, string $workerId, array $worker): void;

    public function ackPaused(
        UpgradeGateSnapshot $gate,
        string $workerId,
        UpgradeRuntimeInstance $owner,
        int $revision,
        int $expiresAt,
    ): void;

    /** @return list<array<string,mixed>> */
    public function liveWorkers(UpgradeGateSnapshot $gate, int $now): array;

    public function reconcileQueue(int $generation, string $serverRunId, UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void;

    public function reconcileOrphans(int $generation, string $serverRunId, UpgradeRuntimeOwnerLiveness $owners): void;
}
