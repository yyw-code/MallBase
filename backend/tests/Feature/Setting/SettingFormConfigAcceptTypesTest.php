<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use PHPUnit\Framework\TestCase;

final class SettingFormConfigAcceptTypesTest extends TestCase
{
    public function testFormConfigReturnsAcceptTypesAsLabeledOptions(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过设置项 form/config 结构测试。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/form/config',
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达，请先启动服务后再执行测试。');
        }

        $this->assertIsArray($response);
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('form/config 接口未返回 200（可能是权限或环境差异），跳过结构断言。');
        }

        $data = $response['data'] ?? [];
        $ruleTypes = is_array($data) ? ($data['rule_types'] ?? []) : [];
        $this->assertIsArray($ruleTypes);

        foreach (['file', 'files', 'video', 'videos'] as $formType) {
            $rules = $ruleTypes[$formType] ?? null;
            $this->assertIsArray($rules, "缺少 {$formType} 规则定义");

            $acceptRule = $this->findRuleByType($rules, 'accept_types');
            $this->assertIsArray($acceptRule, "{$formType} 缺少 accept_types 规则");

            $options = $acceptRule['options'] ?? null;
            $this->assertIsArray($options, "{$formType} 的 accept_types.options 必须是数组");
            $this->assertNotEmpty($options, "{$formType} 的 accept_types.options 不应为空");

            $first = $options[0] ?? null;
            $this->assertIsArray($first, "{$formType} 的 options 项必须是对象数组");
            $this->assertIsString($first['label'] ?? null);
            $this->assertIsString($first['value'] ?? null);
        }

        $fileAcceptRule = $this->findRuleByType($ruleTypes['file'] ?? [], 'accept_types');
        $fileOptions = is_array($fileAcceptRule) ? ($fileAcceptRule['options'] ?? []) : [];

        $pdfOption = $this->findOptionByValue($fileOptions, 'application/pdf');
        $this->assertIsArray($pdfOption, 'file 规则必须包含 application/pdf');
        $this->assertNotSame('application/pdf', $pdfOption['label'] ?? '', 'application/pdf 应展示短名称而不是原 MIME');

        $videoAcceptRule = $this->findRuleByType($ruleTypes['video'] ?? [], 'accept_types');
        $videoOptions = is_array($videoAcceptRule) ? ($videoAcceptRule['options'] ?? []) : [];
        $mp4Option = $this->findOptionByValue($videoOptions, 'video/mp4');
        $this->assertIsArray($mp4Option, 'video 规则必须包含 video/mp4');
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

    /**
     * @param array<int, mixed> $options
     */
    private function findOptionByValue(array $options, string $value): ?array
    {
        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        return null;
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
