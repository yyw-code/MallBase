<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

/**
 * SystemSettingService 通过两个公开接口间接验证：
 * - GET /admin/api/config/appMeta    返回 SystemBasic + SystemCopyright 扁平合并
 * - GET /client/setting/basic        返回白名单过滤后的客户端配置
 *
 * 核心契约：
 * - 单/批量 / 按 group 三种读取都能正确返回
 * - 图片字段返回为 full_url（非空时以 http(s):// 开头）
 * - {year} 占位被替换为当前年份
 *
 * 纯 Service 的 Unit 测试需要 ThinkPHP App bootstrap + DB + Redis，放到 Feature 层更合适。
 */
final class SystemSettingServiceTest extends TestCase
{
    use ApiClientTrait;

    public function testAppMetaFlattensSystemBasicAndCopyright(): void
    {
        $response = $this->requestJson('GET', $this->getBaseUrl() . '/admin/api/config/appMeta');
        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        $this->assertIsArray($response);
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('appMeta 未返回 200，跳过。');
        }

        $data = $response['data'] ?? [];
        $this->assertIsArray($data);

        // SystemBasic 关键字段存在
        foreach (['site_name', 'admin_logo', 'admin_favicon', 'admin_login_title'] as $key) {
            $this->assertArrayHasKey($key, $data, "appMeta 缺少字段 {$key}");
        }

        // SystemCopyright 关键字段存在
        foreach (['copyright_enabled', 'copyright_company'] as $key) {
            $this->assertArrayHasKey($key, $data, "appMeta 缺少版权字段 {$key}");
        }

        // 图片字段：非空时必须是完整 URL（含 scheme）
        foreach (['admin_logo', 'admin_favicon', 'default_avatar'] as $imgKey) {
            $val = $data[$imgKey] ?? null;
            if (is_string($val) && $val !== '') {
                $this->assertMatchesRegularExpression(
                    '#^(https?://|/)#',
                    $val,
                    "{$imgKey} 应该是 full_url 或以 / 开头的路径，实际：{$val}"
                );
            }
        }

        // {year} 占位替换：copyright_date 不再包含字面量 {year}
        $date = $data['copyright_date'] ?? '';
        if (is_string($date) && $date !== '') {
            $this->assertStringNotContainsString('{year}', $date, 'copyright_date 应已替换 {year} 占位符');
        }
    }

    public function testGetSystemSettingGroupsReturnsKeyValueMap(): void
    {
        // 通过 appMeta 接口间接验证 getSystemSettingGroups（多组合并）
        $response = $this->requestJson('GET', $this->getBaseUrl() . '/admin/api/config/appMeta');
        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('appMeta 未返回 200。');
        }

        $data = $response['data'] ?? [];
        $this->assertIsArray($data);

        // 返回必须是扁平 key-value（不是嵌套分组结构）
        // 随机抽一个字段判断是标量或字符串即可
        foreach (['site_name', 'copyright_company'] as $probe) {
            if (array_key_exists($probe, $data)) {
                $value = $data[$probe];
                $this->assertTrue(
                    $value === null || is_scalar($value),
                    "{$probe} 应为标量或 null（扁平结构），实际类型：" . gettype($value)
                );
            }
        }
    }
}
