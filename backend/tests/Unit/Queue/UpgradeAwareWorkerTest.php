<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use app\queue\UpgradeAwareWorker;
use app\service\upgrade\SimpleUpgradeGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Event;
use think\Queue;
use think\exception\Handle;
use think\queue\Worker;
use Throwable;

final class UpgradeAwareWorkerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/mallbase-queue-gate-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testSimpleGateStopsNewPopsAndKeepsLeaseUntilReservedJobFinishes(): void
    {
        $gate = new SimpleUpgradeGate($this->directory);
        $job = new UpgradeWorkerJob('simple-job');
        [$worker, $connector] = $this->worker([$job, new UpgradeWorkerJob('pending-job')], $gate);

        $reserved = $worker->reserve($connector, 'default');
        self::assertSame($job, $reserved);

        $activity = fopen($this->directory . '/activity.lock', 'c+b');
        self::assertIsResource($activity);
        self::assertFalse(flock($activity, LOCK_EX | LOCK_NB));

        $worker->process('redis', $reserved, 0, 0);
        self::assertTrue($job->fired);
        self::assertTrue(flock($activity, LOCK_EX | LOCK_NB));
        flock($activity, LOCK_UN);
        fclose($activity);

        $gate->drain();
        $worker->runNextJob('redis', 'default', 0, 0, 0);
        self::assertSame(1, $connector->popCalls);
    }

    public function testMissingGateKeepsNormalQueueBehavior(): void
    {
        $job = new UpgradeWorkerJob('normal-job');
        [$worker, $connector] = $this->worker([$job]);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertSame(1, $connector->popCalls);
        self::assertTrue($job->fired);
    }

    public function testJobFailureStillReleasesSimpleLease(): void
    {
        $gate = new SimpleUpgradeGate($this->directory);
        $job = new UpgradeWorkerJob('failed-job', new RuntimeException('job failed'));
        [$worker, , $handle] = $this->worker([$job], $gate);

        $worker->runNextJob('redis', 'default', 0, 0, 0);

        self::assertCount(1, $handle->reported);
        self::assertSame('paused', $gate->drain());
    }

    public function testWorkerExtendsVendorWorker(): void
    {
        self::assertTrue(is_subclass_of(UpgradeAwareWorker::class, Worker::class));
    }

    /** @param list<UpgradeWorkerJob|null> $jobs @return array{SimpleUpgradeTestWorker,UpgradeWorkerConnector,UpgradeWorkerHandle} */
    private function worker(array $jobs, ?SimpleUpgradeGate $gate = null): array
    {
        $connector = new UpgradeWorkerConnector($jobs);
        $handle = new UpgradeWorkerHandle();

        return [
            new SimpleUpgradeTestWorker(
                new UpgradeWorkerQueue($connector),
                new UpgradeWorkerEvent(),
                $handle,
                null,
                $gate,
            ),
            $connector,
            $handle,
        ];
    }
}

final class SimpleUpgradeTestWorker extends UpgradeAwareWorker
{
    public function reserve(object $connector, string $queue): mixed
    {
        return $this->getNextJob($connector, $queue);
    }
}

final class UpgradeWorkerConnector
{
    public int $popCalls = 0;

    /** @param list<UpgradeWorkerJob|null> $jobs */
    public function __construct(private array $jobs)
    {
    }

    public function pop(string $queue): ?UpgradeWorkerJob
    {
        $this->popCalls++;

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
    public function __construct()
    {
    }

    public function trigger($event, $params = null, bool $once = false)
    {
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

    public function __construct(
        private readonly string $jobId,
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
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
