<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\controller\admin\upgrade\UpgradeController;
use app\service\admin\upgrade\UpgradeAdminService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\Container;
use think\Request;

final class UpgradeControllerResponseContractTest extends TestCase
{
    private Container $previousContainer;
    private App $app;
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('json')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }

        $this->previousContainer = Container::getInstance();
        $this->app = new App(dirname(__DIR__, 3));
        $this->request = new Request();
        $this->request->admin_id = 7;
        $this->app->instance('request', $this->request);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testInvalidJobArgumentsReturnTheFrontendBusinessStatus(): void
    {
        $this->assertCreateJobError('UPGRADE_ENTRY_ARGUMENT_INVALID');
    }

    public function testActiveJobConflictReturnsTheFrontendBusinessStatus(): void
    {
        $this->assertCreateJobError('UPGRADE_ENTRY_CONFLICT');
    }

    private function assertCreateJobError(string $reason): void
    {
        $this->request->withPost([
            'action' => 'upgrade',
            'target_version' => '1.2.0',
        ]);
        $this->app->instance(
            UpgradeAdminService::class,
            new UpgradeAdminServiceResponseFake($reason),
        );

        $response = (new UpgradeController($this->app))->createJob();
        $body = json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getCode(), $response->getContent());
        self::assertSame(400, $body['code'] ?? null);
        self::assertSame($reason, $body['data']['reason'] ?? null);
    }
}

final class UpgradeAdminServiceResponseFake
{
    public function __construct(private readonly string $reason)
    {
    }

    /** @return array<string, mixed> */
    public function createJob(int $adminId, mixed $action, mixed $targetVersion = ''): array
    {
        throw new RuntimeException($this->reason);
    }
}
