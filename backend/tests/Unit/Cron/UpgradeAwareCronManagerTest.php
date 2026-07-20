<?php

declare(strict_types=1);

namespace Tests\Unit\Cron;

use app\cron\CronManager;
use app\cron\CronTaskInterface;
use app\service\upgrade\SimpleUpgradeGate;
use mall_base\log\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeAwareCronManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/mallbase-cron-gate-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testSimpleGateSuppressesNewCronCallbacksAfterDrain(): void
    {
        $gate = new SimpleUpgradeGate($this->directory);
        $task = new CapturingCronTask();
        $manager = new TestCronManager($task, $gate);
        $sandboxCalls = 0;
        $manager->boot(0, static function (callable $callback) use (&$sandboxCalls): void {
            $sandboxCalls++;
            $callback();
        });

        ($task->callback)(static function (): void {
        });
        $gate->drain();
        ($task->callback)(static function (): void {
            self::fail('paused gate allowed a new Cron callback');
        });

        self::assertSame(1, $sandboxCalls);
    }

    public function testMissingGateKeepsNormalCronBehavior(): void
    {
        $task = new CapturingCronTask();
        $manager = new TestCronManager($task);
        $bodyCalls = 0;
        $manager->boot(0, static function (callable $callback): void {
            $callback();
        });

        ($task->callback)(static function () use (&$bodyCalls): void {
            $bodyCalls++;
        });

        self::assertSame(1, $bodyCalls);
    }

    public function testTaskFailureStillReleasesSimpleLease(): void
    {
        $gate = new SimpleUpgradeGate($this->directory);
        $task = new CapturingCronTask();
        $manager = new TestCronManager($task, $gate);
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

        self::assertSame('paused', $gate->drain());
    }
}

final class TestCronManager extends CronManager
{
    public function __construct(
        private readonly CronTaskInterface $task,
        ?SimpleUpgradeGate $simpleGate = null,
    ) {
        parent::__construct($simpleGate);
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
