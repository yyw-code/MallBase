<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

/**
 * 验证 SettingService::rebuildAllPermissions() 在安装流程末尾直接同步菜单。
 *
 * 契约：
 * - 登录后通过 /auth/permission/menu 应能看到 "系统设置" 根菜单（code=SettingGroup:SystemSetting）
 * - "系统配置"/"上传配置"/"微信配置"/"支付配置" 这 4 个菜单存在
 * - ClientConfig 保留为设置数据源，但后台入口迁移到 "客户端装修 -> 客户端配置"，不再出现在系统设置菜单
 * - tab 子 page（如 SystemBasic / UploadBasic）不独立建菜单（共享父级路由）
 *
 * 幂等性：修复命令重复跑不会重复插入（通过 DB 中 SettingGroup:* 的 permission 条目总数验证，
 *   需要运维手动跑 `php think settings:sync-permissions` 后再次运行此测试，条数应不变）。
 *   本测试仅断言"单次安装后菜单存在"。
 */
final class SettingServiceRebuildPermissionsTest extends TestCase
{
    use ApiClientTrait;

    public function testSystemSettingMenusAreInjectedAfterInstall(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/auth/permission/menu',
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('permission/menu 未返回 200。');
        }

        $data = $response['data'] ?? [];
        $routes = is_array($data) ? ($data['routes'] ?? []) : [];
        $this->assertIsArray($routes);

        $allCodes = $this->collectRouteNames($routes);

        // 顶级 & 一级 page 分组必须有独立菜单（permission code 格式 SettingGroup:{group code}）
        $expectedMenuCodes = [
            'SettingGroup:SystemSetting',   // 顶级 category
            'SettingGroup:SystemConfig',    // tab 容器
            'SettingGroup:UploadConfig',
            'SettingGroup:WechatConfig',
            'SettingGroup:PaymentConfig',
        ];
        foreach ($expectedMenuCodes as $code) {
            $this->assertContains(
                $code,
                $allCodes,
                "菜单权限缺失：{$code}（rebuildAllPermissions 未生效）"
            );
        }

        $this->assertNotContains(
            'SettingGroup:ClientConfig',
            $allCodes,
            'ClientConfig 已迁移到客户端配置专属页面，不应继续出现在系统设置菜单'
        );

        // tab 下的子 page（SystemBasic / UploadBasic 等）**不应**出现独立菜单
        // （它们在 tab 页内部作为选项卡，共享父级路由）
        $tabChildren = [
            'SettingGroup:SystemBasic',
            'SettingGroup:UploadBasic',
            'SettingGroup:WechatMiniProgram',
            'SettingGroup:PaymentBasic',
        ];
        foreach ($tabChildren as $code) {
            $this->assertNotContains(
                $code,
                $allCodes,
                "tab 下的 page 子 {$code} 不应建独立菜单（应共享父 tab 菜单）"
            );
        }
    }

    /**
     * 递归收集菜单树中所有 name 字段（后端菜单 API 的 route.name 即 permission.code）
     *
     * @param array<int, array<string, mixed>> $routes
     * @return array<int, string>
     */
    private function collectRouteNames(array $routes): array
    {
        $names = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            if (isset($route['name'])) {
                $names[] = (string) $route['name'];
            }
            if (isset($route['children']) && is_array($route['children'])) {
                $names = array_merge($names, $this->collectRouteNames($route['children']));
            }
        }
        return $names;
    }
}
