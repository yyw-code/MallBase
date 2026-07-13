<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\ThinkQueueInspector;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeStateConflict;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ThinkQueueInspectorTest extends TestCase
{
    public function testThinkQueueInspectorContractExists(): void
    {
        self::assertTrue(class_exists(ThinkQueueInspector::class));
    }

    public function testRedisReadyReservedAndDelayedPayloadsRemainSeparateAndUsePayloadId(): void
    {
        $calls = [];
        $inspector = $this->inspector(
            connections: ['redis'],
            queueNames: ['default', 'critical'],
            configs: ['redis' => ['type' => 'redis', 'queue' => 'default']],
            redisReader: static function (string $connection, array $config, array $queues) use (&$calls): array {
                $calls[] = [$connection, $config, $queues];

                return [
                    'ready' => [self::redisRow('default', 'ready-1')],
                    'reserved' => [self::redisRow('critical', 'reserved-1')],
                    'delayed' => [self::redisRow('default', 'delayed-1')],
                ];
            },
        );

        $inventory = $inspector->inventory();

        self::assertSame([self::job('redis', 'default', 'ready-1')], $inventory->ready);
        self::assertSame([self::job('redis', 'critical', 'reserved-1')], $inventory->reserved);
        self::assertSame([self::job('redis', 'default', 'delayed-1')], $inventory->delayed);
        self::assertSame([], $inventory->unsupported);
        self::assertSame([
            ['redis', ['type' => 'redis', 'queue' => 'default'], ['critical', 'default']],
        ], $calls);
    }

    public function testDatabaseRowsUseReserveAndAvailableTimesForExactPhaseClassification(): void
    {
        $databaseCalls = [];
        $inspector = $this->inspector(
            connections: ['database'],
            queueNames: ['default'],
            configs: ['database' => ['type' => 'database', 'queue' => 'default', 'table' => 'jobs']],
            databaseReader: static function (string $connection, array $config, array $queues) use (&$databaseCalls): array {
                $databaseCalls[] = [$connection, $config, $queues];

                return [
                    ['id' => 20, 'queue' => 'default', 'reserve_time' => null, 'available_time' => 1_001],
                    ['id' => '10', 'queue' => 'default', 'reserve_time' => null, 'available_time' => 1_000],
                    ['id' => 30, 'queue' => 'default', 'reserve_time' => 999, 'available_time' => 900],
                ];
            },
            clock: static fn (): int => 1_000,
        );

        $inventory = $inspector->inventory();

        self::assertSame([self::job('database', 'default', '10')], $inventory->ready);
        self::assertSame([self::job('database', 'default', '30')], $inventory->reserved);
        self::assertSame([self::job('database', 'default', '20')], $inventory->delayed);
        self::assertSame([
            ['database', ['type' => 'database', 'queue' => 'default', 'table' => 'jobs'], ['default']],
        ], $databaseCalls);
    }

    public function testSyncConnectionIsExplicitlyEmptyWithoutCallingExternalReaders(): void
    {
        $readerCalled = false;
        $failIfCalled = static function () use (&$readerCalled): array {
            $readerCalled = true;
            throw new LogicException('sync must not inspect an external queue');
        };
        $inspector = $this->inspector(
            connections: ['sync'],
            queueNames: ['default'],
            configs: ['sync' => ['type' => 'sync']],
            redisReader: $failIfCalled,
            databaseReader: $failIfCalled,
        );

        $inventory = $inspector->inventory();

        self::assertSame([], $inventory->ready);
        self::assertSame([], $inventory->reserved);
        self::assertSame([], $inventory->delayed);
        self::assertSame([], $inventory->unsupported);
        self::assertFalse($readerCalled);
    }

    public function testUnknownAndCustomDriversAreReportedAsUnsupportedInsteadOfAssumedEmpty(): void
    {
        $inspector = $this->inspector(
            connections: ['custom', 'unknown'],
            configs: [
                'custom' => ['type' => 'app\\queue\\connector\\Kafka'],
                'unknown' => ['type' => 'mystery'],
            ],
        );

        $inventory = $inspector->inventory();

        self::assertSame([], $inventory->ready);
        self::assertSame([
            ['connection' => 'custom', 'reason' => 'UNSUPPORTED_QUEUE_DRIVER'],
            ['connection' => 'unknown', 'reason' => 'UNSUPPORTED_QUEUE_DRIVER'],
        ], $inventory->unsupported);
    }

    public function testConfiguredQueueNamesAndLiveWorkerQueuesAreInspectedAsSortedUnion(): void
    {
        $tracker = new QueueInspectorActivityTracker([
            ['worker_id' => 'worker-1', 'queues' => ['emails', 'critical'], 'expired' => false],
            ['worker_id' => 'worker-expired', 'queues' => ['stale'], 'expired' => true],
        ]);
        $seenQueues = [];
        $inspector = $this->inspector(
            activity: $tracker,
            connections: ['redis'],
            queueNames: ['manual', 'critical'],
            configs: ['redis' => ['type' => 'redis', 'queue' => 'default']],
            redisReader: static function (string $connection, array $config, array $queues) use (&$seenQueues): array {
                $seenQueues = $queues;

                return ['ready' => [], 'reserved' => [], 'delayed' => []];
            },
        );

        $inspector->inventory();

        self::assertSame(['critical', 'default', 'emails', 'manual'], $seenQueues);
    }

    public function testDuplicateEntriesAreSortedAndDeduplicatedWithinEachPhase(): void
    {
        $inspector = $this->inspector(
            connections: ['redis-b', 'redis-a'],
            configs: [
                'redis-a' => ['type' => 'redis', 'queue' => 'default'],
                'redis-b' => ['type' => 'redis', 'queue' => 'default'],
            ],
            redisReader: static function (string $connection): array {
                $rows = $connection === 'redis-a'
                    ? [self::redisRow('zeta', 'job-z'), self::redisRow('alpha', 'job-b'), self::redisRow('alpha', 'job-a')]
                    : [self::redisRow('default', 'job-b')];

                return [
                    'ready' => [$rows[0], $rows[0], ...array_slice($rows, 1)],
                    'reserved' => [],
                    'delayed' => [],
                ];
            },
        );

        $inventory = $inspector->inventory();

        self::assertSame([
            self::job('redis-a', 'alpha', 'job-a'),
            self::job('redis-a', 'alpha', 'job-b'),
            self::job('redis-a', 'zeta', 'job-z'),
            self::job('redis-b', 'default', 'job-b'),
        ], $inventory->ready);
    }

    public function testMoreThanOneMillionEntriesInOnePhaseFailsClosed(): void
    {
        $row = self::redisRow('default', 'same-job');
        $inspector = $this->inspector(
            connections: ['redis'],
            configs: ['redis' => ['type' => 'redis', 'queue' => 'default']],
            redisReader: static fn (): array => [
                'ready' => array_fill(0, 1_000_001, $row),
                'reserved' => [],
                'delayed' => [],
            ],
        );

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_QUEUE_INVENTORY_TOO_LARGE');
        $inspector->inventory();
    }

    /** @param Closure():ThinkQueueInspector $factory */
    #[DataProvider('malformedInventoryProvider')]
    public function testMalformedPayloadsAndRowsFailClosedWithStableCode(Closure $factory): void
    {
        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_QUEUE_INVENTORY_INVALID');
        $factory()->inventory();
    }

    public static function malformedInventoryProvider(): iterable
    {
        yield 'invalid Redis JSON' => [static fn (): ThinkQueueInspector => self::staticInspector(
            ['redis'],
            ['redis' => ['type' => 'redis', 'queue' => 'default']],
            static fn (): array => [
                'ready' => [['queue' => 'default', 'payload' => '{not-json']],
                'reserved' => [],
                'delayed' => [],
            ],
        )];
        yield 'Redis payload without id' => [static fn (): ThinkQueueInspector => self::staticInspector(
            ['redis'],
            ['redis' => ['type' => 'redis', 'queue' => 'default']],
            static fn (): array => [
                'ready' => [['queue' => 'default', 'payload' => json_encode(['attempts' => 0], JSON_THROW_ON_ERROR)]],
                'reserved' => [],
                'delayed' => [],
            ],
        )];
        yield 'malformed Database row' => [static fn (): ThinkQueueInspector => self::staticInspector(
            ['database'],
            ['database' => ['type' => 'database', 'queue' => 'default']],
            null,
            static fn (): array => [
                ['id' => 1, 'queue' => 'default', 'reserve_time' => null],
            ],
        )];
    }

    public function testReaderFailureUsesUnavailableCodeAndNeverReturnsPartialInventory(): void
    {
        $inspector = $this->inspector(
            connections: ['redis'],
            configs: ['redis' => ['type' => 'redis', 'queue' => 'default']],
            redisReader: static function (): never {
                throw new RuntimeException('redis unavailable');
            },
        );

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
        $inspector->inventory();
    }

    /** @param array<string,array<string,mixed>> $configs */
    private function inspector(
        ?UpgradeActivityTracker $activity = null,
        ?array $connections = null,
        ?array $queueNames = null,
        array $configs = [],
        ?Closure $redisReader = null,
        ?Closure $databaseReader = null,
        ?Closure $clock = null,
    ): ThinkQueueInspector {
        return self::staticInspector(
            $connections,
            $configs,
            $redisReader,
            $databaseReader,
            $activity,
            $queueNames ?? ['default'],
            $clock,
        );
    }

    /** @param array<string,array<string,mixed>> $configs */
    private static function staticInspector(
        ?array $connections,
        array $configs,
        ?Closure $redisReader = null,
        ?Closure $databaseReader = null,
        ?UpgradeActivityTracker $activity = null,
        ?array $queueNames = null,
        ?Closure $clock = null,
    ): ThinkQueueInspector {
        return new ThinkQueueInspector(
            $activity,
            $connections,
            $queueNames ?? ['default'],
            static function (string $connection) use ($configs): array {
                if (!array_key_exists($connection, $configs)) {
                    throw new RuntimeException('missing connection config');
                }

                return $configs[$connection];
            },
            $redisReader,
            $databaseReader,
            $clock ?? static fn (): int => 1_000,
        );
    }

    /** @return array{queue:string,payload:string} */
    private static function redisRow(string $queue, string $jobId): array
    {
        return [
            'queue' => $queue,
            'payload' => json_encode(['id' => $jobId, 'attempts' => 0], JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array{connection:string,queue:string,job_id:string} */
    private static function job(string $connection, string $queue, string $jobId): array
    {
        return ['connection' => $connection, 'queue' => $queue, 'job_id' => $jobId];
    }
}

final class QueueInspectorActivityTracker implements UpgradeActivityTracker
{
    /** @param list<array<string,mixed>> $workers */
    public function __construct(private array $workers)
    {
    }

    public function liveWorkers(): array { return $this->workers; }
    public function snapshot(): UpgradeActivitySnapshot { throw new LogicException('not used'); }
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
