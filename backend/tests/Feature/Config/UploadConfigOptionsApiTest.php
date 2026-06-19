<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class UploadConfigOptionsApiTest extends TestCase
{
    use ApiClientTrait;

    public function testAdminUploadOptionsReturnsCommonDictionaries(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过上传选项测试。');
        }

        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/config/uploadOptions',
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($response === null) {
            $this->markTestSkipped('上传选项接口不可达。');
        }

        $this->assertSame(200, $response['code'] ?? null);
        $data = $response['data'] ?? [];
        $this->assertIsArray($data);

        $uploadTypes = $data['upload_types'] ?? null;
        $assetTypes = $data['asset_types'] ?? null;
        $drivers = $data['upload_drivers'] ?? null;

        $this->assertIsArray($uploadTypes);
        $this->assertIsArray($assetTypes);
        $this->assertIsArray($drivers);

        foreach (['image', 'images', 'video', 'videos', 'file', 'files'] as $value) {
            $option = $this->findOption($uploadTypes, $value);
            $this->assertIsArray($option, "缺少上传类型 {$value}");
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('asset_type', $option);
            $this->assertArrayHasKey('multiple', $option);
            $this->assertIsBool($option['multiple']);
        }

        foreach (['image', 'video', 'file'] as $value) {
            $this->assertIsArray($this->findOption($assetTypes, $value), "缺少素材类型 {$value}");
        }

        $enabledDrivers = array_values(array_filter(
            $drivers,
            static fn($item) => is_array($item) && ($item['enabled'] ?? false) === true
        ));
        $this->assertLessThanOrEqual(1, count($enabledDrivers), '当前上传驱动只能有一个 enabled=true');

        foreach ($drivers as $driver) {
            $this->assertIsArray($driver);
            $this->assertArrayHasKey('label', $driver);
            $this->assertArrayHasKey('value', $driver);
            $this->assertArrayHasKey('enabled', $driver);
            $this->assertIsBool($driver['enabled']);
        }
    }

    public function testClientUploadConfigDoesNotExposeAdminTechnicalWarnings(): void
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/setting/uploadConfig',
            ['type' => 'video']
        );

        if ($response === null) {
            $this->markTestSkipped('客户端上传配置接口不可达。');
        }

        $this->assertSame(200, $response['code'] ?? null);
        $data = $response['data'] ?? [];
        $this->assertIsArray($data);

        foreach (['max_size', 'max_count', 'accept_types', 'tips'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertArrayNotHasKey('warnings', $data);
        $this->assertArrayNotHasKey('system_limits', $data);
        $this->assertIsArray($data['tips']);

        $raw = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($raw);
        foreach (['PHP', 'Nginx', '413', 'client_max_body_size', 'bucket', 'endpoint', 'driver'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<string, mixed>|null
     */
    private function findOption(array $options, string $value): ?array
    {
        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        return null;
    }
}
