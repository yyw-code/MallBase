<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use app\queue\UpgradeAwareWorker;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeLifecycle;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeState;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Event;
use think\Queue;
use think\exception\Handle;
use think\queue\Worker;
use Throwable;

final class UpgradeAwareWorkerTest extends TestCase
{
    public function testUpgradeAwareWorkerClassExistsAndExtendsVendorWorker(): void
    {
        self::assertTrue(class_exists(UpgradeAwareWorker::class), 'UpgradeAwareWorker production class is missing');
        self::assertTrue(is_subclass_of(UpgradeAwareWorker::class, Worker::class));
    }

    public function testDrainingStillPopsAndProcessesAlreadyDrainableQueueWork(): void
    {
        $job = new UpgradeWorkerJob('job-draining');
        [$worker, $connector, $tracker] = $this->worker(UpgradeState::Draining, [$job]);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(1, $connector->popCalls);
        self::assertTrue($job->fired);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(1, $tracker->bindCalls);
        self::assertSame(1, $tracker->releaseCalls);
    }

    #[DataProvider('pausedQueueStateProvider')]
    public function testPausedStatesHeartbeatThenAckExactOwnerWithoutPopping(UpgradeState $state): void
    {
        $events = [];
        [$worker, $connector, $tracker, $owner] = $this->worker($state, [new UpgradeWorkerJob('unexpected')], events: $events);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(0, $connector->popCalls);
        self::assertSame(1, $tracker->heartbeatCalls);
        self::assertSame(1, $tracker->ackCalls);
        self::assertSame($owner->key(), $tracker->ackedOwner?->key());
        self::assertSame(7, $tracker->ackedRevision);
        self::assertLessThan(
            array_search('activity.ack', $events, true),
            array_search('activity.heartbeat', $events, true),
            'heartbeat must be published before the exact owner pause acknowledgement',
        );
    }

    /** @return array<string,array{UpgradeState}> */
    public static function pausedQueueStateProvider(): array
    {
        return [
            'paused' => [UpgradeState::Paused],
            'reconciling' => [UpgradeState::Reconciling],
            'completed' => [UpgradeState::Completed],
        ];
    }

    public function testNormalMatchingIdentityOrdersPopIntentBindProcessAndFinallyRelease(): void
    {
        $events = [];
        $job = new UpgradeWorkerJob('job-1', $events);
        [$worker, $connector, $tracker] = $this->worker(UpgradeState::Normal, [$job], events: $events);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertTrue($job->fired);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(1, $tracker->bindCalls);
        self::assertSame(1, $tracker->releaseCalls);
        self::assertSame('job-1', $tracker->boundJobIds[0] ?? null);
        self::assertFalse(str_contains((string) ($tracker->connectorTypes[0] ?? ''), '\\'));
        self::assertEventOrder($events, [
            'activity.begin',
            'connector.pop:default',
            'activity.bind:job-1',
            'job.fire:job-1',
            'activity.release',
        ]);
    }

    public function testJobFailureStillReleasesBoundLease(): void
    {
        $events = [];
        $job = new UpgradeWorkerJob('job-failure', $events, new RuntimeException('job failed'));
        [$worker, , $tracker, , $handle] = $this->worker(UpgradeState::Normal, [$job], events: $events);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(1, $tracker->bindCalls);
        self::assertSame(1, $tracker->releaseCalls);
        self::assertCount(1, $handle->reported);
        self::assertSame('job failed', $handle->reported[0]->getMessage());
        self::assertEventOrder($events, ['activity.bind:job-failure', 'job.fire:job-failure', 'activity.release']);
    }

    public function testNullConnectorResultReleasesUnboundPopIntent(): void
    {
        $events = [];
        [$worker, $connector, $tracker] = $this->worker(UpgradeState::Normal, [null], events: $events);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(1, $connector->popCalls);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(0, $tracker->bindCalls);
        self::assertSame(1, $tracker->releaseCalls);
        self::assertEventOrder($events, ['activity.begin', 'connector.pop:default', 'activity.release']);
    }

    public function testDurablyUncertainNormalStateContinuesQueueWorkWithoutBinding(): void
    {
        $job = new UpgradeWorkerJob('job-untracked');
        [$worker, $connector, $tracker] = $this->worker(UpgradeState::Normal, [$job]);
        $tracker->untracked = true;

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(1, $connector->popCalls);
        self::assertTrue($job->fired);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(0, $tracker->bindCalls);
        self::assertSame(1, $tracker->releaseCalls);
    }

    #[DataProvider('fencedOwnerProvider')]
    public function testRuntimeIdentityOrEpochFencePreventsAnyPop(string $deploymentId, int $epoch): void
    {
        $owner = $this->owner($deploymentId, $epoch);
        [$worker, $connector, $tracker] = $this->worker(
            UpgradeState::Normal,
            [new UpgradeWorkerJob('unexpected')],
            owner: $owner,
        );

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(0, $connector->popCalls);
        self::assertSame(0, $tracker->beginCalls);
    }

    /** @return array<string,array{string,int}> */
    public static function fencedOwnerProvider(): array
    {
        return [
            'runtime identity mismatch' => [self::WRONG_DEPLOYMENT_ID, 2],
            'deployment epoch mismatch' => [self::DEPLOYMENT_ID, 1],
        ];
    }

    public function testSameJobDeliveredTwiceUsesDifferentExecutionAttemptIds(): void
    {
        $first = new UpgradeWorkerJob('same-job');
        $second = new UpgradeWorkerJob('same-job');
        [$worker, , $tracker] = $this->worker(UpgradeState::Normal, [$first, $second]);

        $worker->runNextJob('redis', 'default', 0, 0, 0);
        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertCount(2, $tracker->executionAttemptIds);
        self::assertNotSame($tracker->executionAttemptIds[0], $tracker->executionAttemptIds[1]);
        foreach ($tracker->executionAttemptIds as $attemptId) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $attemptId,
            );
        }
    }

    public function testDirectProcessFallbackBindsLeaseBeforeJobBody(): void
    {
        $events = [];
        $job = new UpgradeWorkerJob('job-direct', $events);
        [$worker, $connector, $tracker] = $this->worker(UpgradeState::Normal, [], events: $events);

        $worker->process('redis', $job, 0, 0);

        self::assertSame(0, $connector->popCalls);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(1, $tracker->bindCalls);
        self::assertSame(1, $tracker->releaseCalls);
        self::assertEventOrder($events, [
            'activity.begin',
            'activity.bind:job-direct',
            'job.fire:job-direct',
            'activity.release',
        ]);
    }

    /**
     * @param list<UpgradeWorkerJob|null> $jobs
     * @param list<string> $events
     * @return array{UpgradeAwareWorker,UpgradeWorkerConnector,UpgradeWorkerTracker,UpgradeRuntimeInstance,UpgradeWorkerHandle}
     */
    private function worker(
        UpgradeState $state,
        array $jobs,
        ?UpgradeRuntimeInstance $owner = null,
        array &$events = [],
    ): array {
        $owner ??= $this->owner();
        $connector = new UpgradeWorkerConnector($jobs, $events);
        $queue = new UpgradeWorkerQueue($connector);
        $event = new UpgradeWorkerEvent($events);
        $handle = new UpgradeWorkerHandle();
        $gate = new UpgradeWorkerGate($this->snapshot($state));
        $tracker = new UpgradeWorkerTracker($events);
        $runtime = new UpgradeWorkerRuntimeContext($owner);
        $lifecycle = new UpgradeWorkerLifecycle($events);

        return [
            new UpgradeAwareWorker(
                $queue,
                $event,
                $handle,
                null,
                $gate,
                $tracker,
                $runtime,
                $lifecycle,
                true,
            ),
            $connector,
            $tracker,
            $owner,
            $handle,
        ];
    }

    private function snapshot(UpgradeState $state): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            7,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
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

    private function owner(string $deploymentId = self::DEPLOYMENT_ID, int $epoch = 2): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'queue',
            new UpgradeRuntimeIdentity('1.2.0', $deploymentId, 1, 4),
            $epoch,
        );
    }

    /** @param list<string> $events @param list<string> $expected */
    private static function assertEventOrder(array $events, array $expected): void
    {
        $last = -1;
        foreach ($expected as $event) {
            $position = array_search($event, $events, true);
            self::assertIsInt($position, 'missing event: ' . $event . '; actual=' . implode('|', $events));
            self::assertGreaterThan($last, $position, 'event order violation: ' . $event);
            $last = $position;
        }
    }

    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const WRONG_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class UpgradeWorkerConnector
{
    public int $popCalls = 0;

    /** @param list<UpgradeWorkerJob|null> $jobs @param list<string> $events */
    public function __construct(
        private array $jobs,
        private array &$events,
    ) {
    }

    public function pop(string $queue): ?UpgradeWorkerJob
    {
        $this->popCalls++;
        $this->events[] = 'connector.pop:' . $queue;

        return array_shift($this->jobs);
    }
}

final class UpgradeWorkerQueue extends Queue
{
    public function __construct(private readonly UpgradeWorkerConnector $connector)
    {
    }

    public function connection($name = null)
    {
        return $this->connector;
    }
}

final class UpgradeWorkerEvent extends Event
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function trigger($event, $params = null, bool $once = false)
    {
        $this->events[] = 'vendor.event:' . basename(str_replace('\\', '/', $event::class));

        return null;
    }
}

final class UpgradeWorkerHandle extends Handle
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function __construct()
    {
    }

    public function report(Throwable $exception): void
    {
        $this->reported[] = $exception;
    }
}

final class UpgradeWorkerJob
{
    public bool $fired = false;
    private bool $failed = false;
    private bool $deleted = false;
    private bool $released = false;

    /** @param list<string> $events */
    public function __construct(
        private readonly string $jobId,
        private array &$events = [],
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function getJobId(): string { return $this->jobId; }
    public function getQueue(): string { return 'default'; }
    public function getConnection(): string { return 'redis'; }
    public function maxTries(): ?int { return null; }
    public function timeoutAt(): ?int { return null; }
    public function attempts(): int { return 1; }
    public function timeout(): ?int { return null; }
    public function getName(): string { return 'UpgradeWorkerJob'; }
    public function hasFailed(): bool { return $this->failed; }
    public function isDeleted(): bool { return $this->deleted; }
    public function isReleased(): bool { return $this->released; }
    public function markAsFailed(): void { $this->failed = true; }
    public function delete(): void { $this->deleted = true; }
    public function failed(Throwable $exception): void { $this->failed = true; }
    public function release(int $delay = 0): void { $this->released = true; }

    public function fire(): void
    {
        $this->fired = true;
        $this->events[] = 'job.fire:' . $this->jobId;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

final readonly class UpgradeWorkerRuntimeContext implements UpgradeRuntimeContext
{
    public function __construct(private UpgradeRuntimeInstance $owner)
    {
    }

    public function owner(string $role): UpgradeRuntimeInstance
    {
        if ($role !== 'queue') {
            throw new LogicException('unexpected role');
        }

        return $this->owner;
    }
}

final class UpgradeWorkerTracker implements UpgradeActivityTracker
{
    public bool $untracked = false;
    public int $heartbeatCalls = 0;
    public int $ackCalls = 0;
    public int $beginCalls = 0;
    public int $bindCalls = 0;
    public int $releaseCalls = 0;
    public ?UpgradeRuntimeInstance $ackedOwner = null;
    public ?int $ackedRevision = null;
    /** @var list<string> */
    public array $executionAttemptIds = [];
    /** @var list<string> */
    public array $connectorTypes = [];
    /** @var list<string> */
    public array $boundJobIds = [];

    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void
    {
        $this->heartbeatCalls++;
        $this->events[] = 'activity.heartbeat';
    }

    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void
    {
        $this->ackCalls++;
        $this->ackedOwner = $owner;
        $this->ackedRevision = $revision;
        $this->events[] = 'activity.ack';
    }

    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        $this->beginCalls++;
        $this->executionAttemptIds[] = $executionAttemptId;
        $this->connectorTypes[] = $connectorType;
        $this->events[] = 'activity.begin';

        return new UpgradeActivityLease(
            'queue:' . $executionAttemptId,
            str_repeat('a', 32),
            function (): void {
                $this->releaseCalls++;
                $this->events[] = 'activity.release';
            },
            $this->untracked,
            $executionAttemptId,
            11,
            str_repeat('a', 40),
        );
    }

    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease
    {
        $this->bindCalls++;
        $this->boundJobIds[] = $jobId;
        $this->events[] = 'activity.bind:' . $jobId;

        return $popLease;
    }

    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { throw new LogicException('not used'); }
    public function snapshot(): UpgradeActivitySnapshot { return new UpgradeActivitySnapshot(0, 0, 0, 0, 0, false); }
    public function liveWorkers(): array { return []; }
    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void {}
    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void {}
}

final class UpgradeWorkerLifecycle implements UpgradeRuntimeLifecycle
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function registerWorker(string $serverName, int $workerId, bool $cronEnabled): void
    {
        $this->events[] = 'lifecycle.register';
    }

    public function heartbeat(): void
    {
        $this->events[] = 'lifecycle.heartbeat';
    }

    public function stopWorker(): void
    {
        $this->events[] = 'lifecycle.stop';
    }
}

final readonly class UpgradeWorkerGate implements UpgradeGateRepository
{
    public function __construct(private UpgradeGateSnapshot $snapshot)
    {
    }

    public function snapshot(): UpgradeGateSnapshot { return $this->snapshot; }
    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot { throw new LogicException('not used'); }
    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot { throw new LogicException('not used'); }
}
