<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use PHPUnit\Framework\TestCase;

final class UploadRuleResolveClampTest extends TestCase
{
    public function testSettingFormConfigShouldExposeValueMaxAndHintForUploadRules(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过规则上限注入测试。');
        }

        $formConfig = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/form/config',
            [],
            ["Authorization: Bearer {$token}"]
        );
        if (!is_array($formConfig) || ($formConfig['code'] ?? null) !== 200) {
            $this->markTestSkipped('表单配置接口不可达，跳过测试。');
        }

        $uploadConfig = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/config/uploadConfig',
            ['type' => 'files'],
            ["Authorization: Bearer {$token}"]
        );
        if (!is_array($uploadConfig) || ($uploadConfig['code'] ?? null) !== 200) {
            $this->markTestSkipped('上传配置接口不可达，跳过测试。');
        }

        $formWarnings = $formConfig['data']['warnings'] ?? [];
        $this->assertIsArray($formWarnings);

        $fileRules = $formConfig['data']['rule_types']['files'] ?? [];
        $this->assertIsArray($fileRules);

        $maxSizeRule = $this->findRuleByType($fileRules, 'max_size');
        $maxCountRule = $this->findRuleByType($fileRules, 'max_count');
        $this->assertIsArray($maxSizeRule);
        $this->assertIsArray($maxCountRule);

        $this->assertArrayHasKey('value_max', $maxSizeRule);
        $this->assertArrayHasKey('value_max', $maxCountRule);
        $this->assertArrayHasKey('hint', $maxSizeRule);
        $this->assertArrayHasKey('hint', $maxCountRule);
        $this->assertIsString($maxSizeRule['hint']);
        $this->assertIsString($maxCountRule['hint']);
        $this->assertStringContainsString('client_max_body_size', $maxSizeRule['hint']);
        $this->assertStringContainsString('client_max_body_size', $maxCountRule['hint']);

        $effectiveSize = $uploadConfig['data']['system_limits']['effective_max_size_mb'] ?? null;
        $effectiveCount = $uploadConfig['data']['system_limits']['effective_max_count'] ?? null;

        if (is_numeric($effectiveSize)) {
            $this->assertEquals((float)$effectiveSize, (float)$maxSizeRule['value_max'], '', 0.0001);
        }
        if (is_numeric($effectiveCount)) {
            $this->assertSame((int)$effectiveCount, (int)$maxCountRule['value_max']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function findRuleByType(array $rules, string $type): ?array
    {
        foreach ($rules as $rule) {
            if (($rule['type'] ?? null) === $type) {
                return $rule;
            }
        }

        return null;
    }

    private function loginAndGetToken(): ?string
    {
        $username = getenv('E2E_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('E2E_ADMIN_PASSWORD') ?: '123123';

        $response = $this->requestJson(
            'POST',
            $this->getBaseUrl() . '/admin/api/auth/admin/login',
            [
                'username' => $username,
                'password' => $password,
            ]
        );

        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $token = $response['data']['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function getBaseUrl(): string
    {
        $baseUrl = getenv('BACKEND_API_BASE_URL');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return 'http://127.0.0.1:8080';
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestJson(string $method, string $url, array $payload = [], array $headers = []): ?array
    {
        $finalUrl = $url;
        $method = strtoupper($method);

        $headerLines = ['Accept: application/json'];

        if ($method === 'GET' && !empty($payload)) {
            $query = http_build_query($payload);
            $finalUrl .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        $content = '';
        if ($method !== 'GET') {
            $headerLines[] = 'Content-Type: application/json';
            $content = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        foreach ($headers as $header) {
            $headerLines[] = $header;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 6,
            ],
        ]);

        $raw = @file_get_contents($finalUrl, false, $context);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->fail('接口响应不是合法 JSON。');
        }

        return $decoded;
    }
}

