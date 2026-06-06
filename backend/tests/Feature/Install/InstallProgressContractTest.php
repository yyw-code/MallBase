<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallProgressContractTest extends TestCase
{
    public function testRegionImportReportsStreamingProgressDuringInstall(): void
    {
        $root = dirname(__DIR__, 3);
        $installService = (string) file_get_contents($root . '/app/service/install/InstallService.php');
        $regionService = (string) file_get_contents($root . '/app/service/RegionImportService.php');

        $this->assertStringContainsString('?callable $progress = null', $regionService);
        $this->assertStringContainsString('private function countNodes(array $nodes): int', $regionService);
        $this->assertStringContainsString("'processed' => \$processed", $regionService);
        $this->assertStringContainsString("'percent'   => \$total > 0 ? (int) floor(\$processed * 100 / \$total) : 100", $regionService);
        $this->assertStringContainsString("function (array \$progress) use (&\$regionProgress, \$emit): void", $installService);
        $this->assertStringContainsString("'progress' => \$progress", $installService);
        $this->assertStringContainsString('正在导入地区数据：%d/%d（%d%%），新增 %d，更新 %d', $installService);
    }

    public function testInstallPageShowsProgressMeterAndProtectsRunningInstallFromRefresh(): void
    {
        $installPage = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');

        $this->assertStringContainsString('progress-meter', $installPage);
        $this->assertStringContainsString('event.progress || (event.percent !== undefined ? event : null)', $installPage);
        $this->assertStringContainsString('meterBar.style.width', $installPage);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $installPage);
        $this->assertStringContainsString('installRunning', $installPage);
    }

    public function testInstallPageProvidesHostQuickFillButtons(): void
    {
        $installPage = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');

        $this->assertStringContainsString("fillHostValue('db_host', 'db', 'mysql')", $installPage);
        $this->assertStringContainsString("fillHostValue('db_host', 'db', 'host.docker.internal')", $installPage);
        $this->assertStringContainsString("fillHostValue('db_host', 'db', '127.0.0.1')", $installPage);
        $this->assertStringContainsString("fillHostValue('redis_host', 'redis', 'redis')", $installPage);
        $this->assertStringContainsString("fillHostValue('redis_host', 'redis', 'host.docker.internal')", $installPage);
        $this->assertStringContainsString("fillHostValue('redis_host', 'redis', '127.0.0.1')", $installPage);
        $this->assertStringContainsString('function fillHostValue(inputId, validationType, value)', $installPage);
    }

    public function testDemoStaticCopyMessageUsesExistingInsteadOfSkippedForInstalledFiles(): void
    {
        $installService = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("'existing'       => 0", $installService);
        $this->assertStringContainsString("\$result['existing']++", $installService);
        $this->assertStringContainsString('演示静态资源就绪（新增 %d，已存在 %d）', $installService);
        $this->assertStringNotContainsString('演示静态资源就绪（新增 %d，跳过 %d）', $installService);
    }
}
