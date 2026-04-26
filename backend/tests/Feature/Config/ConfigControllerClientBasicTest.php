<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

/**
 * Client /client/api/setting/basic 公开接口契约测试（敏感字段过滤）。
 *
 * 硬约束（失败即视为安全回归，必须红）：
 * - 响应 JSON 字符串中**不应出现任何**下列前缀的字段 key：
 *     wechat_*、pay_*、upload_*、jwt_*、oss_*、cos_*、admin_*、site_url、default_avatar
 * - SystemBasic 只能输出白名单：site_name / site_slogan
 * - ClientConfig / SystemCopyright 全组放通
 *
 * 参见 backend/app/service/client/ConfigService.php::PUBLIC_GROUPS / SYSTEM_BASIC_PUBLIC_FIELDS
 */
final class ConfigControllerClientBasicTest extends TestCase
{
    use ApiClientTrait;

    public function testClientBasicIsPublicAndContainsExpectedClientFields(): void
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/setting/basic'
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        $this->assertIsArray($response);
        if (($response['code'] ?? null) !== 200) {
            $this->fail(sprintf(
                '/client/api/setting/basic 应为公开路由，预期 code=200，实际：%s',
                var_export($response['code'] ?? null, true)
            ));
        }

        $data = $response['data'] ?? [];
        $this->assertIsArray($data);

        foreach (['client_site_name', 'client_logo', 'client_home_banners'] as $key) {
            $this->assertArrayHasKey($key, $data, "client basic 缺少字段 {$key}");
        }
    }

    public function testClientBasicRejectsSensitiveFields(): void
    {
        $raw = $this->requestJsonRaw(
            'GET',
            $this->getBaseUrl() . '/client/api/setting/basic'
        );

        if ($raw === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        // 硬断言：原始 JSON 字符串中不得含任何敏感字段 key
        $forbiddenPatterns = [
            // 各 "{prefix}_" 前缀
            '/"wechat_[a-zA-Z_]+"\s*:/',
            '/"pay_[a-zA-Z_]+"\s*:/',
            '/"upload_[a-zA-Z_]+"\s*:/',
            '/"jwt_[a-zA-Z_]+"\s*:/',
            '/"oss_[a-zA-Z_]+"\s*:/',
            '/"cos_[a-zA-Z_]+"\s*:/',
            '/"admin_[a-zA-Z_]+"\s*:/',
            // 显式禁止字段
            '/"site_url"\s*:/',
            '/"default_avatar"\s*:/',
            '/"mime_[a-zA-Z_]+"\s*:/',
            '/"local_[a-zA-Z_]+"\s*:/',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $raw,
                "client basic 响应泄露敏感字段，匹配 pattern：{$pattern}"
            );
        }
    }

    public function testClientBasicCopyrightYearPlaceholderReplaced(): void
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/setting/basic'
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('/client/api/setting/basic 未返回 200。');
        }

        $data = $response['data'] ?? [];
        $date = $data['copyright_date'] ?? '';
        if (is_string($date) && $date !== '') {
            $this->assertStringNotContainsString('{year}', $date, '客户端 copyright_date 应已替换 {year}');
        }
    }
}
