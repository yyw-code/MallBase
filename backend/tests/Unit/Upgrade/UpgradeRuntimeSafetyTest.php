<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeActivityBootstrapper;
use app\service\upgrade\UpgradeActivityLedgerInitializer;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeFailureLatch;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeStateConflict;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeRuntimeSafetyTest extends TestCase
{
    public function testActivityBootstrapFailureIsPersistedBeforeCommerceContinues(): void
    {
        $gate = new RuntimeSafetyGate($this->snapshot());
        $initializer = new class implements UpgradeActivityLedgerInitializer {
            public function initializeLedger(): void
            {
                throw new RuntimeException('redis unavailable');
            }
        };

        $ready = (new UpgradeActivityBootstrapper($initializer, $gate))->initialize();

        self::assertFalse($ready);
        self::assertTrue($gate->current->uncertain);
        self::assertSame('ACTIVITY_TRACKING_UNCERTAIN', $gate->current->failureCode);
        self::assertSame(1, $gate->recordCalls);
    }

    public function testActivityBootstrapCannotFailOpenWhenTheDurableLatchIsUnavailable(): void
    {
        $gate = new RuntimeSafetyGate($this->snapshot(), failRecord: true);
        $initializer = new class implements UpgradeActivityLedgerInitializer {
            public function initializeLedger(): void
            {
                throw new RuntimeException('redis unavailable');
            }
        };

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_ACTIVITY_BOOTSTRAP_UNSAFE');
        (new UpgradeActivityBootstrapper($initializer, $gate))->initialize();
    }

    public function testRuntimeFailureLatchPersistsExactRoleOwnersAcrossRevisionRace(): void
    {
        $gate = new RuntimeSafetyGate($this->snapshot(), conflictOnce: true);
        $runtime = new RuntimeSafetyContext();
        $latch = new UpgradeRuntimeFailureLatch(
            $gate,
            $runtime,
            new RuntimeSafetyRegistry([]),
        );

        $snapshot = $latch->taintRoles(['cron', 'http', 'http']);

        $expected = [$runtime->owner('cron')->key(), $runtime->owner('http')->key()];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $snapshot->taintedBoots);
        self::assertTrue($snapshot->uncertain);
        self::assertSame(2, $gate->recordCalls);
    }

    public function testRuntimeFailureLatchRefusesToContinueWithoutDurableEvidence(): void
    {
        $gate = new RuntimeSafetyGate($this->snapshot(), failRecord: true);
        $latch = new UpgradeRuntimeFailureLatch(
            $gate,
            new RuntimeSafetyContext(),
            new RuntimeSafetyRegistry([]),
        );

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_RUNTIME_FAILURE_LATCH_UNAVAILABLE');
        $latch->taintRoles(['queue']);
    }

    private function snapshot(): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            UpgradeState::Normal,
            5,
            null,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            11,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1_000,
        );
    }

    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class RuntimeSafetyGate implements UpgradeGateRepository
{
    public int $recordCalls = 0;

    public function __construct(
        public UpgradeGateSnapshot $current,
        private readonly bool $failRecord = false,
        private bool $conflictOnce = false,
    ) {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->current;
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        $this->recordCalls++;
        if ($this->failRecord) {
            throw new RuntimeException('durable latch unavailable');
        }
        if ($this->conflictOnce) {
            $this->conflictOnce = false;
            $this->current = $this->copy($this->current, $this->current->revision + 1, []);
            throw new UpgradeStateConflict('revision race');
        }
        if ($expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict('revision race');
        }
        $owners = array_values(array_unique([...$this->current->taintedBoots, ...$taintedBoots]));
        sort($owners, SORT_STRING);
        $this->current = $this->copy($this->current, $this->current->revision + 1, $owners);

        return $this->current;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    /** @param list<string> $owners */
    private function copy(UpgradeGateSnapshot $source, int $revision, array $owners): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $source->state,
            $revision,
            $source->jobId,
            $source->requiredRuntimeVersion,
            $source->requiredDeploymentId,
            $source->requiredStorageLayoutVersion,
            $source->requiredStorageLayoutGeneration,
            $source->deploymentEpoch,
            $source->activityGeneration,
            $source->redisIncarnation,
            true,
            $owners,
            $source->platformSyncPending,
            'ACTIVITY_TRACKING_UNCERTAIN',
            $source->updatedAt,
            $source->uncertainRevision ?? $revision,
            $source->replacementBarrierRevision,
            $source->taintedBootsOverflow,
        );
    }
}

final readonly class RuntimeSafetyContext implements UpgradeRuntimeContext
{
    public function owner(string $role): UpgradeRuntimeInstance
    {
        $boot = match ($role) {
            'http' => '318f5d35-3f42-7a31-a731-9e45df3356c2',
            'queue' => '418f5d35-3f42-7a31-a731-9e45df3356c2',
            'cron' => '518f5d35-3f42-7a31-a731-9e45df3356c2',
            default => throw new LogicException('invalid role'),
        };

        return new UpgradeRuntimeInstance(
            '218f5d35-3f42-7a31-a731-9e45df3356c2',
            $boot,
            $role,
            new UpgradeRuntimeIdentity('1.2.0', 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7', 1, 4),
            2,
        );
    }
}

final readonly class RuntimeSafetyRegistry implements UpgradeRuntimeRegistry
{
    /** @param list<array<string,mixed>> $records */
    public function __construct(private array $records)
    {
    }

    public function active(): array
    {
        return $this->records;
    }

    public function register(UpgradeRuntimeInstance $instance, array $queues, bool $cronEnabled, UpgradeGateSnapshot $gate, string $slotId): array
    {
        throw new LogicException('not used');
    }

    public function heartbeat(UpgradeRuntimeInstance $instance, array $queues, bool $cronEnabled, UpgradeGateSnapshot $gate, bool $identityFenced, ?int $pausedAckRevision): array
    {
        throw new LogicException('not used');
    }

    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array
    {
        throw new LogicException('not used');
    }
}
