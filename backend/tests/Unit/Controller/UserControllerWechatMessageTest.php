<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use app\controller\client\user\UserController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UserControllerWechatMessageTest extends TestCase
{
    public function testWechatPendingLoginMessagesMatchReturnedState(): void
    {
        $message = $this->wechatLoginMessage(['need_mobile' => true]);
        $this->assertSame('请绑定手机号以完成登录', $message);

        $message = $this->wechatLoginMessage(['need_userinfo' => true]);
        $this->assertSame('请完善头像昵称以完成登录', $message);

        $message = $this->wechatLoginMessage(['access_token' => 'token'], '绑定成功');
        $this->assertSame('绑定成功', $message);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function wechatLoginMessage(array $result, string $successMessage = '登录成功'): string
    {
        $reflection = new ReflectionClass(UserController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('wechatLoginMessage');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $result, $successMessage);
    }
}
