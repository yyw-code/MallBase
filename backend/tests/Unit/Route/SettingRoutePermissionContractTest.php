<?php

declare(strict_types=1);

namespace Tests\Unit\Route;

use PHPUnit\Framework\TestCase;

final class SettingRoutePermissionContractTest extends TestCase
{
    public function testSaveConfigUsesSettingGroupPermission(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 3) . '/route/api/admin/setting.php');

        $this->assertStringContainsString("Route::post('saveConfig/:groupCode'", $route);
        $this->assertStringContainsString("'_auth'              => true", $route);
        $this->assertStringContainsString("'_permission_param'  => 'groupCode'", $route);
        $this->assertStringContainsString("'_permission_prefix' => 'SettingGroup:'", $route);
        $this->assertStringNotContainsString("saveConfig/:groupCode', 'saveConfig')->name('SettingSaveConfig')->option(['_alias' => '保存配置', '_desc' => '保存分组配置', '_auth' => false]", $route);
    }
}
