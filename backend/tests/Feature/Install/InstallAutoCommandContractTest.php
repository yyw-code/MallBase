<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallAutoCommandContractTest extends TestCase
{
    public function testInstallAutoPassesProgressCallbackToInstallService(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/command/InstallAuto.php');
        $serviceSource = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("->addOption('demo', null, Option::VALUE_NONE, '导入演示数据和演示静态资源')", $source);
        $this->assertStringContainsString("\$params['import_demo'] = (bool) \$input->getOption('demo');", $source);
        $this->assertStringContainsString('$service->execute($params, function (array $event) use ($output): void {', $source);
        $this->assertStringContainsString('formatProgressEvent', $source);
        $this->assertStringContainsString("'copy_demo_static'", $source);
        $this->assertStringContainsString("'copy_demo_static'         => '拷贝演示静态资源'", $serviceSource);
    }

    public function testInstallAutoExplainsEnvSourceAndRequiresSiteUrl(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/command/InstallAuto.php');

        $this->assertStringContainsString('env 来源：', $source);
        $this->assertStringContainsString("'site_url'   => 'SITE_URL'", $source);
        $this->assertStringContainsString('未发现安装 env 文件，将仅使用主机和端口默认值', $source);
    }

    public function testInstallAutoPrintsSuccessSummary(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/command/InstallAuto.php');

        $this->assertStringContainsString('printSuccessSummary($params, $output);', $source);
        $this->assertStringContainsString('基本信息：', $source);
        $this->assertStringContainsString('管理员账号：', $source);
        $this->assertStringContainsString('管理员密码：', $source);
        $this->assertStringContainsString('演示数据：', $source);
        $this->assertStringContainsString('管理后台：', $source);
        $this->assertStringContainsString('客户端入口：', $source);
        $this->assertStringContainsString('安装完成后请尽快修改默认管理员密码。', $source);
        $this->assertStringContainsString('安装完成后请重启 Swoole，让新配置和安装锁生效。', $source);
    }
}
