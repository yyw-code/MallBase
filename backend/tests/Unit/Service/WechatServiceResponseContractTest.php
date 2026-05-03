<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

final class WechatServiceResponseContractTest extends TestCase
{
    public function testWechatPendingResponsesDoNotExposeRawWechatIdentityFields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/service/client/WechatService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'bind_token'", $source, '待绑定状态应返回后端生成的短期 bind_token。');
        $this->assertStringNotContainsString("'session_key'        => \$sessionKey", $source, '小程序 session_key 不能返回给客户端。');
        $this->assertStringNotContainsString("'openid'             => \$openid", $source, '待绑定状态不能直接返回小程序 openid。');
        $this->assertStringNotContainsString("'openid'      => \$openid", $source, '待绑定状态不能直接返回公众号 openid。');
        $this->assertStringNotContainsString("'unionid'            => \$unionid", $source, '待绑定状态不能直接返回 unionid。');
        $this->assertStringNotContainsString("'unionid'     => \$unionid", $source, '待绑定状态不能直接返回公众号 unionid。');
    }

    public function testWechatBindEndpointsUseOpaqueBindToken(): void
    {
        $controllerSource = file_get_contents(dirname(__DIR__, 3) . '/app/controller/client/user/UserController.php');
        $apiSource = file_get_contents(dirname(__DIR__, 4) . '/frontend/uniapp/api/user/auth.js');

        $this->assertIsString($controllerSource);
        $this->assertIsString($apiSource);
        $this->assertStringContainsString("param('bind_token'", $controllerSource);
        $this->assertStringNotContainsString("param('openid'", $controllerSource, '绑定接口不应再接收客户端传入的 openid。');
        $this->assertStringContainsString('bind_token: bindToken', $apiSource);
        $this->assertStringNotContainsString('{ openid', $apiSource, '前端绑定请求不应再提交 openid。');
    }
}
