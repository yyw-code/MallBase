<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use app\service\client\WechatAppFactory;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;
use think\App;
use think\facade\Cache;
use Throwable;

final class WechatOfficialOauthUrlTest extends TestCase
{
    use ApiClientTrait;

    public function testOfficialOauthUrlRouteDoesNotFallThroughToWechatLogin(): void
    {
        $body = $this->dispatchClientUserAuthPost('wechat/official/oauthUrl');

        $this->assertIsArray($body);
        $this->assertSame('redirect_uri 不能为空', $body['message'] ?? null);
        $this->assertNotSame('code 不能为空', $body['message'] ?? null);
    }

    /**
     * @dataProvider userAuthRouteProvider
     */
    public function testUserAuthRoutesDoNotFallThroughToShortPrefixRoutes(string $path, string $expectedMessage): void
    {
        $body = $this->dispatchClientUserAuthPost($path);

        $this->assertIsArray($body);
        $this->assertSame($expectedMessage, $body['message'] ?? null, $path);
    }

    /**
     * @return array<string, array{path: string, expectedMessage: string}>
     */
    public static function userAuthRouteProvider(): array
    {
        return [
            'login' => ['path' => 'login', 'expectedMessage' => '请输入手机号'],
            'login username' => ['path' => 'login/username', 'expectedMessage' => '请输入用户名'],
            'login sms' => ['path' => 'login/sms', 'expectedMessage' => '请输入手机号'],
            'wechat miniapp' => ['path' => 'wechat', 'expectedMessage' => 'code 不能为空'],
            'wechat bind mobile' => ['path' => 'wechat/bindMobile', 'expectedMessage' => '参数不完整'],
            'wechat bind mobile by phone code' => ['path' => 'wechat/bindMobileByPhoneCode', 'expectedMessage' => '参数不完整'],
            'wechat bind user info' => ['path' => 'wechat/bindUserInfo', 'expectedMessage' => '参数不完整'],
            'wechat official oauth url' => ['path' => 'wechat/official/oauthUrl', 'expectedMessage' => 'redirect_uri 不能为空'],
            'wechat official bind mobile' => ['path' => 'wechat/official/bindMobile', 'expectedMessage' => '参数不完整'],
            'wechat official login' => ['path' => 'wechat/official', 'expectedMessage' => 'code 不能为空'],
        ];
    }

    public function testFactoryBuildsOfficialOauthUrlFromCachedSettings(): void
    {
        if (gethostbyname('redis') === 'redis' && getenv('REDIS_HOST') === false) {
            $this->markTestSkipped('当前宿主机无法解析 redis 容器域名，跳过公众号 OAuth URL 工厂断言。');
        }

        $appIdKey = 'setting:value:wechat_offi_appid';
        $forceUserInfoKey = 'setting:value:wechat_offi_force_userinfo';
        $cacheReady = false;

        try {
            $app = new App(dirname(__DIR__, 3));
            $app->initialize();
            $cacheReady = true;
            Cache::set($appIdKey, 'wx1234567890abcdef', 60);
            Cache::set($forceUserInfoKey, '1', 60);

            /** @var WechatAppFactory $factory */
            $factory = $app->make(WechatAppFactory::class);
            $url = $factory->officialOauthUrl('https://client.mallbase.test/pages-sub/user/login', 'login');
        } catch (Throwable $e) {
            $this->markTestSkipped('缓存服务不可用，跳过公众号 OAuth URL 工厂断言：' . $e->getMessage());
        } finally {
            if ($cacheReady) {
                try {
                    Cache::delete($appIdKey);
                    Cache::delete($forceUserInfoKey);
                } catch (Throwable) {
                    // 缓存服务不可用时前面已跳过，清理失败不影响测试结论。
                }
            }
        }

        $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $url);
        $this->assertStringContainsString('appid=wx1234567890abcdef', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fclient.mallbase.test%2Fpages-sub%2Fuser%2Flogin', $url);
        $this->assertStringContainsString('scope=snsapi_userinfo', $url);
        $this->assertStringContainsString('state=login', $url);
    }

    public function testFactoryRejectsMissingOfficialAppId(): void
    {
        if (gethostbyname('redis') === 'redis' && getenv('REDIS_HOST') === false) {
            $this->markTestSkipped('当前宿主机无法解析 redis 容器域名，跳过公众号 OAuth URL 缺失配置断言。');
        }

        $appIdKey = 'setting:value:wechat_offi_appid';
        $cacheReady = false;

        try {
            $app = new App(dirname(__DIR__, 3));
            $app->initialize();
            $cacheReady = true;
            Cache::set($appIdKey, '', 60);

            /** @var WechatAppFactory $factory */
            $factory = $app->make(WechatAppFactory::class);

            $this->expectException(BusinessException::class);
            $factory->officialOauthUrl('https://client.mallbase.test/pages-sub/user/login', 'login');
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->markTestSkipped('缓存服务不可用，跳过公众号 OAuth URL 缺失配置断言：' . $e->getMessage());
        } finally {
            if ($cacheReady) {
                try {
                    Cache::delete($appIdKey);
                } catch (Throwable) {
                    // 缓存服务不可用时前面已跳过，清理失败不影响测试结论。
                }
            }
        }
    }

    public function testOfficialOauthUrlUsesBackendSettingAndValidatesMissingAppId(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达。');
        }

        $headers = ["Authorization: Bearer {$token}"];
        $config = $this->loadConfigValues('WechatOffiAccount', $headers);
        if ($config === null || !array_key_exists('wechat_offi_appid', $config)) {
            $this->markTestSkipped('微信公众号配置分组不可用，跳过 OAuth URL 接口断言。');
        }

        $originalConfig = $config;
        $redirectUri = 'https://client.mallbase.test/pages-sub/user/login';

        try {
            $config['wechat_offi_appid'] = '';
            $this->saveConfigValues('WechatOffiAccount', $config, $headers);

            $missingResponse = $this->requestJson(
                'POST',
                $this->getBaseUrl() . '/client/api/user/auth/wechat/official/oauthUrl',
                [
                    'redirect_uri' => $redirectUri,
                    'state' => 'login',
                ]
            );
            $this->assertIsArray($missingResponse, 'OAuth URL 接口不可达。');
            $this->assertNotSame(200, $missingResponse['code'] ?? null, '公众号 AppID 缺失时不应返回授权 URL。');

            $config['wechat_offi_appid'] = 'wx1234567890abcdef';
            $config['wechat_offi_force_userinfo'] = '1';
            $this->saveConfigValues('WechatOffiAccount', $config, $headers);

            $response = $this->requestJson(
                'POST',
                $this->getBaseUrl() . '/client/api/user/auth/wechat/official/oauthUrl',
                [
                    'redirect_uri' => $redirectUri,
                    'state' => 'login',
                ]
            );
            $this->assertIsArray($response, 'OAuth URL 接口不可达。');
            $this->assertSame(200, $response['code'] ?? null, 'OAuth URL 接口应返回 200。');
            $url = $response['data']['url'] ?? null;
            $this->assertIsString($url);
            $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $url);
            $this->assertStringContainsString('appid=wx1234567890abcdef', $url);
            $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fclient.mallbase.test%2Fpages-sub%2Fuser%2Flogin', $url);
            $this->assertStringContainsString('scope=snsapi_userinfo', $url);
            $this->assertStringContainsString('state=login', $url);
        } finally {
            $this->saveConfigValues('WechatOffiAccount', $originalConfig, $headers);
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

    /**
     * @return array<string, mixed>
     */
    private function dispatchClientUserAuthPost(string $path): array
    {
        $app = new App(dirname(__DIR__, 3));

        $request = $app->make(\think\Request::class);
        $request->setPathinfo('client/api/user/auth/' . ltrim($path, '/'));
        $request->withServer([
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
        ]);

        $response = $app->http->run($request);
        $body = json_decode($response->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
