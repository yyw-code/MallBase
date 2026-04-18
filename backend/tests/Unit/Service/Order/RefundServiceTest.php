<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\service\client\order\RefundService;
use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 买家售后服务纯逻辑单元测试
 *
 * 覆盖（全部在 DB 访问之前生效的分支）：
 *  - apply 前置守卫：userId / orderItemId / quantity / type / reason
 *  - calcRefundAmount    退款金额 bcmath 2 位精度
 *  - assertQuantityLimit 申请数量上限校验
 *  - assertOrderRefundable  主订单状态白名单校验
 *  - assertUserId        登录校验
 *
 * apply / cancel / list / detail 的完整 DB 路径留给集成测试
 */
final class RefundServiceTest extends TestCase
{
    private RefundService $service;

    protected function setUp(): void
    {
        $this->service = new RefundService();
    }

    // ====================== apply 前置守卫 ======================

    /**
     * userId=0 → 立即拒绝，不走 DB
     */
    public function testApplyRejectsZeroUserId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->service->apply(0, $this->validApplyPayload());
    }

    /**
     * userId=-1 → 立即拒绝
     */
    public function testApplyRejectsNegativeUserId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->service->apply(-1, $this->validApplyPayload());
    }

    /**
     * order_item_id 缺失 → 拒绝
     */
    public function testApplyRejectsZeroOrderItemId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('缺少订单项参数');

        $payload = $this->validApplyPayload();
        $payload['order_item_id'] = 0;
        $this->service->apply(1, $payload);
    }

    /**
     * quantity=0 → 拒绝
     */
    public function testApplyRejectsZeroQuantity(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('申请数量必须大于 0');

        $payload = $this->validApplyPayload();
        $payload['quantity'] = 0;
        $this->service->apply(1, $payload);
    }

    /**
     * quantity=-1 → 拒绝
     */
    public function testApplyRejectsNegativeQuantity(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('申请数量必须大于 0');

        $payload = $this->validApplyPayload();
        $payload['quantity'] = -1;
        $this->service->apply(1, $payload);
    }

    /**
     * MVP 硬拦截：退货退款（type=1）在 apply 入口直接拒绝
     */
    public function testApplyRejectsReturnRefundType(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('退货退款功能开发中，敬请期待');

        $payload = $this->validApplyPayload();
        $payload['type'] = RefundOrderStatus::TYPE_RETURN_REFUND;
        $this->service->apply(1, $payload);
    }

    /**
     * reason 不在 RefundReason 枚举内 → 拒绝
     */
    public function testApplyRejectsInvalidReason(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('售后原因不合法');

        $payload = $this->validApplyPayload();
        $payload['reason'] = 'INVALID_REASON';
        $this->service->apply(1, $payload);
    }

    /**
     * reason 为空字符串 → 拒绝
     */
    public function testApplyRejectsEmptyReason(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('售后原因不合法');

        $payload = $this->validApplyPayload();
        $payload['reason'] = '';
        $this->service->apply(1, $payload);
    }

    /**
     * 所有 RefundReason 枚举值都应通过 reason 校验
     *
     * 后续校验（assertOwnedOrderItem）会因 DB 缺失而报其他异常，
     * 但不会再是"售后原因不合法"，从而证明 reason 校验通过。
     */
    public function testApplyAcceptsAllValidReasons(): void
    {
        foreach (RefundReason::values() as $reason) {
            $payload = $this->validApplyPayload();
            $payload['reason'] = $reason;

            try {
                $this->service->apply(1, $payload);
                $this->fail("reason={$reason} 预期在 DB 阶段失败，但没有异常抛出");
            } catch (BusinessException $e) {
                // 只要不是"售后原因不合法"就说明 reason 校验通过了
                $this->assertStringNotContainsString('售后原因不合法', $e->getMessage());
            } catch (\Throwable $e) {
                // DB 相关异常也证明 reason 校验通过了
                $this->assertStringNotContainsString('售后原因不合法', $e->getMessage());
            }
        }
    }

    // ====================== calcRefundAmount ======================

    public function testCalcRefundAmountSingleItem(): void
    {
        $item = ['unit_price' => '12.50'];
        $result = $this->invokePrivate('calcRefundAmount', [$item, 2]);
        $this->assertSame('25.00', $result);
    }

    public function testCalcRefundAmountBcmathPrecision(): void
    {
        // 0.10 × 3 = 0.30（float 下可能出现 0.30000000000000004）
        $item = ['unit_price' => '0.10'];
        $result = $this->invokePrivate('calcRefundAmount', [$item, 3]);
        $this->assertSame('0.30', $result);
    }

    public function testCalcRefundAmountHighValueItem(): void
    {
        $item = ['unit_price' => '9999.99'];
        $result = $this->invokePrivate('calcRefundAmount', [$item, 100]);
        $this->assertSame('999999.00', $result);
    }

    public function testCalcRefundAmountSingleUnit(): void
    {
        $item = ['unit_price' => '88.00'];
        $result = $this->invokePrivate('calcRefundAmount', [$item, 1]);
        $this->assertSame('88.00', $result);
    }

    public function testCalcRefundAmountTruncatesBeyondTwoDecimals(): void
    {
        // 33.333 × 3 = 99.999 → bcmath scale=2 截断为 99.99
        $item = ['unit_price' => '33.333'];
        $result = $this->invokePrivate('calcRefundAmount', [$item, 3]);
        $this->assertSame('99.99', $result);
    }

    // ====================== assertQuantityLimit ======================

    /**
     * 正好退完最后一件 → 通过
     */
    public function testAssertQuantityLimitAcceptsExactRemain(): void
    {
        $item = ['quantity' => 5, 'refunded_quantity' => 3];
        $this->invokePrivate('assertQuantityLimit', [$item, 2]);
        $this->assertTrue(true); // 不抛即通过
    }

    /**
     * 退 1 件，剩余充足 → 通过
     */
    public function testAssertQuantityLimitAcceptsBelowRemain(): void
    {
        $item = ['quantity' => 10, 'refunded_quantity' => 0];
        $this->invokePrivate('assertQuantityLimit', [$item, 5]);
        $this->assertTrue(true);
    }

    /**
     * 超出剩余可退数量 → 拒绝
     */
    public function testAssertQuantityLimitRejectsOverRemain(): void
    {
        $item = ['quantity' => 5, 'refunded_quantity' => 3];

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/申请数量超出可退数量/');

        $this->invokePrivate('assertQuantityLimit', [$item, 3]);
    }

    /**
     * 已全部退完 → 拒绝
     */
    public function testAssertQuantityLimitRejectsWhenFullyRefunded(): void
    {
        $item = ['quantity' => 2, 'refunded_quantity' => 2];

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该商品已全部申请过售后');

        $this->invokePrivate('assertQuantityLimit', [$item, 1]);
    }

    /**
     * refunded_quantity 字段缺失时视作 0
     */
    public function testAssertQuantityLimitDefaultsRefundedToZero(): void
    {
        $item = ['quantity' => 3]; // 没有 refunded_quantity 键
        $this->invokePrivate('assertQuantityLimit', [$item, 3]);
        $this->assertTrue(true);
    }

    // ====================== assertOrderRefundable ======================

    /**
     * @return iterable<string, array{0:int, 1:bool}>
     */
    public static function orderStatusMatrix(): iterable
    {
        yield 'PAID → 可售后'       => [OrderStatus::PAID, true];
        yield 'SHIPPED → 可售后'    => [OrderStatus::SHIPPED, true];
        yield 'RECEIVED → 可售后'   => [OrderStatus::RECEIVED, true];
        yield 'COMPLETED → 可售后'  => [OrderStatus::COMPLETED, true];
        yield 'PENDING_PAY → 不可'  => [OrderStatus::PENDING_PAY, false];
        yield 'CLOSED → 不可'       => [OrderStatus::CLOSED, false];
    }

    /**
     * @dataProvider orderStatusMatrix
     */
    public function testAssertOrderRefundableMatchesWhitelist(int $status, bool $shouldPass): void
    {
        $order = ['status' => $status];

        if ($shouldPass) {
            $this->invokePrivate('assertOrderRefundable', [$order]);
            $this->assertTrue(true);
        } else {
            $this->expectException(BusinessException::class);
            $this->expectExceptionMessage('当前订单状态不允许发起售后');
            $this->invokePrivate('assertOrderRefundable', [$order]);
        }
    }

    // ====================== assertUserId ======================

    public function testAssertUserIdRejectsZero(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');
        $this->invokePrivate('assertUserId', [0]);
    }

    public function testAssertUserIdAcceptsPositive(): void
    {
        $this->invokePrivate('assertUserId', [1]);
        $this->invokePrivate('assertUserId', [999999]);
        $this->assertTrue(true);
    }

    // ====================== cancel 前置守卫 ======================

    public function testCancelRejectsZeroUserId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->service->cancel(0, 1);
    }

    // ====================== list / detail 前置守卫 ======================

    public function testListRejectsZeroUserId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->service->list(0);
    }

    public function testDetailRejectsZeroUserId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->service->detail(0, 1);
    }

    // ====================== ORDERABLE_STATUSES 契约 ======================

    /**
     * 确保可申请售后的主单状态集合恰好是 {10,20,30,40}
     *
     * 若后续扩大白名单必须同步此测试，强制 review
     */
    public function testOrderableStatusesContract(): void
    {
        $ref = new ReflectionClass(RefundService::class);
        $const = $ref->getReflectionConstant('ORDERABLE_STATUSES');
        $this->assertNotFalse($const, 'ORDERABLE_STATUSES 常量不存在');
        $value = $const->getValue();

        sort($value);
        $expected = [
            OrderStatus::PAID,
            OrderStatus::SHIPPED,
            OrderStatus::RECEIVED,
            OrderStatus::COMPLETED,
        ];
        sort($expected);

        $this->assertSame($expected, $value);
    }

    // ====================== helpers ======================

    /**
     * 合法的 apply payload（会在 DB 阶段报错，但能通过所有前置校验）
     *
     * @return array<string, mixed>
     */
    private function validApplyPayload(): array
    {
        return [
            'order_item_id' => 1,
            'quantity'      => 1,
            'type'          => RefundOrderStatus::TYPE_REFUND_ONLY,
            'reason'        => RefundReason::QUALITY_ISSUE,
            'remark'        => '测试备注',
        ];
    }

    /**
     * 反射调用 RefundService 的私有方法
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionClass(RefundService::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }
}
