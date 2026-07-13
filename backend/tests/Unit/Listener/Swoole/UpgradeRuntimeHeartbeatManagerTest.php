<?php

declare(strict_types=1);

namespace Tests\Unit\Listener\Swoole;

use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeHeartbeatManager;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;

final class UpgradeRuntimeHeartbeatManagerTest extends TestCase
{
    public function testTickPersistsLocalIdentityAndReportsFenceWithoutInventingExpectedValues(): void
    {
        $registry = new TestRuntimeRegistry();
        $gate = new TestRuntimeGate($this->snapshot());
        $manager = new UpgradeRuntimeHeartbeatManager($registry, $gate);
        $target = $this->owner(self::DEPLOYMENT_ID, 4);

        $manager->tick($target, ['default'], true);
        self::assertFalse($registry->last['identity_fenced']);
        self::assertSame(self::DEPLOYMENT_ID, $registry->last['deployment_id']);

        $old = $this->owner(self::OLD_DEPLOYMENT_ID, 3);
        $manager->tick($old, ['default'], true);
        self::assertTrue($registry->last['identity_fenced']);
        self::assertSame(self::OLD_DEPLOYMENT_ID, $registry->last['deployment_id']);
        self::assertSame(3, $registry->last['storage_layout_generation']);
    }

    public function testWorkerStartedWhilePausedRegistersBeforeAcknowledgingRevision(): void
    {
        $registry = new TestRuntimeRegistry();
        $gate = new TestRuntimeGate($this->snapshot(UpgradeState::Paused, 9));
        $manager = new UpgradeRuntimeHeartbeatManager($registry, $gate);

        $manager->tick($this->owner(self::DEPLOYMENT_ID, 4), ['default'], false);

        self::assertSame(['heartbeat', 'ack'], $registry->operations);
        self::assertSame(9, $registry->last['paused_ack_revision']);
    }

    private function owner(string $deploymentId, int $generation): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'queue',
            new UpgradeRuntimeIdentity('1.2.0', $deploymentId, 1, $generation),
            2,
        );
    }

    private function snapshot(UpgradeState $state = UpgradeState::Normal, int $revision = 1): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            7,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1000,
        );
    }

    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const OLD_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class TestRuntimeRegistry implements UpgradeRuntimeRegistry
{
    /** @var list<string> */
    public array $operations = [];
    /** @var array<string,mixed> */
    public array $last = [];

    public function register(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        string $slotId,
    ): array
    {
        throw new \LogicException('not used');
    }

    public function heartbeat(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        bool $identityFenced,
        ?int $pausedAckRevision,
    ): array {
        $this->operations[] = 'heartbeat';
        $this->last = [
            ...$instance->toArray(),
            'observed_gate_revision' => $gate->revision,
            'activity_generation' => $gate->activityGeneration,
            'redis_incarnation' => $gate->redisIncarnation,
            'identity_fenced' => $identityFenced,
            'paused_ack_revision' => $pausedAckRevision,
        ];
        if ($pausedAckRevision !== null) {
            $this->operations[] = 'ack';
        }

        return $this->last;
    }

    public function active(): array
    {
        return [];
    }

    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array
    {
        throw new \LogicException('not used');
    }
}

final readonly class TestRuntimeGate implements UpgradeGateRepository
{
    public function __construct(private UpgradeGateSnapshot $value)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->value;
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
