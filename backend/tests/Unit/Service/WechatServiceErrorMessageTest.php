<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use app\service\client\WechatService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WechatServiceErrorMessageTest extends TestCase
{
    private WechatService $service;

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(WechatService::class))->newInstanceWithoutConstructor();
    }

    public function testWechatMiniAppidMismatchMessageIsActionable(): void
    {
        $message = $this->invokeWechatApiErrorMessage(
            'code2Session error: {"errcode":40013,"errmsg":"invalid appid rid: abc"}',
            '微信登录失败,请稍后再试'
        );

        $this->assertSame('小程序 AppID 配置不正确或与当前小程序不一致(errcode:40013)', $message);
    }

    public function testWechatCodeExpiredMessageIsActionable(): void
    {
        $message = $this->invokeWechatApiErrorMessage(
            'code2Session error: {"errcode":40029,"errmsg":"invalid code rid: abc"}',
            '微信登录失败,请稍后再试'
        );

        $this->assertSame('登录 code 无效或已过期,请重新打开小程序后重试(errcode:40029)', $message);
    }

    public function testUnknownWechatErrorKeepsFallback(): void
    {
        $message = $this->invokeWechatApiErrorMessage(
            'code2Session error: {"errcode":99999,"errmsg":"unknown"}',
            '微信登录失败,请稍后再试'
        );

        $this->assertSame('微信登录失败,请稍后再试(errcode:99999)', $message);
    }

    public function testInvalidWechatErrorPayloadKeepsFallback(): void
    {
        $message = $this->invokeWechatApiErrorMessage(
            'code2Session error: upstream timeout',
            '微信登录失败,请稍后再试'
        );

        $this->assertSame('微信登录失败,请稍后再试', $message);
    }

    private function invokeWechatApiErrorMessage(string $message, string $fallback): string
    {
        $method = new \ReflectionMethod(WechatService::class, 'wechatApiErrorMessage');
        $method->setAccessible(true);

        return $method->invoke($this->service, $message, $fallback);
    }
}
