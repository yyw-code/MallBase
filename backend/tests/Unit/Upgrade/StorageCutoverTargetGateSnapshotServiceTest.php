<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\StorageCutoverTargetGateSnapshotService;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;

final class StorageCutoverTargetGateSnapshotServiceTest extends TestCase
{
    public function testItEmitsOnlyTheFencedCandidateIdentityForTheCurrentJob(): void
    {
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d1';
        $service = new StorageCutoverTargetGateSnapshotService(new TargetGateRepositoryStub(
            $this->snapshot(UpgradeState::AwaitingDeployment, $jobId),
        ));

        self::assertSame([
            'schema_version' => 1,
            'purpose' => 'storage_cutover_php_target_snapshot',
            'job_id' => $jobId,
            'gate_state' => 'awaiting_deployment',
            'gate_revision' => 19,
            'required_runtime' => [
                'app_version' => '1.3.0',
                'deployment_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d2',
                'storage_layout_version' => 2,
                'layout_generation' => 2,
            ],
            'maintenance_fenced' => true,
        ], $service->snapshot($jobId));
    }

    public function testItRejectsUnfencedStateAnotherJobAndUncertainActivity(): void
    {
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d1';
        foreach ([
            $this->snapshot(UpgradeState::Normal, null),
            $this->snapshot(UpgradeState::AwaitingDeployment, '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d3'),
            $this->snapshot(UpgradeState::AwaitingDeployment, $jobId, true),
            $this->snapshot(UpgradeState::BackingUp, $jobId),
            $this->snapshot(UpgradeState::FailedMaintenance, $jobId),
        ] as $snapshot) {
            $service = new StorageCutoverTargetGateSnapshotService(new TargetGateRepositoryStub($snapshot));
            try {
                $service->snapshot($jobId);
                self::fail('An unfenced target snapshot was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('STORAGE_CUTOVER_TARGET_GATE_INVALID', $exception->getMessage());
            }
        }
    }

    private function snapshot(
        UpgradeState $state,
        ?string $jobId,
        bool $uncertain = false,
    ): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            state: $state,
            revision: 19,
            jobId: $jobId,
            requiredRuntimeVersion: '1.3.0',
            requiredDeploymentId: '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d2',
            requiredStorageLayoutVersion: 2,
            requiredStorageLayoutGeneration: 2,
            deploymentEpoch: 3,
            activityGeneration: 4,
            redisIncarnation: str_repeat('a', 40),
            uncertain: $uncertain,
            taintedBoots: [],
            platformSyncPending: false,
            failureCode: null,
            updatedAt: 1_783_785_600,
            uncertainRevision: $uncertain ? 18 : null,
        );
    }
}

final class TargetGateRepositoryStub implements UpgradeGateRepository
{
    public function __construct(private UpgradeGateSnapshot $snapshot)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->snapshot;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }
}
