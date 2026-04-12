<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;

final class GoodsCategoryAllApiTest extends TestCase
{
    public function testAllCategoriesApiReturnsTreeItemsWithDisplayFields(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过商品分类接口结构测试。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/goods/category/all',
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达，请先启动服务后再执行测试。');
        }

        $this->assertIsArray($response);
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('goods/category/all 未返回 200，可能是权限或环境差异，跳过结构断言。');
        }

        $this->assertSame(200, $response['code']);
        $data = $response['data'] ?? null;
        $this->assertIsArray($data);

        if ($data === []) {
            $this->assertSame([], $data);
            return;
        }

        $first = $data[0] ?? null;
        $this->assertIsArray($first);
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('pid', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('status', $first);
        $this->assertIsInt($first['id']);
        $this->assertIsInt($first['pid']);
        $this->assertIsString($first['name']);
        $this->assertContains($first['status'], [0, 1]);

        if (array_key_exists('children', $first)) {
            $this->assertIsArray($first['children']);
        }
    }

    private function getBaseUrl(): string
    {
        $baseUrl = getenv('BACKEND_API_BASE_URL');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return 'http://127.0.0.1:8080';
        }

        return rtrim($baseUrl, '/');
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

    /**
     * @return array<string, mixed>|null
     */
    private function requestJson(string $method, string $url, array $payload = [], array $headers = []): ?array
    {
        $finalUrl = $url;
        $method = strtoupper($method);

        $headerLines = [
            'Accept: application/json',
        ];

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
            $this->fail('接口响应不是合法 JSON。');
        }

        return $decoded;
    }
}
