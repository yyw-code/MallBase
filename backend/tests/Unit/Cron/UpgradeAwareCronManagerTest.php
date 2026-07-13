<?php

declare(strict_types=1);

namespace Tests\Unit\Cron;

use app\cron\CronManager;
use app\cron\CronTaskInterface;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use mall_base\log\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeAwareCronManagerTest extends TestCase
{
    public function testCronCallbackRunsInsideLeaseAndAlwaysReleasesIt(): void
    {
        $tracker = new CronTrackerFake();
        $task = new CapturingCronTask();
        $manager = new TestCronManager($tracker, new CronRuntimeContextFake(), $task, true);
        $sandboxCalls = 0;
        $manager->boot(0, static function (callable $callback) use (&$sandboxCalls): void {
            $sandboxCalls++;
            $callback();
        });

        self::assertNotNull($task->callback);
        $bodyCalls = 0;
        ($task->callback)(static function () use (&$bodyCalls): void {
            $bodyCalls++;
        });

        self::assertSame(1, $sandboxCalls);
        self::assertSame(1, $bodyCalls);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(1, $tracker->releaseCalls);
        self::assertSame('cron', $tracker->lastOwner?->role);
    }

    public function testDeniedLeaseSuppressesNewCronCallback(): void
    {
        $tracker = new CronTrackerFake();
        $tracker->allow = false;
        $task = new CapturingCronTask();
        $manager = new TestCronManager($tracker, new CronRuntimeContextFake(), $task, true);
        $sandboxCalls = 0;
        $manager->boot(0, static function () use (&$sandboxCalls): void {
            $sandboxCalls++;
        });

        ($task->callback)(static function (): void {
            self::fail('denied Cron callback entered its task body');
        });

        self::assertSame(0, $sandboxCalls);
        self::assertSame(1, $tracker->beginCalls);
        self::assertSame(0, $tracker->releaseCalls);
    }

    public function testTaskFailureStillReleasesLease(): void
    {
        $tracker = new CronTrackerFake();
        $task = new CapturingCronTask();
        $manager = new TestCronManager($tracker, new CronRuntimeContextFake(), $task, true);
        $manager->boot(0, static function (callable $callback): void {
            $callback();
        });

        try {
            ($task->callback)(static function (): void {
                throw new RuntimeException('expected task failure');
            });
            self::fail('task exception was swallowed');
        } catch (RuntimeException $exception) {
            self::assertSame('expected task failure', $exception->getMessage());
        }
        self::assertSame(1, $tracker->releaseCalls);
    }

    public function testDisabledUpgradeRuntimeKeepsExistingCronBehavior(): void
    {
        $tracker = new CronTrackerFake();
        $task = new CapturingCronTask();
        $manager = new TestCronManager($tracker, new CronRuntimeContextFake(), $task, false);
        $sandboxCalls = 0;
        $manager->boot(0, static function (callable $callback) use (&$sandboxCalls): void {
            $sandboxCalls++;
            $callback();
        });

        ($task->callback)(static function (): void {
        });

        self::assertSame(1, $sandboxCalls);
        self::assertSame(0, $tracker->beginCalls);
    }
}

final class TestCronManager extends CronManager
{
    public function __construct(
        UpgradeActivityTracker $tracker,
        UpgradeRuntimeContext $runtime,
        private readonly CronTaskInterface $task,
        private readonly bool $upgradeRuntimeEnabled,
    ) {
        parent::__construct($tracker, $runtime);
    }

    protected function configuredOnlyWorkerId(): int
    {
        return 0;
    }

    protected function logger(): ?Logger
    {
        return null;
    }

    protected function isInstalled(): bool
    {
        return true;
    }

    protected function cronEnabled(): bool
    {
        return true;
    }

    protected function upgradeEnabled(): bool
    {
        return $this->upgradeRuntimeEnabled;
    }

    protected function tasks(): array
    {
        return [$this->task];
    }

    protected function resolveTask(mixed $task): CronTaskInterface
    {
        return $this->task;
    }
}

final class CapturingCronTask implements CronTaskInterface
{
    public mixed $callback = null;

    public function register(callable $runInSandbox): void
    {
        $this->callback = $runInSandbox;
    }
}

final class CronRuntimeContextFake implements UpgradeRuntimeContext
{
    public function owner(string $role): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            '018f5d35-3f42-7a31-a731-9e45df3356c1',
            '118f5d35-3f42-7a31-a731-9e45df3356c1',
            $role,
            new UpgradeRuntimeIdentity('1.0.0', '218f5d35-3f42-7a31-a731-9e45df3356c1', 0, 1),
            1,
        );
    }
}

final class CronTrackerFake implements UpgradeActivityTracker
{
    public bool $allow = true;
    public int $beginCalls = 0;
    public int $releaseCalls = 0;
    public ?UpgradeRuntimeInstance $lastOwner = null;

    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        $this->beginCalls++;
        $this->lastOwner = $owner;
        if (!$this->allow) {
            return null;
        }

        return new UpgradeActivityLease('cron:test', str_repeat('a', 32), function (): void {
            $this->releaseCalls++;
        });
    }

    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease { return $popLease; }
    public function snapshot(): UpgradeActivitySnapshot { return new UpgradeActivitySnapshot(0, 0, 0, 0, 0, false); }
    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void {}
    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void {}
    public function liveWorkers(): array { return []; }
    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void {}
    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void {}
}
