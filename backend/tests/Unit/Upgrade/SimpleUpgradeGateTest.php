<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\SimpleUpgradeGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SimpleUpgradeGateTest extends TestCase
{
    private string $runDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runDirectory = sys_get_temp_dir() . '/mallbase-simple-gate-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->runDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->runDirectory);
        parent::tearDown();
    }

    public function testSimpleUpgradeGateProductionClassExists(): void
    {
        self::assertTrue(
            class_exists(\app\service\upgrade\SimpleUpgradeGate::class),
            'SimpleUpgradeGate production class is missing',
        );
    }

    public function testInitializesExactRunFilesAndStrictNormalState(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);

        self::assertSame('normal', $gate->state());
        self::assertFileExists($this->runDirectory . '/admission.lock');
        self::assertFileExists($this->runDirectory . '/activity.lock');
        self::assertSame(0660, fileperms($this->runDirectory . '/admission.lock') & 0777);
        self::assertSame(0660, fileperms($this->runDirectory . '/activity.lock') & 0777);
        self::assertSame(
            "{\"schema_version\":1,\"state\":\"normal\"}\n",
            file_get_contents($this->runDirectory . '/state.json'),
        );
    }

    public function testRejectsSymlinkLockWithoutChangingItsTarget(): void
    {
        mkdir($this->runDirectory, 0770, true);
        $target = $this->runDirectory . '/lock-target';
        file_put_contents($target, 'target');
        chmod($target, 0600);
        symlink($target, $this->runDirectory . '/admission.lock');

        try {
            new SimpleUpgradeGate($this->runDirectory);
            self::fail('Expected symlink lock to be rejected');
        } catch (RuntimeException $exception) {
            self::assertSame('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE', $exception->getMessage());
        }
        self::assertSame(0600, fileperms($target) & 0777);
    }

    public function testRejectsNonCanonicalOrUnknownStateDocument(): void
    {
        new SimpleUpgradeGate($this->runDirectory);
        file_put_contents(
            $this->runDirectory . '/state.json',
            "{ \"schema_version\": 1, \"state\": \"normal\", \"extra\": true }\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SIMPLE_UPGRADE_STATE_INVALID');
        new SimpleUpgradeGate($this->runDirectory);
    }

    public function testDrainClosesAdmissionThenWaitsForCurrentActivityBeforePaused(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);
        $lease = $gate->tryEnter();
        self::assertNotNull($lease);

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<'PHP'
require $argv[1];
$gate = new app\service\upgrade\SimpleUpgradeGate($argv[2]);
$gate->drain();
PHP;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $autoload, $this->runDirectory],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        $deadline = microtime(true) + 2;
        while ($gate->state() !== 'draining' && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertSame('draining', $gate->state());
        self::assertNull($gate->tryEnter(), 'new activity entered after draining closed admission');
        self::assertTrue(proc_get_status($process)['running'], 'drain did not wait for current activity');

        $lease->release();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "drain subprocess failed: {$stdout}{$stderr}");
        self::assertSame('paused', $gate->state());
    }

    public function testPausedCanAdvanceToAwaitingPhpRestartAndThenNormal(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);

        self::assertSame('paused', $gate->drain());
        self::assertSame('awaiting_php_restart', $gate->markAwaitingPhpRestart());
        self::assertNull($gate->tryEnter());
        self::assertSame('normal', $gate->restoreNormal());
        self::assertNotNull($gate->tryEnter());
    }
}
