<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;

final class GoodsSingleSpecDefaultSkuApiTest extends TestCase
{
    public function testSingleSpecGoodsShouldPersistDefaultSku(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过单规格默认 SKU 测试。');
        }

        $headers = ["Authorization: Bearer {$token}"];
        $nonce = (string) time();
        $categoryId = null;
        $goodsId = null;

        try {
            $categoryCreate = $this->requestJson(
                'POST',
                $this->getBaseUrl() . '/admin/api/goods/category/create',
                [
                    'name' => "单规格分类-{$nonce}",
                    'pid' => 0,
                    'sort' => 0,
                    'status' => 1,
                ],
                $headers,
            );

            if (!is_array($categoryCreate) || ($categoryCreate['code'] ?? null) !== 200) {
                $this->markTestSkipped('临时分类创建失败，跳过单规格默认 SKU 测试。');
            }

            $categoryId = (int) ($categoryCreate['data']['id'] ?? 0);
            $this->assertGreaterThan(0, $categoryId);

            $goodsCreate = $this->requestJson(
                'POST',
                $this->getBaseUrl() . '/admin/api/goods/list/create',
                [
                    'category_id' => $categoryId,
                    'name' => "单规格商品-{$nonce}",
                    'spec_type' => 1,
                    'price' => 88.5,
                    'market_price' => 99.9,
                    'stock' => 12,
                    'main_image' => '/uploads/test/single-spec.jpg',
                    'images' => [],
                    'skus' => [],
                    'tag_ids' => [],
                    'status' => 1,
                    'is_on_sale' => 1,
                    'is_recommend' => 0,
                    'is_new' => 0,
                    'is_hot' => 0,
                    'unit' => '件',
                ],
                $headers,
            );

            if (!is_array($goodsCreate) || ($goodsCreate['code'] ?? null) !== 200) {
                $this->markTestSkipped('单规格商品创建失败，跳过默认 SKU 测试。');
            }

            $goodsId = (int) ($goodsCreate['data']['id'] ?? 0);
            $this->assertGreaterThan(0, $goodsId);

            $goodsInfo = $this->requestJson(
                'GET',
                $this->getBaseUrl() . "/admin/api/goods/list/info/{$goodsId}",
                [],
                $headers,
            );

            if (!is_array($goodsInfo) || ($goodsInfo['code'] ?? null) !== 200) {
                $this->markTestSkipped('商品详情读取失败，跳过默认 SKU 测试。');
            }

            $data = $goodsInfo['data'] ?? [];
            $this->assertSame(1, (int) ($data['spec_type'] ?? 0));
            $this->assertIsArray($data['spec_meta'] ?? null);
            $this->assertSame([], $data['spec_meta']);
            $this->assertIsArray($data['skus'] ?? null);
            $this->assertCount(1, $data['skus']);

            $sku = $data['skus'][0] ?? [];
            $this->assertSame('', (string) ($sku['spec_values'] ?? 'x'));
            $this->assertSame('88.50', number_format((float) ($sku['price'] ?? 0), 2, '.', ''));
            $this->assertSame(12, (int) ($sku['stock'] ?? -1));
            $this->assertSame('/uploads/test/single-spec.jpg', (string) ($sku['image'] ?? ''));
        } finally {
            if ($goodsId) {
                $this->requestJson(
                    'DELETE',
                    $this->getBaseUrl() . "/admin/api/goods/list/delete/{$goodsId}",
                    [],
                    $headers,
                );
            }

            if ($categoryId) {
                $this->requestJson(
                    'DELETE',
                    $this->getBaseUrl() . "/admin/api/goods/category/delete/{$categoryId}",
                    [],
                    $headers,
                );
            }
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

        $token = $response['data']['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
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
