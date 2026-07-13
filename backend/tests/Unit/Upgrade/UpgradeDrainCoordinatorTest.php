<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\QueueInspector;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeBlockerSnapshot;
use app\service\upgrade\UpgradeDrainCheckpointRepository;
use app\service\upgrade\UpgradeDrainCoordinator;
use app\service\upgrade\UpgradeDrainGateRepository;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeStateConflict;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpgradeDrainCoordinatorTest extends TestCase
{
    private const JOB_ID = '018f1f4d-2be4-7b93-a342-1e66a7b5146f';

    public function testUpgradeDrainCoordinatorContractExists(): void
    {
        self::assertTrue(class_exists(UpgradeDrainCoordinator::class));
        self::assertTrue(class_exists(UpgradeBlockerSnapshot::class));
        self::assertTrue(interface_exists(QueueInspector::class));
        self::assertTrue(interface_exists(UpgradeDrainCheckpointRepository::class));
    }

    public function testBeginOnlyTransitionsReadyToDrainAndRecordsDurableStart(): void
    {
        [$coordinator, $gate, , $checkpoint] = $this->coordinator(UpgradeState::ReadyToDrain, revision: 10);

        $snapshot = $coordinator->begin(self::JOB_ID, 10);

        self::assertSame(UpgradeState::Draining, $snapshot->state);
        self::assertSame(11, $snapshot->revision);
        self::assertSame([[self::JOB_ID, 11, 1_700_000_000]], $checkpoint->drainStarts);
        self::assertSame([[10, UpgradeState::ReadyToDrain, UpgradeState::Draining, self::JOB_ID]], $gate->casCalls);

        [$wrongState] = $this->coordinator(UpgradeState::Preparing, revision: 10);
        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_DRAIN_STATE_CONFLICT');
        $wrongState->begin(self::JOB_ID, 10);
    }

    /** @param Closure():array{UpgradeActivitySnapshot,UpgradeQueueInventory} $blocker */
    #[DataProvider('blockingInventoryProvider')]
    public function testActivityReadyReservedAndUnsupportedInventoryBlockPause(Closure $blocker): void
    {
        [$activity, $inventory] = $blocker();
        [$coordinator, $gate] = $this->coordinator(
            UpgradeState::Draining,
            activity: $activity,
            inventory: $inventory,
        );

        $inspection = $coordinator->inspect();

        self::assertFalse($inspection->safe);
        self::assertSame(UpgradeState::Draining, $inspection->state);
        self::assertSame(10, $inspection->gateRevision);

        try {
            $coordinator->tryPause(10, true);
            self::fail('A drain blocker must prevent the paused CAS.');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_DRAIN_BLOCKED', $exception->getMessage());
        }
        self::assertSame([], $gate->casCalls);
    }

    public static function blockingInventoryProvider(): iterable
    {
        yield 'active activity lease' => [static fn (): array => [
            new UpgradeActivitySnapshot(1, 0, 0, 0, 0, false),
            new UpgradeQueueInventory([], [], []),
        ]];
        yield 'ready job' => [static fn (): array => [
            self::idleActivity(),
            new UpgradeQueueInventory([self::job('redis', 'default', 'ready-1')], [], []),
        ]];
        yield 'reserved job' => [static fn (): array => [
            self::idleActivity(),
            new UpgradeQueueInventory([], [self::job('redis', 'default', 'reserved-1')], []),
        ]];
        yield 'unsupported queue' => [static fn (): array => [
            self::idleActivity(),
            new UpgradeQueueInventory([], [], [], [
                ['connection' => 'sqs', 'reason' => 'UNSUPPORTED_QUEUE_DRIVER'],
            ]),
        ]];
    }

    public function testDelayedJobsRequireSignedCompatibilityBeforeAnyCheckpointOrCas(): void
    {
        $delayed = [self::job('redis', 'default', 'delayed-1')];
        [$coordinator, $gate, , $checkpoint] = $this->coordinator(
            UpgradeState::Draining,
            inventory: new UpgradeQueueInventory([], [], $delayed),
        );

        try {
            $coordinator->tryPause(10, false);
            self::fail('Unsigned delayed-job compatibility must block pausing.');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_DELAYED_QUEUE_INCOMPATIBLE', $exception->getMessage());
        }

        self::assertSame([], $checkpoint->deferredRecords);
        self::assertSame([], $gate->casCalls);
    }

    public function testCompatibleDelayedJobsAreSortedDeduplicatedAndPersistedExactly(): void
    {
        $jobA = self::job('redis', 'alpha', 'delayed-a');
        $jobB = self::job('redis', 'zeta', 'delayed-b');
        [$coordinator, , , $checkpoint] = $this->coordinator(
            UpgradeState::Draining,
            inventory: new UpgradeQueueInventory([], [], [$jobB, $jobA, $jobA]),
        );

        $paused = $coordinator->tryPause(10, true);

        self::assertSame(UpgradeState::Paused, $paused->state);
        self::assertSame(11, $paused->revision);
        self::assertSame([[self::JOB_ID, 10, [$jobA, $jobB]]], $checkpoint->deferredRecords);
        self::assertSame([$jobA, $jobB], $checkpoint->deferredJobs(self::JOB_ID));
    }

    public function testFinalPopAfterPausedCasKeepsGatePausedUntilExplicitConfirmationIsSafe(): void
    {
        [$coordinator, $gate, $tracker] = $this->coordinator(UpgradeState::Draining, ackTimeoutSeconds: 1);
        $gate->afterCas = static function () use ($tracker): void {
            $tracker->activity = new UpgradeActivitySnapshot(0, 0, 0, 1, 0, false);
        };

        $paused = $coordinator->tryPause(10, true);

        self::assertSame(UpgradeState::Paused, $paused->state);
        self::assertSame(11, $paused->revision);
        self::assertSame(UpgradeState::Paused, $gate->snapshot()->state);

        try {
            $coordinator->confirmPaused(11);
            self::fail('A final pop intent must keep the paused state unconfirmed.');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_DRAIN_NOT_SAFE', $exception->getMessage());
        }
        self::assertSame(UpgradeState::Paused, $gate->snapshot()->state);

        $tracker->activity = self::idleActivity();
        $confirmed = $coordinator->confirmPaused(11);
        self::assertSame(UpgradeState::Paused, $confirmed->state);
        self::assertSame(11, $confirmed->revision);
    }

    public function testLiveWorkersMustAcknowledgeTheExactPausedRevision(): void
    {
        [$coordinator, , $tracker] = $this->coordinator(UpgradeState::Paused, revision: 11);
        $tracker->workers = [
            ['worker_id' => 'worker-exact', 'paused_revision' => 11],
            ['worker_id' => 'worker-stale', 'paused_revision' => 10],
        ];

        $blocked = $coordinator->inspect();

        self::assertFalse($blocked->safe);
        self::assertSame(['worker-stale'], $blocked->missingWorkerAcks);

        $tracker->workers[1]['paused_revision'] = 11;
        $safe = $coordinator->inspect();
        self::assertTrue($safe->safe);
        self::assertSame([], $safe->missingWorkerAcks);
    }

    public function testResumeDrainOnlyTransitionsPausedBackToDraining(): void
    {
        [$coordinator, $gate] = $this->coordinator(UpgradeState::Paused, revision: 11);

        $resumed = $coordinator->resumeDrain(11);

        self::assertSame(UpgradeState::Draining, $resumed->state);
        self::assertSame(12, $resumed->revision);
        self::assertSame([[11, UpgradeState::Paused, UpgradeState::Draining, self::JOB_ID]], $gate->casCalls);

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_DRAIN_STATE_CONFLICT');
        $coordinator->resumeDrain(12);
    }

    public function testResumeThenPauseAgainPersistsTheNewDeferredRevision(): void
    {
        $delayed = self::job('redis', 'default', 'delayed-retry');
        [$coordinator, , , $checkpoint] = $this->coordinator(
            UpgradeState::Draining,
            revision: 10,
            inventory: new UpgradeQueueInventory([], [], [$delayed]),
        );

        $firstPaused = $coordinator->tryPause(10, true);
        $resumed = $coordinator->resumeDrain($firstPaused->revision);
        $secondPaused = $coordinator->tryPause($resumed->revision, true);

        self::assertSame(UpgradeState::Paused, $secondPaused->state);
        self::assertSame(13, $secondPaused->revision);
        self::assertSame([
            [self::JOB_ID, 10, [$delayed]],
            [self::JOB_ID, 12, [$delayed]],
        ], $checkpoint->deferredRecords);
    }

    public function testEnteringBackupAlwaysRunsThePausedSafetyCheckFirst(): void
    {
        [$coordinator, $gate, $tracker] = $this->coordinator(UpgradeState::Paused, revision: 11);
        $tracker->activity = new UpgradeActivitySnapshot(1, 0, 0, 0, 0, false);
        try {
            $coordinator->confirmAndEnterBackingUp(11);
            self::fail('unsafe paused state entered backup');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_DRAIN_NOT_SAFE', $exception->getMessage());
        }
        self::assertSame([], $gate->casCalls);

        $tracker->activity = self::idleActivity();
        $backingUp = $coordinator->confirmAndEnterBackingUp(11);
        self::assertSame(UpgradeState::BackingUp, $backingUp->state);
        self::assertSame(12, $backingUp->revision);
    }

    public function testPersistedDeferredJobBecomingReadyIsAllowedButANewReadyJobBlocks(): void
    {
        $deferred = self::job('redis', 'default', 'deferred-1');
        $newReady = self::job('redis', 'default', 'new-ready-1');
        [$coordinator, , , $checkpoint, $inspector] = $this->coordinator(
            UpgradeState::Paused,
            revision: 11,
            inventory: new UpgradeQueueInventory([$deferred], [], []),
        );
        $checkpoint->deferredByJob[self::JOB_ID] = [$deferred];

        $allowed = $coordinator->inspect();

        self::assertTrue($allowed->safe);
        self::assertSame([$deferred], $allowed->allowedDeferredJobs);

        $inspector->current = new UpgradeQueueInventory([$deferred, $newReady], [], []);
        $blocked = $coordinator->inspect();
        self::assertFalse($blocked->safe);
        self::assertSame([$deferred], $blocked->allowedDeferredJobs);

        [$draining, , , $drainingCheckpoint] = $this->coordinator(
            UpgradeState::Draining,
            inventory: new UpgradeQueueInventory([$newReady], [], []),
        );
        $drainingCheckpoint->deferredByJob[self::JOB_ID] = [$deferred];
        try {
            $draining->tryPause(10, true);
            self::fail('A newly ready job not in the durable deferred checkpoint must block pausing.');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_DRAIN_BLOCKED', $exception->getMessage());
        }
    }

    /**
     * @return array{UpgradeDrainCoordinator,DrainTestGate,DrainTestActivityTracker,DrainTestCheckpoint,DrainTestQueueInspector}
     */
    private function coordinator(
        UpgradeState $state,
        int $revision = 10,
        ?UpgradeActivitySnapshot $activity = null,
        ?UpgradeQueueInventory $inventory = null,
        int $ackTimeoutSeconds = 1,
    ): array {
        $gate = new DrainTestGate($this->snapshot($state, $revision));
        $tracker = new DrainTestActivityTracker($activity ?? self::idleActivity());
        $inspector = new DrainTestQueueInspector($inventory ?? new UpgradeQueueInventory([], [], []));
        $checkpoint = new DrainTestCheckpoint();
        $now = 1_700_000_000;

        $coordinator = new UpgradeDrainCoordinator(
            $gate,
            $tracker,
            $inspector,
            $checkpoint,
            static function () use (&$now): int {
                return $now;
            },
            static function () use (&$now): void {
                $now++;
            },
            $ackTimeoutSeconds,
        );

        return [$coordinator, $gate, $tracker, $checkpoint, $inspector];
    }

    private function snapshot(UpgradeState $state, int $revision): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7',
            1,
            4,
            2,
            7,
            str_repeat('b', 40),
            false,
            [],
            false,
            null,
            1_700_000_000,
        );
    }

    /** @return array{connection:string,queue:string,job_id:string} */
    private static function job(string $connection, string $queue, string $jobId): array
    {
        return ['connection' => $connection, 'queue' => $queue, 'job_id' => $jobId];
    }

    private static function idleActivity(): UpgradeActivitySnapshot
    {
        return new UpgradeActivitySnapshot(0, 0, 0, 0, 0, false);
    }
}

final class DrainTestGate implements UpgradeGateRepository, UpgradeDrainGateRepository
{
    /** @var list<array{int,UpgradeState,UpgradeState,string}> */
    public array $casCalls = [];
    public ?Closure $afterCas = null;

    public function __construct(private UpgradeGateSnapshot $current)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->current;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        if ($this->current->revision !== $expectedRevision || $this->current->state !== $expectedState
            || $this->current->jobId !== $jobId) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_STATE_CONFLICT');
        }
        $this->casCalls[] = [$expectedRevision, $expectedState, $nextState, $jobId];
        $this->current = new UpgradeGateSnapshot(
            $nextState,
            $expectedRevision + 1,
            $jobId,
            $this->current->requiredRuntimeVersion,
            $this->current->requiredDeploymentId,
            $this->current->requiredStorageLayoutVersion,
            $this->current->requiredStorageLayoutGeneration,
            $this->current->deploymentEpoch,
            $this->current->activityGeneration,
            $this->current->redisIncarnation,
            $this->current->uncertain,
            $this->current->taintedBoots,
            $this->current->platformSyncPending,
            $this->current->failureCode,
            $this->current->updatedAt + 1,
            $this->current->uncertainRevision,
            $this->current->replacementBarrierRevision,
            $this->current->taintedBootsOverflow,
        );
        ($this->afterCas ?? static function (): void {})();

        return $this->current;
    }

    public function enterBackingUpAfterDrain(int $expectedRevision, string $jobId): UpgradeGateSnapshot
    {
        return $this->compareAndSet(
            $expectedRevision,
            UpgradeState::Paused,
            UpgradeState::BackingUp,
            $jobId,
        );
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot { throw new LogicException('not used'); }
}

final class DrainTestQueueInspector implements QueueInspector
{
    public function __construct(public UpgradeQueueInventory $current)
    {
    }

    public function inventory(): UpgradeQueueInventory
    {
        return $this->current;
    }
}

final class DrainTestCheckpoint implements UpgradeDrainCheckpointRepository
{
    /** @var list<array{string,int,int}> */
    public array $drainStarts = [];
    /** @var list<array{string,int,list<array{connection:string,queue:string,job_id:string}>}> */
    public array $deferredRecords = [];
    /** @var array<string,list<array{connection:string,queue:string,job_id:string}>> */
    public array $deferredByJob = [];

    public function recordDrainStarted(string $jobId, int $gateRevision, int $startedAt): void
    {
        $this->drainStarts[] = [$jobId, $gateRevision, $startedAt];
    }

    public function recordDeferredJobs(string $jobId, int $gateRevision, array $entries): void
    {
        $this->deferredRecords[] = [$jobId, $gateRevision, $entries];
        $this->deferredByJob[$jobId] = $entries;
    }

    public function deferredJobs(string $jobId): array
    {
        return $this->deferredByJob[$jobId] ?? [];
    }
}

final class DrainTestActivityTracker implements UpgradeActivityTracker
{
    /** @param list<array<string,mixed>> $workers */
    public function __construct(public UpgradeActivitySnapshot $activity, public array $workers = [])
    {
    }

    public function snapshot(): UpgradeActivitySnapshot { return $this->activity; }
    public function liveWorkers(): array { return $this->workers; }
    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease { throw new LogicException('not used'); }
    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void { throw new LogicException('not used'); }
    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void { throw new LogicException('not used'); }
    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void { throw new LogicException('not used'); }
    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void { throw new LogicException('not used'); }
}
