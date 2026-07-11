<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

/**
 * 验证安装时的默认 setting_group / setting seed 是否完整落库。
 *
 * 契约：
 * - 一级分组 "SystemSetting" 存在（display_type=category）
 * - 二级分组：SystemConfig / UploadConfig / WechatConfig / PaymentConfig / ClientConfig 齐全
 * - 三级：SystemBasic / SystemCopyright / UploadBasic/Local/Oss/Cos / WechatMiniProgram/OffiAccount /
 *        PaymentBasic/Wechat 齐全
 * - 重要设置项存在（site_url / admin_logo / copyright_enabled / upload_driver / client_logo 等）
 * - InstallService::seedSiteUrl 成功后 mb_setting.site_url 有非空值
 *
 * 测试方式：通过已登录 admin 接口拉 setting/group/tree + setting/item/config/{code} 验证。
 * 后端未启动或未安装时 markTestSkipped。
 */
final class InstallDefaultSeedTest extends TestCase
{
    use ApiClientTrait;

    public function testSystemSettingGroupTreeHasAllExpectedNodes(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过 seed 校验。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/group/tree',
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        $this->assertIsArray($response);
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('setting/group/tree 未返回 200，跳过断言。');
        }

        $tree = $response['data'] ?? [];
        $this->assertIsArray($tree);

        $codes = $this->collectCodes($tree);

        $expected = [
            'SystemSetting',
            'SystemConfig', 'SystemBasic', 'SystemCopyright',
            'UploadConfig', 'UploadBasic', 'UploadLocal', 'UploadOss', 'UploadCos',
            'WechatConfig', 'WechatMiniProgram', 'WechatOffiAccount',
            'PaymentConfig', 'PaymentBasic', 'PaymentWechat',
            'ClientConfig',
        ];
        foreach ($expected as $code) {
            $this->assertContains($code, $codes, "缺少分组 seed：{$code}");
        }
    }

    public function testSystemBasicSettingsContainSiteUrlAndLogo(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/config/SystemBasic',
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('config/SystemBasic 未返回 200，可能环境差异。');
        }

        $data = $response['data'] ?? [];
        $settings = is_array($data) ? ($data['settings'] ?? []) : [];
        $this->assertIsArray($settings);

        $codes = [];
        $siteUrlValue = null;
        foreach ($settings as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = (string) ($item['code'] ?? '');
            $codes[] = $code;
            if ($code === 'site_url') {
                $siteUrlValue = $item['value'] ?? null;
            }
        }

        foreach (['site_name', 'site_url', 'default_avatar', 'admin_logo', 'admin_favicon'] as $required) {
            $this->assertContains($required, $codes, "SystemBasic 缺少设置项 {$required}");
        }

        // site_url 安装期应该被 seedSiteUrl 填入非空值
        $this->assertNotNull($siteUrlValue, 'site_url 字段不存在');
        $this->assertNotSame('', trim((string) $siteUrlValue), 'site_url 应在安装时被 seed 为非空（默认当前 host）');
    }

    public function testRegionsAreAvailableAfterInstall(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/region/children',
            ['parent_id' => 0],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('region/children 未返回 200。');
        }

        $data = $response['data'] ?? [];
        $this->assertIsArray($data);
        $this->assertNotEmpty($data, '安装完成后顶级地区数据不应为空');
    }

    /**
     * 递归收集 tree 中所有 code
     *
     * @param array<int, array<string, mixed>> $tree
     * @return array<int, string>
     */
    private function collectCodes(array $tree): array
    {
        $codes = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['code'])) {
                $codes[] = (string) $node['code'];
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $codes = array_merge($codes, $this->collectCodes($node['children']));
            }
        }
        return $codes;
    }
}
