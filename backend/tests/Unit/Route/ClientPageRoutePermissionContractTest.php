<?php

declare(strict_types=1);

namespace Tests\Unit\Route;

use PHPUnit\Framework\TestCase;

final class ClientPageRoutePermissionContractTest extends TestCase
{
    public function testClientPageMenusAreSeparatedUnderPageLibrary(): void
    {
        $root = dirname(__DIR__, 3);
        $permissions = (string) file_get_contents($root . '/config/permissions.php');
        $route = (string) file_get_contents($root . '/route/api/admin/client.php');
        $pageView = (string) file_get_contents(dirname(__DIR__, 4) . '/frontend/admin/apps/web-antd/src/views/client/page/index.vue');
        $categoryView = (string) file_get_contents(dirname(__DIR__, 4) . '/frontend/admin/apps/web-antd/src/views/client/page/category/index.vue');

        $this->assertStringContainsString("'code' => 'SystemClientPageManagement'", $permissions);
        $this->assertStringContainsString("'redirect' => '/client/page/list'", $permissions);

        $this->assertStringContainsString("'_group_name' => '页面列表'", $route);
        $this->assertStringContainsString("'_parent' => 'SystemClientPageManagement'", $route);
        $this->assertStringContainsString("'_path' => '/client/page/list'", $route);
        $this->assertStringContainsString("'_component' => '/client/page/index'", $route);
        $this->assertStringContainsString("name('SystemClientPageList')->option(['_alias' => '页面列表', '_desc' => '获取客户端页面列表', '_auth' => true, '_type' => Permission::TYPE_MENU])", $route);
        $this->assertStringContainsString("name('SystemClientPageInfo')->option(['_alias' => '页面详情', '_desc' => '获取客户端页面详情', '_auth' => true, '_type' => Permission::TYPE_MENU])", $route);
        $this->assertStringContainsString("name('SystemClientPagePicker')->option(['_alias' => '页面链接选择', '_desc' => '获取客户端页面链接选择列表', '_auth' => true, '_type' => Permission::TYPE_MENU])", $route);

        $this->assertStringContainsString("'_group_name' => '页面分类'", $route);
        $this->assertStringContainsString("'_path' => '/client/page/category'", $route);
        $this->assertStringContainsString("'_component' => '/client/page/category/index'", $route);

        $categoryMenuStart = strpos($route, "'_group_code' => 'SystemClientPageCategory'");
        $nextGroupStart = strpos($route, "Route::group('client/decorate/scheme'", (int) $categoryMenuStart);
        $this->assertIsInt($categoryMenuStart);
        $this->assertIsInt($nextGroupStart);
        $categoryMenu = substr($route, $categoryMenuStart, $nextGroupStart - $categoryMenuStart);
        $this->assertStringNotContainsString("'_is_show' => 0", $categoryMenu);

        $this->assertStringContainsString('v-access:code="\'SystemClientPageCreate\'"', $pageView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageImport\'"', $pageView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageUpdate\'"', $pageView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageDelete\'"', $pageView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageCategoryCreate\'"', $categoryView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageCategoryUpdate\'"', $categoryView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageCategoryDelete\'"', $categoryView);
        $this->assertStringContainsString('v-access:code="\'SystemClientPageCategoryUpdateStatus\'"', $categoryView);
    }

    public function testClientPageRoleUpgradeGrantsSuperAdmin(): void
    {
        $path = dirname(__DIR__, 3) . '/install/data/upgrade/2026_07_05_client_page_role_permissions.sql';
        if (!is_file($path)) {
            $this->markTestSkipped('本地升级 SQL 未生成，跳过 git-ignored 运维产物校验。');
        }

        $upgrade = (string) file_get_contents($path);
        $this->assertStringContainsString("`r`.`code` = 'super_admin'", $upgrade);
        $this->assertStringContainsString("`p`.`code` LIKE 'SystemClientPage%'", $upgrade);
        $this->assertStringContainsString('INSERT IGNORE INTO `mb_role_permission`', $upgrade);
    }
}
