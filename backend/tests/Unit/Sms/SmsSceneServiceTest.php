<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use app\service\admin\sms\SmsSceneService;
use PHPUnit\Framework\TestCase;

final class SmsSceneServiceTest extends TestCase
{
    public function testListPaginatesAndKeepsBindingId(): void
    {
        $service = new TestableSmsSceneService([
            ['id' => 1, 'scene_code' => 'login', 'scene_name' => '登录验证码', 'provider_id' => 1, 'provider_name' => '阿里云', 'template_name' => '登录模板', 'sign_name' => '商城', 'status' => 1],
            ['id' => 2, 'scene_code' => 'register', 'scene_name' => '注册验证码', 'provider_id' => 1, 'provider_name' => '阿里云', 'template_name' => '注册模板', 'sign_name' => '商城', 'status' => 1],
            ['id' => null, 'scene_code' => 'reset_password', 'scene_name' => '找回密码', 'provider_id' => null, 'provider_name' => null, 'template_name' => null, 'sign_name' => null, 'status' => null],
        ]);

        $result = $service->getList([], 2, 1);

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['list'][0]['id']);
        $this->assertSame('register', $result['list'][0]['scene_code']);
    }

    public function testListFiltersKeywordProviderAndDisabledStatus(): void
    {
        $service = new TestableSmsSceneService([
            ['id' => 1, 'scene_code' => 'login', 'scene_name' => '登录验证码', 'provider_id' => 1, 'provider_name' => '阿里云', 'template_name' => '登录模板', 'sign_name' => '商城', 'status' => 0],
            ['id' => 2, 'scene_code' => 'register', 'scene_name' => '注册验证码', 'provider_id' => 1, 'provider_name' => '阿里云', 'template_name' => '注册模板', 'sign_name' => '商城', 'status' => 1],
            ['id' => 3, 'scene_code' => 'bind_mobile', 'scene_name' => '绑定手机', 'provider_id' => 2, 'provider_name' => 'Mock', 'template_name' => '登录模板', 'sign_name' => '测试', 'status' => 0],
        ]);

        $result = $service->getList([
            'keyword' => '登录',
            'provider_id' => 1,
            'status' => 0,
        ], 1, 10);

        $this->assertSame(1, $result['total']);
        $this->assertSame('login', $result['list'][0]['scene_code']);
    }
}

/**
 * @internal
 */
final class TestableSmsSceneService extends SmsSceneService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildSceneRows(): array
    {
        return $this->rows;
    }
}
