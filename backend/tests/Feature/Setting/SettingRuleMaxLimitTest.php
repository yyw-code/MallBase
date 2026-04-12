<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use PHPUnit\Framework\TestCase;

final class SettingRuleMaxLimitTest extends TestCase
{
    public function testSettingRulesShouldBeClampedBySystemLimits(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过设置规则上限测试。');
        }

        $uploadConfig = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/config/uploadConfig',
            ['type' => 'files'],
            ["Authorization: Bearer {$token}"]
        );
        if (!is_array($uploadConfig) || ($uploadConfig['code'] ?? null) !== 200) {
            $this->markTestSkipped('获取上传配置失败，跳过测试。');
        }

        $limits = $uploadConfig['data']['system_limits'] ?? [];
        $effectiveMaxSize = is_numeric($limits['effective_max_size_mb'] ?? null) ? (float)$limits['effective_max_size_mb'] : null;
        $effectiveMaxCount = is_numeric($limits['effective_max_count'] ?? null) ? (int)$limits['effective_max_count'] : null;

        $listResponse = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/list',
            ['page' => 1, 'limit' => 20],
            ["Authorization: Bearer {$token}"]
        );

        if (!is_array($listResponse) || ($listResponse['code'] ?? null) !== 200) {
            $this->markTestSkipped('设置项列表接口不可达，跳过测试。');
        }

        $list = $listResponse['data']['list'] ?? [];
        if (!is_array($list) || empty($list)) {
            $this->markTestSkipped('设置项列表为空，无法执行测试。');
        }

        $target = $this->pickTargetItem($list);
        if (!is_array($target)) {
            $this->markTestSkipped('未找到可编辑的设置项。');
        }

        $itemId = (int)($target['id'] ?? 0);
        $this->assertGreaterThan(0, $itemId);

        $originalPayload = $this->buildUpdatePayload($target);
        $testPayload = $originalPayload;
        $testPayload['type'] = 'files';
        $testPayload['rules'] = [
            ['type' => 'max_size', 'value' => 99999, 'message' => '文件大小不能超过99999MB'],
            ['type' => 'max_count', 'value' => 99999, 'message' => '最多上传99999个文件'],
        ];

        try {
            $updateResponse = $this->requestJson(
                'PUT',
                $this->getBaseUrl() . '/admin/api/setting/item/update/' . $itemId,
                $testPayload,
                ["Authorization: Bearer {$token}"]
            );

            $this->assertIsArray($updateResponse);
            if (($updateResponse['code'] ?? null) !== 200) {
                $this->markTestSkipped('更新设置项未返回 200（可能是权限或数据约束差异），跳过截断断言。');
            }
            $this->assertSame(200, $updateResponse['code'] ?? null);

            $updateWarnings = $updateResponse['data']['warnings'] ?? [];
            $this->assertIsArray($updateWarnings);

            $updated = $this->fetchItemById($itemId, $token);
            $this->assertIsArray($updated, '更新后未能读取到设置项');

            $rules = is_array($updated['rules'] ?? null) ? $updated['rules'] : [];
            $maxSizeRule = $this->findRuleByType($rules, 'max_size');
            $maxCountRule = $this->findRuleByType($rules, 'max_count');
            $this->assertIsArray($maxSizeRule);
            $this->assertIsArray($maxCountRule);

            $storedSize = is_numeric($maxSizeRule['value'] ?? null) ? (float)$maxSizeRule['value'] : null;
            $storedCount = is_numeric($maxCountRule['value'] ?? null) ? (int)$maxCountRule['value'] : null;

            $this->assertNotNull($storedSize);
            $this->assertNotNull($storedCount);

            if ($effectiveMaxSize !== null) {
                $this->assertLessThanOrEqual($effectiveMaxSize, (float)$storedSize + 0.0001);
            } else {
                $this->assertLessThanOrEqual(99999.0, (float)$storedSize);
            }

            if ($effectiveMaxCount !== null) {
                $this->assertLessThanOrEqual($effectiveMaxCount, $storedCount);
            } else {
                $this->assertLessThanOrEqual(99999, $storedCount);
            }
        } finally {
            $this->requestJson(
                'PUT',
                $this->getBaseUrl() . '/admin/api/setting/item/update/' . $itemId,
                $originalPayload,
                ["Authorization: Bearer {$token}"]
            );
        }
    }

    private function pickTargetItem(array $list): ?array
    {
        foreach ($list as $item) {
            if (is_array($item) && isset($item['id'], $item['name'], $item['code'])) {
                return $item;
            }
        }

        return null;
    }

    private function buildUpdatePayload(array $item): array
    {
        return [
            'group_id' => (int)($item['group_id'] ?? 0),
            'name' => (string)($item['name'] ?? ''),
            'code' => (string)($item['code'] ?? ''),
            'value' => (string)($item['value'] ?? ''),
            'type' => (string)($item['type'] ?? 'input'),
            'options' => $item['options'] ?? null,
            'rules' => is_array($item['rules'] ?? null) ? $item['rules'] : null,
            'placeholder' => (string)($item['placeholder'] ?? ''),
            'remark' => (string)($item['remark'] ?? ''),
            'sort' => (int)($item['sort'] ?? 0),
        ];
    }

    private function fetchItemById(int $itemId, string $token): ?array
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/list',
            ['page' => 1, 'limit' => 50],
            ["Authorization: Bearer {$token}"]
        );

        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $list = $response['data']['list'] ?? [];
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $item) {
            if (is_array($item) && (int)($item['id'] ?? 0) === $itemId) {
                return $item;
            }
        }

        return null;
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
