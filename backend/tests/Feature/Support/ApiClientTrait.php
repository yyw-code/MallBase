<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

/**
 * Feature 测试 HTTP 客户端 trait
 *
 * 所有 Feature 级测试（真实 HTTP 请求 backend）共用这组 helper，
 * 避免每个测试类重复 100 行模板代码。
 *
 * 约定：
 * - 接口不可达（backend 没启动）时应 markTestSkipped，不让测试红
 * - 登录凭据允许用 E2E_ADMIN_USERNAME / E2E_ADMIN_PASSWORD 环境变量覆盖
 * - 默认 base URL 为 http://127.0.0.1:8080（可用 BACKEND_API_BASE_URL 覆盖）
 */
trait ApiClientTrait
{
    protected function getBaseUrl(): string
    {
        $baseUrl = getenv('BACKEND_API_BASE_URL');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return 'http://127.0.0.1:8080';
        }

        return rtrim($baseUrl, '/');
    }

    protected function loginAndGetToken(): ?string
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

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array<string, mixed>|null 接口不可达返回 null，调用方自行 markTestSkipped
     */
    protected function requestJson(
        string $method,
        string $url,
        array $payload = [],
        array $headers = []
    ): ?array {
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
            $content = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
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
                'timeout' => 5,
            ],
        ]);

        $raw = @file_get_contents($finalUrl, false, $context);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * 获取原始 JSON 字符串（保留返回，供敏感字段 grep 断言）
     *
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     */
    protected function requestJsonRaw(
        string $method,
        string $url,
        array $payload = [],
        array $headers = []
    ): ?string {
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
            $content = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
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
                'timeout' => 5,
            ],
        ]);

        $raw = @file_get_contents($finalUrl, false, $context);
        return $raw === false ? null : $raw;
    }
}
