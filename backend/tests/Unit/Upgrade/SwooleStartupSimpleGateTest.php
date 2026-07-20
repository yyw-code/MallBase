<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\listener\swoole\SwooleStartupListener;
use app\service\upgrade\SimpleUpgradeGate;
use PHPUnit\Framework\TestCase;

final class SwooleStartupSimpleGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-simple-startup-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testStartupRestoresOnlyAwaitingPhpRestart(): void
    {
        $gate = new SimpleUpgradeGate($this->root);
        $gate->drain();
        $gate->markAwaitingPhpRestart();
        $listener = new TestableSwooleStartupListener($gate);

        $listener->restoreSimpleGate();

        self::assertSame('normal', $gate->state());
    }

    public function testStartupLeavesPausedAndDrainingUntouched(): void
    {
        $gate = new SimpleUpgradeGate($this->root);
        $gate->drain();
        $listener = new TestableSwooleStartupListener($gate);
        $listener->restoreSimpleGate();
        self::assertSame('paused', $gate->state());

        file_put_contents($this->root . '/state.json', "{\"schema_version\":1,\"state\":\"draining\"}\n");
        $listener->restoreSimpleGate();
        self::assertSame('draining', $gate->state());
    }
}

final class TestableSwooleStartupListener extends SwooleStartupListener
{
    public function restoreSimpleGate(): void
    {
        $this->restoreSimpleGateAfterPhpRestart();
    }
}
