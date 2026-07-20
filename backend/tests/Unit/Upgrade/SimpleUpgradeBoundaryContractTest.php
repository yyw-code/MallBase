<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\TestCase;

final class SimpleUpgradeBoundaryContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function testLocalHttpAndHmacBoundaryIsRemoved(): void
    {
        foreach ([
            'route/upgrade.php',
            'app/controller/upgrade/SimpleUpgradeController.php',
            'app/middleware/upgrade/SimpleUpgradeAuthMiddleware.php',
        ] as $path) {
            self::assertFileDoesNotExist($this->root . '/' . $path, $path);
        }

        $service = $this->read('app/UpgradeService.php');
        $trafficGate = $this->read('app/middleware/UpgradeTrafficGateMiddleware.php');
        self::assertStringNotContainsString('SimpleUpgradeAuthMiddleware', $service);
        self::assertStringNotContainsString('upgrade/api/simple', $trafficGate);
        self::assertStringNotContainsString('isSimpleUpgradePath', $trafficGate);
    }

    public function testOnlyTheFixedUpgradeRuntimeCommandIsRegistered(): void
    {
        $console = $this->read('config/console.php');
        self::assertStringContainsString("'upgrade:runtime' => UpgradeRuntimeCommand::class", $console);
        self::assertStringContainsString('use app\\command\\UpgradeRuntimeCommand;', $console);
    }

    public function testPhpProductionCodeDoesNotOwnAPlatformDomain(): void
    {
        $paths = [
            ...glob($this->root . '/app/**/*.php') ?: [],
            ...glob($this->root . '/app/**/**/*.php') ?: [],
            ...glob($this->root . '/config/*.php') ?: [],
        ];
        foreach (array_unique($paths) as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('platform.gosowong.cn', $source, $path);
            self::assertStringNotContainsString('PLATFORM_BASE_URL', $source, $path);
        }
    }

    public function testReleaseCatalogHasNoPhpPlatformOriginOrHttpRequester(): void
    {
        $catalog = $this->read('app/service/admin/upgrade/PlatformReleaseCatalogService.php');
        $upgrade = $this->read('config/upgrade.php');

        foreach (['MALLBASE_PLATFORM_CATALOG_ORIGIN', 'catalog_origin'] as $legacy) {
            self::assertStringNotContainsString($legacy, $catalog . $upgrade);
        }
        foreach (['CURLOPT_', '/api/v1/releases', 'normalizeOrigin', 'http_build_query'] as $http) {
            self::assertStringNotContainsString($http, $catalog);
        }

        $documentation = $this->read('../docs/install/upgrade-agent.md');
        foreach ([
            'active/mallbase-agent catalog',
            'stdin 为空',
            '无额外参数',
            'PHP 不配置、接收、持久化或拼接 Platform 域名',
            '262144',
            'catalog 不代替 resolve',
            'instance token',
            '`package_kind=full`',
        ] as $contract) {
            self::assertStringContainsString($contract, $documentation);
        }
    }

    private function read(string $relative): string
    {
        $source = file_get_contents($this->root . '/' . $relative);
        self::assertIsString($source);

        return $source;
    }
}
