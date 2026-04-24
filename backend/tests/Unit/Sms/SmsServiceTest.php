<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use mall_base\sms\MockSmsAdapter;
use mall_base\sms\SmsException;
use mall_base\sms\SmsRateLimiter;
use mall_base\sms\SmsScene;
use mall_base\sms\SmsService;
use PHPUnit\Framework\TestCase;

/**
 * SmsService 与 SmsRateLimiter 的纯逻辑单元测试
 *
 * 通过 SmsCache 抽象注入 InMemorySmsCache,完全脱离 think\facade\Cache,
 * 测试聚焦在:
 *  - 验证码 6 位、5 分钟 TTL、校验后被删除
 *  - 错误码不通过、过期/不存在的友好提示
 *  - 频控:同手机号 60s 拒绝、同手机号日上限拒绝、同 IP 分钟上限拒绝
 *
 * Mock 适配器只写日志不发短信,关注的是验证码生成与缓存生命周期,以及频控判定。
 */
final class SmsServiceTest extends TestCase
{
    private InMemorySmsCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemorySmsCache();
    }

    public function testSendAndVerifyHappyPath(): void
    {
        $service = $this->makeService();
        $service->sendCode('13800138000', SmsScene::LOGIN, '127.0.0.1');

        $code = $service->peekCodeForTesting('13800138000', SmsScene::LOGIN);
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $service->verifyCode('13800138000', SmsScene::LOGIN, $code);

        // 验证后码被删除,二次校验应抛过期
        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('验证码已过期');
        $service->verifyCode('13800138000', SmsScene::LOGIN, $code);
    }

    public function testWrongCodeRejected(): void
    {
        $service = $this->makeService();
        $service->sendCode('13800138001', SmsScene::REGISTER, '127.0.0.1');

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('验证码错误');
        $service->verifyCode('13800138001', SmsScene::REGISTER, '000000');
    }

    public function testInvalidSceneRejected(): void
    {
        $service = $this->makeService();
        $this->expectException(SmsException::class);
        $service->sendCode('13800138002', 'not_a_scene', '127.0.0.1');
    }

    public function testInvalidMobileRejected(): void
    {
        $service = $this->makeService();
        $this->expectException(SmsException::class);
        $service->sendCode('not_a_phone', SmsScene::LOGIN, '127.0.0.1');
    }

    public function testMobileIntervalRateLimit(): void
    {
        $service = $this->makeService();
        $service->sendCode('13800138003', SmsScene::LOGIN, '127.0.0.1');

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('60 秒');
        $service->sendCode('13800138003', SmsScene::LOGIN, '127.0.0.1');
    }

    public function testMobileDailyRateLimit(): void
    {
        // 跳过 60s 间隔限制:每次发送后清掉 mobile_interval key,但保留 mobile_daily 计数
        $service = $this->makeService();
        for ($i = 0; $i < 5; $i++) {
            $service->sendCode('13800138004', SmsScene::LOGIN, '10.0.0.' . $i);
            $this->cache->forget('sms:rl:mobile_interval:13800138004');
        }

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('今日已超过');
        $service->sendCode('13800138004', SmsScene::LOGIN, '10.0.0.99');
    }

    public function testIpMinuteRateLimit(): void
    {
        $service = $this->makeService();
        $service->sendCode('13800138010', SmsScene::LOGIN, '192.168.1.1');
        $service->sendCode('13800138011', SmsScene::LOGIN, '192.168.1.1');
        $service->sendCode('13800138012', SmsScene::LOGIN, '192.168.1.1');

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('IP');
        $service->sendCode('13800138013', SmsScene::LOGIN, '192.168.1.1');
    }

    private function makeService(): SmsService
    {
        $rateLimiter = new SmsRateLimiter(
            cache: $this->cache,
            mobileDailyLimit: 5,
            ipMinuteLimit: 3,
        );
        return new SmsService(
            adapter: new MockSmsAdapter(),
            rateLimiter: $rateLimiter,
            cache: $this->cache,
            codeTtl: 300,
        );
    }
}
