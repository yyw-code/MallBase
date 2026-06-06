<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallRestartCommandContractTest extends TestCase
{
    public function testRestartCommandsDoNotExposeLocalAbsoluteProjectPath(): void
    {
        $serviceSource = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');
        $pageSource = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');

        $this->assertStringNotContainsString('project_root', $serviceSource);
        $this->assertStringNotContainsString('backend_root', $serviceSource);
        $this->assertStringNotContainsString('cd {$projectRoot}', $serviceSource);
        $this->assertStringNotContainsString('cd {$backendRoot}', $serviceSource);
        $this->assertStringContainsString("'docker_dev' => 'docker compose -f docker-compose.dev.yml restart backend'", $serviceSource);
        $this->assertStringContainsString("'docker_prod' => 'docker compose restart'", $serviceSource);
        $this->assertStringContainsString('cd backend', $serviceSource);

        $this->assertStringNotContainsString('projectRootDev', $pageSource);
        $this->assertStringNotContainsString('projectRootProd', $pageSource);
        $this->assertStringNotContainsString('meta.project_root', $pageSource);
        $this->assertStringContainsString('在项目根目录执行，也就是包含 <code>docker-compose.dev.yml</code> 的目录。', $pageSource);
    }
}
