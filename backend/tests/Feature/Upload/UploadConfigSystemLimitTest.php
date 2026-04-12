<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use PHPUnit\Framework\TestCase;

final class UploadConfigSystemLimitTest extends TestCase
{
    public function testUploadConfigContainsSystemLimitsAndClampWarnings(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过上传配置系统上限测试。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/config/uploadConfig',
            ['type' => 'videos'],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('上传配置接口不可达，跳过测试。');
        }

        $this->assertSame(200, $response['code'] ?? null);
        $data = $response['data'] ?? [];
        $this->assertIsArray($data);
        $this->assertArrayHasKey('max_size', $data);
        $this->assertArrayHasKey('max_count', $data);
        $this->assertArrayHasKey('accept_types', $data);

        $systemLimits = $data['system_limits'] ?? null;
        $this->assertIsArray($systemLimits, '应返回 system_limits');
        $this->assertArrayHasKey('php_upload_max_filesize_mb', $systemLimits);
        $this->assertArrayHasKey('php_post_max_size_mb', $systemLimits);
        $this->assertArrayHasKey('php_max_file_uploads', $systemLimits);
        $this->assertArrayHasKey('effective_max_size_mb', $systemLimits);
        $this->assertArrayHasKey('effective_max_count', $systemLimits);

        $uploadMb = $systemLimits['php_upload_max_filesize_mb'] ?? null;
        $postMb = $systemLimits['php_post_max_size_mb'] ?? null;
        $effectiveMb = $systemLimits['effective_max_size_mb'] ?? null;

        if (is_numeric($uploadMb) && is_numeric($postMb) && is_numeric($effectiveMb)) {
            $this->assertEquals(min((float)$uploadMb, (float)$postMb), (float)$effectiveMb, '', 0.0001);
        }

        $warnings = $data['warnings'] ?? [];
        $this->assertIsArray($warnings);

        // videos 在配置中为 200MB / 5，若被系统上限截断应带 Nginx 提示
        $wasClamped = ((float)($data['max_size'] ?? 0)) < 200.0 || ((int)($data['max_count'] ?? 0)) < 5;
        if ($wasClamped) {
            $warningText = implode(' ', array_map(static fn($w) => (string)$w, $warnings));
            $this->assertStringContainsString('client_max_body_size', $warningText);
        }
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

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $token = $data['access_token'] ?? null;
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

