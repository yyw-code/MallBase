<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use app\service\cache\SettingCacheService;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;
use think\App;
use think\facade\Cache;
use Throwable;

final class SettingSaveConfigCacheInvalidationTest extends TestCase
{
    use ApiClientTrait;

    public function testGetSettingValueDoesNotCacheNullValue(): void
    {
        if (gethostbyname('redis') === 'redis' && getenv('REDIS_HOST') === false) {
            $this->markTestSkipped('当前宿主机无法解析 redis 容器域名，跳过直接 Redis 缓存断言。');
        }

        $code = 'codex_null_setting_value_probe';
        $cacheKey = 'setting:value:' . $code;
        $cacheReady = false;

        try {
            $app = new App(dirname(__DIR__, 3));
            $app->initialize();
            $cacheReady = true;
            Cache::delete($cacheKey);

            /** @var SettingCacheService $cacheService */
            $cacheService = $app->make(SettingCacheService::class);
            $cachedValue = $cacheService->getSettingValue($code, static fn(): null => null);
            $hasCacheAfterRead = Cache::has($cacheKey);
        } catch (Throwable $e) {
            $this->markTestSkipped('缓存服务不可用，跳过直接缓存断言：' . $e->getMessage());
        } finally {
            if ($cacheReady) {
                Cache::delete($cacheKey);
            }
        }

        $this->assertNull($cachedValue);
        $this->assertFalse($hasCacheAfterRead, '单值读取返回 null 时不应写入 Redis，避免出现 N; 缓存占位。');
    }

    public function testSystemSettingDoesNotFallbackToConfigFile(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/service/SystemSettingService.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('config($code)', $source, '数据库设置缺失时不应再回退读取 config($code)。');
    }

    public function testClearSettingValuesInvalidatesOnlyRequestedSingleValueCache(): void
    {
        if (gethostbyname('redis') === 'redis' && getenv('REDIS_HOST') === false) {
            $this->markTestSkipped('当前宿主机无法解析 redis 容器域名，跳过直接 Redis 缓存断言。');
        }

        $code = 'codex_clear_setting_value_tag_probe';
        $otherCode = 'codex_clear_setting_value_other_probe';
        $cacheKey = 'setting:value:' . $code;
        $otherCacheKey = 'setting:value:' . $otherCode;
        $cacheReady = false;

        try {
            $app = new App(dirname(__DIR__, 3));
            $app->initialize();
            $cacheReady = true;
            Cache::delete($cacheKey);
            Cache::delete($otherCacheKey);

            /** @var SettingCacheService $cacheService */
            $cacheService = $app->make(SettingCacheService::class);
            $cachedValue = $cacheService->getSettingValue($code, static fn(): string => 'tagged-value');
            $otherCachedValue = $cacheService->getSettingValue($otherCode, static fn(): string => 'other-tagged-value');
            $hasCacheBeforeClear = Cache::has($cacheKey);
            $hasOtherCacheBeforeClear = Cache::has($otherCacheKey);
            $cacheService->clearSettingValues([$code]);
            $hasCacheAfterClear = Cache::has($cacheKey);
            $hasOtherCacheAfterClear = Cache::has($otherCacheKey);
        } catch (Throwable $e) {
            $this->markTestSkipped('缓存服务不可用，跳过直接缓存断言：' . $e->getMessage());
        } finally {
            if ($cacheReady) {
                Cache::delete($cacheKey);
                Cache::delete($otherCacheKey);
            }
        }

        $this->assertSame('tagged-value', $cachedValue);
        $this->assertSame('other-tagged-value', $otherCachedValue);
        $this->assertTrue($hasCacheBeforeClear, '单值缓存预热后应写入 Redis。');
        $this->assertTrue($hasOtherCacheBeforeClear, '旁路单值缓存预热后应写入 Redis。');
        $this->assertFalse($hasCacheAfterClear, 'clearSettingValues 应精确清除传入 code 的单值缓存。');
        $this->assertTrue($hasOtherCacheAfterClear, 'clearSettingValues 不应清除未传入 code 的单值缓存。');
    }

    public function testSaveSystemBasicInvalidatesSingleValueCacheUsedByUploadUrl(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达。');
        }

        $headers = ["Authorization: Bearer {$token}"];
        $systemBasic = $this->loadConfigValues('SystemBasic', $headers);
        $uploadLocal = $this->loadConfigValues('UploadLocal', $headers);

        if ($systemBasic === null || $uploadLocal === null) {
            $this->markTestSkipped('配置接口不可达或未返回 200。');
        }

        $originalSystemBasic = $systemBasic;

        if (($uploadLocal['local_base_url'] ?? '') !== '') {
            $this->markTestSkipped('local_base_url 非空时上传域名不回退 site_url，跳过该缓存失效用例。');
        }

        $oldUrl = 'https://cache-old.mallbase.test';
        $newUrl = 'https://cache-new.mallbase.test';

        try {
            $systemBasic['site_url'] = $oldUrl;
            $this->saveConfigValues('SystemBasic', $systemBasic, $headers);

            $oldLogo = $this->loadAdminLogoFromAppMeta();
            if ($oldLogo === null) {
                $this->markTestSkipped('appMeta 未返回可用于断言的 admin_logo。');
            }
            $this->assertStringStartsWith($oldUrl, $oldLogo);

            $systemBasic['site_url'] = $newUrl;
            $this->saveConfigValues('SystemBasic', $systemBasic, $headers);

            $newLogo = $this->loadAdminLogoFromAppMeta();
            if ($newLogo === null) {
                $this->markTestSkipped('appMeta 未返回可用于断言的 admin_logo。');
            }

            $this->assertStringStartsWith(
                $newUrl,
                $newLogo,
                'saveConfig 更新 site_url 后，依赖 getSystemSetting(site_url) 的上传 URL 应立即使用新值。'
            );
        } finally {
            try {
                $this->saveConfigValues('SystemBasic', $originalSystemBasic, $headers);
            } catch (Throwable) {
                // 接口不可达或测试已跳过时，恢复失败不应覆盖原始测试结论。
            }
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>|null
     */
    private function loadConfigValues(string $groupCode, array $headers): ?array
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . "/admin/api/setting/item/config/{$groupCode}",
            [],
            $headers
        );

        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        return $this->collectConfigValues($data);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $headers
     */
    private function saveConfigValues(string $groupCode, array $values, array $headers): void
    {
        $response = $this->requestJson(
            'POST',
            $this->getBaseUrl() . "/admin/api/setting/item/saveConfig/{$groupCode}",
            $values,
            $headers
        );

        $this->assertIsArray($response, "保存 {$groupCode} 配置接口不可达。");
        $this->assertSame(200, $response['code'] ?? null, "保存 {$groupCode} 配置失败。");
    }

    private function loadAdminLogoFromAppMeta(): ?string
    {
        $response = $this->requestJson('GET', $this->getBaseUrl() . '/admin/api/config/appMeta');
        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $logo = $data['admin_logo'] ?? null;
        return is_string($logo) && $logo !== '' ? $logo : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function collectConfigValues(array $config): array
    {
        $values = [];
        foreach (($config['settings'] ?? []) as $setting) {
            if (is_array($setting) && isset($setting['code'])) {
                $values[(string)$setting['code']] = $setting['value'] ?? '';
            }
        }

        foreach (($config['tabs'] ?? []) as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            foreach (($tab['settings'] ?? []) as $setting) {
                if (is_array($setting) && isset($setting['code'])) {
                    $values[(string)$setting['code']] = $setting['value'] ?? '';
                }
            }
        }

        return $values;
    }
}
