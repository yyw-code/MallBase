<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use PHPUnit\Framework\TestCase;

final class UpgradeSecurityHeadersTest extends TestCase
{
    public function testAdminEntryUsesHashedTicketAndDoesNotCreatePhpUpgradeCookie(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/controller/admin/upgrade/UpgradeController.php',
        );
        $model = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/model/upgrade/UpgradeRecord.php',
        );
        $service = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/service/admin/upgrade/UpgradeAdminService.php',
        );

        $this->assertStringNotContainsString('->cookie(', $controller);
        $this->assertStringContainsString("hash('sha256', \$ticket)", $service);
        $this->assertStringContainsString("'ticket_hash' => hash('sha256', \$ticket)", $service);
        $this->assertStringNotContainsString("'ticket' => \$ticket", $service);
        $this->assertStringContainsString("'Referrer-Policy' => 'no-referrer'", $controller);
        $this->assertStringContainsString("'run'", $model);
        $this->assertStringContainsString("'requests'", $model);
    }

    public function testAdminEntryControllerLogsOnlyTheMappedDiagnosticCode(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/controller/admin/upgrade/UpgradeController.php',
        );

        $this->assertStringContainsString("'upgrade admin request failed: ' . \$reason", $controller);
        $this->assertStringNotContainsString("'exception' => \$exception", $controller);
    }

    public function testAdminEntryDoesNotDependOnTheLegacyRuntimeEnableFlag(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/controller/admin/upgrade/UpgradeController.php',
        );
        self::assertStringNotContainsString("config('upgrade.enabled', false)", $controller);
        self::assertStringNotContainsString('UpgradeControlRateLimiter', $controller);
        self::assertStringContainsString('createJob', $controller);
    }
}
