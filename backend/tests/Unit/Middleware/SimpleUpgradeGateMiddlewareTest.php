<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\admin\UpgradeAdminGateMiddleware;
use app\middleware\UpgradeTrafficGateMiddleware;
use app\service\upgrade\SimpleUpgradeGate;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class SimpleUpgradeGateMiddlewareTest extends TestCase
{
    private string $runDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('response')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }
        $this->runDirectory = sys_get_temp_dir() . '/mallbase-simple-middleware-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->runDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->runDirectory);
        parent::tearDown();
    }

    public function testBusinessRequestRunsOnlyWhileSimpleGateIsNormal(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);
        $middleware = new UpgradeTrafficGateMiddleware(simpleGate: $gate);

        $allowed = $middleware->handle(
            $this->request('client/api/goods/list'),
            static fn(): Response => response('allowed', 200),
        );
        self::assertSame(200, $allowed->getCode());
        self::assertSame('paused', $gate->drain(), 'request activity lease was not released');

        $blocked = $middleware->handle(
            $this->request('client/api/goods/list'),
            static fn(): Response => response('unexpected', 200),
        );
        self::assertSame(503, $blocked->getCode());
        self::assertStringContainsString('SYSTEM_MAINTENANCE', $blocked->getContent());
        self::assertStringContainsString('paused', $blocked->getContent());
    }

    public function testLegacyUpgradePathIsNotBypassedDuringDrain(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);
        $gate->drain();
        $middleware = new UpgradeTrafficGateMiddleware(simpleGate: $gate);

        $response = $middleware->handle(
            $this->request('upgrade/api/status'),
            static fn(): Response => response('upgrade-ok', 200),
        );

        self::assertSame(503, $response->getCode());
    }

    public function testAdminGateBlocksOrdinaryAdminButWhitelistsUpgradeEntry(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);
        $gate->drain();
        $middleware = new UpgradeAdminGateMiddleware(simpleGate: $gate);

        $blocked = $middleware->handle(
            $this->request('admin/api/system/info'),
            static fn(): Response => response('unexpected', 200),
        );
        $upgrade = $middleware->handle(
            $this->request('admin/api/system/upgrade/jobs', 'POST'),
            static fn(): Response => response('upgrade-admin-ok', 200),
        );
        $overview = $middleware->handle(
            $this->request('admin/api/system/upgrade/overview'),
            static fn(): Response => response('upgrade-overview-ok', 200),
        );
        $releases = $middleware->handle(
            $this->request('admin/api/system/upgrade/releases'),
            static fn(): Response => response('upgrade-releases-ok', 200),
        );

        self::assertSame(503, $blocked->getCode());
        self::assertStringContainsString('SYSTEM_MAINTENANCE', $blocked->getContent());
        self::assertSame(200, $upgrade->getCode());
        self::assertSame('upgrade-admin-ok', $upgrade->getContent());
        self::assertSame(200, $overview->getCode());
        self::assertSame('upgrade-overview-ok', $overview->getContent());
        self::assertSame(200, $releases->getCode());
        self::assertSame('upgrade-releases-ok', $releases->getContent());
    }

    public function testBusinessRequestFailsClosedWhenStateDocumentIsInvalid(): void
    {
        $gate = new SimpleUpgradeGate($this->runDirectory);
        file_put_contents($this->runDirectory . '/state.json', "{}\n");
        $middleware = new UpgradeTrafficGateMiddleware(simpleGate: $gate);

        $response = $middleware->handle(
            $this->request('client/api/goods/list'),
            static fn(): Response => response('unexpected', 200),
        );

        self::assertSame(503, $response->getCode());
        self::assertStringContainsString('unavailable', $response->getContent());
    }

    private function request(string $path, string $method = 'GET'): Request
    {
        return (new Request())->setPathinfo($path)->setMethod($method);
    }
}
