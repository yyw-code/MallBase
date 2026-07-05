<?php

declare(strict_types=1);

namespace Tests\Unit\Route;

use PHPUnit\Framework\TestCase;

final class DistributionRoutePermissionContractTest extends TestCase
{
    public function testDistributionOverviewAndSettingsAreSeparatedMenus(): void
    {
        $root = dirname(__DIR__, 3);
        $permissions = (string) file_get_contents($root . '/config/permissions.php');
        $route = (string) file_get_contents($root . '/route/api/admin/distribution.php');

        $this->assertStringContainsString("'code' => 'SystemDistributionManagement'", $permissions);
        $this->assertStringContainsString("'redirect' => '/distribution'", $permissions);

        $this->assertStringContainsString("'_group_name'      => '分销概览'", $route);
        $this->assertStringContainsString("'_group_code'      => 'SystemDistribution'", $route);
        $this->assertStringContainsString("'_path'            => '/distribution'", $route);
        $this->assertStringContainsString("'_component'       => '/distribution/index'", $route);

        $this->assertStringContainsString("'_group_name'      => '分销基础设置'", $route);
        $this->assertStringContainsString("'_group_code'      => 'SystemDistributionSettings'", $route);
        $this->assertStringContainsString("'_path'            => '/distribution/settings'", $route);
        $this->assertStringContainsString("'_component'       => '/distribution/settings/index'", $route);

        $this->assertStringContainsString("name('SystemDistributionSettingsInfo')", $route);
        $this->assertStringContainsString("name('SystemDistributionSettingsSave')", $route);
    }
}
