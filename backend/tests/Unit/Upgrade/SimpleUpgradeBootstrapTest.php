<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\cron\CronManager;
use app\middleware\admin\UpgradeAdminGateMiddleware;
use app\middleware\UpgradeTrafficGateMiddleware;
use app\queue\UpgradeAwareWorker;
use app\service\upgrade\SimpleDatabaseSnapshotService;
use app\service\upgrade\SimpleSqlMigrationService;
use app\service\upgrade\SimpleUpgradeGate;
use app\service\upgrade\SimpleUpgradeRuntimeService;
use app\UpgradeService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Config;
use think\Container;
use think\Env;
use think\queue\Worker;

final class SimpleUpgradeBootstrapTest extends TestCase
{
    private Container $previousContainer;
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('config')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }
        $this->previousContainer = Container::getInstance();
        $this->temporaryRoot = sys_get_temp_dir() . '/mallbase-simple-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/install', 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryRoot . '/upgrade/run/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->temporaryRoot . '/upgrade/run');
        @rmdir($this->temporaryRoot . '/upgrade');
        @unlink($this->temporaryRoot . '/install/install.lock');
        @rmdir($this->temporaryRoot . '/install');
        @rmdir($this->temporaryRoot);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testInstalledConfigurationEnablesSimpleGateWithoutLegacyEnvironmentFlag(): void
    {
        $app = $this->application();
        touch($this->temporaryRoot . '/install/install.lock');

        $configuration = require dirname(__DIR__, 3) . '/config/upgrade.php';

        self::assertTrue($configuration['simple_gate_enabled'] ?? false);
        self::assertArrayNotHasKey('enabled', $configuration);
        self::assertSame('/usr/bin/mariadb', $configuration['restore_executable'] ?? null);
    }

    public function testServiceBindsSimpleGateAndQueueWorker(): void
    {
        $app = $this->application();
        config(['simple_gate_enabled' => true], 'upgrade');
        config(['upgrade_root' => $this->temporaryRoot . '/upgrade'], 'agent');

        (new UpgradeService($app))->register();

        self::assertTrue($app->bound(SimpleUpgradeGate::class));
        self::assertTrue($app->bound(Worker::class));
        self::assertTrue($app->bound(UpgradeTrafficGateMiddleware::class));
        self::assertTrue($app->bound(UpgradeAdminGateMiddleware::class));
        self::assertTrue($app->bound(CronManager::class));
        self::assertFalse($app->bound('app\\middleware\\upgrade\\SimpleUpgradeAuthMiddleware'));
        self::assertTrue($app->bound(SimpleDatabaseSnapshotService::class));
        self::assertTrue($app->bound(SimpleSqlMigrationService::class));
        self::assertTrue($app->bound(SimpleUpgradeRuntimeService::class));
        self::assertInstanceOf(SimpleUpgradeGate::class, $app->make(SimpleUpgradeGate::class));
        self::assertInstanceOf(UpgradeAwareWorker::class, $app->make(Worker::class));
    }

    private function application(): App
    {
        $app = new App(dirname(__DIR__, 3));
        $app->setRuntimePath($this->temporaryRoot . DIRECTORY_SEPARATOR);
        $config = new Config();
        $config->set(['default' => 'sync'], 'queue');
        $app->instance('config', $config);
        $env = new Env();
        $app->instance('env', $env);

        return $app;
    }
}
