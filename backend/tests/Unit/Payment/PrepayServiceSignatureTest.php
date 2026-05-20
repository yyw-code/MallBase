<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * 契约测试：PrepayService::prepayById 的方法签名锁定
 *
 * 为何只测签名不测行为：
 *  - DB 归属校验、状态守卫、TTL 复用等场景需要真实 MySQL + Redis + JWT，
 *    属于 Feature 层（与 OrderServiceTest 同款分层策略）。
 *  - 但路径参数从 sn 改为 id 是个一次性硬切换，必须在单测层锁住方法名 + 入参类型，
 *    防止后续误把 prepay(string $sn) 改回去而破坏路由契约。
 *
 * 关联：route/api/client/order.php → pay/:id → OrderController::pay → PrepayService::prepayById
 */
final class PrepayServiceSignatureTest extends TestCase
{
    public function testPrepayByIdMethodExistsWithIdContract(): void
    {
        $ref = new ReflectionClass(\app\service\client\payment\PrepayService::class);

        $this->assertTrue(
            $ref->hasMethod('prepayById'),
            'PrepayService 必须暴露 prepayById：客户端支付入口已改为按订单 ID 调度'
        );

        $method = $ref->getMethod('prepayById');
        $params = $method->getParameters();

        $this->assertCount(3, $params, 'prepayById 应保持 (userId, orderId, sceneCode) 三参签名');
        $this->assertSame('userId', $params[0]->getName());
        $this->assertSame('orderId', $params[1]->getName());
        $this->assertSame('sceneCode', $params[2]->getName());

        $this->assertParamType($params[0], 'int');
        $this->assertParamType($params[1], 'int');
        $this->assertParamType($params[2], 'string');
    }

    public function testLegacySnBasedPrepayIsRemoved(): void
    {
        $ref = new ReflectionClass(\app\service\client\payment\PrepayService::class);

        // 旧 prepay(string $sn) 必须被替换为 prepayById(int $orderId)
        // 同时保留会让上游误以为还能用 sn 入参，故强制移除
        $this->assertFalse(
            $ref->hasMethod('prepay'),
            '旧版 prepay(string $sn) 应已被 prepayById 取代，避免双入口造成混淆'
        );
    }

    public function testOrderServicePayUsesIdBasedSignature(): void
    {
        $ref = new ReflectionClass(\app\service\client\order\OrderService::class);

        $this->assertTrue($ref->hasMethod('pay'), 'OrderService::pay 必须存在');
        $method = $ref->getMethod('pay');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(3, count($params), 'OrderService::pay 至少需要 (orderId, userId, payMethod) 三参');
        $this->assertSame('orderId', $params[0]->getName(), '首个参数必须是订单 ID，禁止再用 sn');
        $this->assertSame('userId', $params[1]->getName(), '第二参数必须是 userId，确保归属边界由 Service 强制');
        $this->assertSame('payMethod', $params[2]->getName());

        $this->assertParamType($params[0], 'int');
        $this->assertParamType($params[1], 'int');
        $this->assertParamType($params[2], 'int');
    }

    private function assertParamType(\ReflectionParameter $param, string $expected): void
    {
        $type = $param->getType();
        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $type,
            sprintf('参数 %s 必须声明类型', $param->getName())
        );
        $this->assertSame(
            $expected,
            $type->getName(),
            sprintf('参数 %s 类型应为 %s', $param->getName(), $expected)
        );
    }
}
