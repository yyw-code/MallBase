<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeGateRepository
{
    public function snapshot(): UpgradeGateSnapshot;

    public function compareAndSet(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        string $jobId,
    ): UpgradeGateSnapshot;

    public function returnToNormal(
        int $expectedRevision,
        UpgradeState $terminalState,
        string $jobId,
        bool $platformSyncPending,
    ): UpgradeGateSnapshot;

    public function advanceRuntimeFence(
        int $expectedRevision,
        UpgradeRuntimeIdentity $current,
        UpgradeRuntimeIdentity $target,
        string $jobId,
    ): UpgradeGateSnapshot;

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot;

    /** @param list<string> $taintedBoots */
    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot;

    /** @param array<string,mixed> $runtimeRecord */
    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot;

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot;

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot;

    /** @param list<array<string,mixed>> $cleanRoleRecords */
    /** @param list<string> $requiredRoles @param list<array<string,mixed>> $cleanRoleRecords */
    public function clearActivityUncertainty(
        int $expectedRevision,
        array $requiredRoles,
        array $cleanRoleRecords,
    ): UpgradeGateSnapshot;
}
