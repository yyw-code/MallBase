<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\TestCase;

final class UpgradeAdminRouteContractTest extends TestCase
{
    public function testAdminUpgradeMenuIsDirectChildOfSystem(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 3) . '/route/api/admin/upgrade.php');

        $this->assertMatchesRegularExpression("/'_parent'\\s*=>\\s*'System'/", $route);
        $this->assertDoesNotMatchRegularExpression("/'_parent'\\s*=>\\s*'SystemManagement'/", $route);
    }

    public function testRecordsRouteReusesPagePermissionWithoutCreatingAnotherPermissionNode(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 3) . '/route/api/admin/upgrade.php');
        $start = strpos($route, "Route::get('records'");
        $end = strpos($route, "Route::post('session'", (int) $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $records = substr($route, (int) $start, (int) $end - (int) $start);
        $this->assertStringContainsString("'_permission' => 'SystemUpgrade'", $records);
        $this->assertStringNotContainsString("'_alias'", $records);
        $this->assertSame(1, substr_count($route, "'_alias'"));
    }

    public function testOverviewRouteReusesPagePermission(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 3) . '/route/api/admin/upgrade.php');

        $this->assertStringContainsString("Route::get('overview', 'overview')", $route);
        $this->assertMatchesRegularExpression(
            "/Route::get\\('overview'.*?'_permission'\\s*=>\\s*'SystemUpgrade'/s",
            $route,
        );
    }

    public function testReleaseCatalogRouteReusesPagePermission(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 3) . '/route/api/admin/upgrade.php');

        $this->assertStringContainsString("Route::get('releases', 'releases')", $route);
        $this->assertMatchesRegularExpression(
            "/Route::get\\('releases'.*?'_permission'\\s*=>\\s*'SystemUpgrade'/s",
            $route,
        );
    }
}
