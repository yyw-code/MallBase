<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\admin\model\order\Order;
use app\admin\service\order\OrderStatusMachine;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;

/**
 * 订单状态机前置守卫单元测试
 *
 * 设计意图：
 *  - transit() 有三道前置守卫，全部在 $this->transaction() 之前生效
 *  - 单测只覆盖这三道守卫，DB 分支（save / OrderLog::create）留给集成测试
 *  - 守卫一旦被绕过，整个状态流转模型失效 —— 因此必须单独兜底
 *
 * 守卫顺序（务必保持）：
 *  1. !OrderStatus::isValid($toStatus) → 抛“订单目标状态不合法”
 *  2. $fromStatus === $toStatus        → 幂等短路返回，不抛不写日志
 *  3. !OrderStatus::canTransit(...)    → 抛“订单状态不允许从 X 流转到 Y”
 *
 * 采用反射绕过 Model ORM 字段初始化，避免 ThinkPHP Model 在
 * PHPUnit 自动装载环境下访问 DB 连接。
 */
final class OrderStatusMachineTest extends TestCase
{
    private OrderStatusMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new OrderStatusMachine();
    }

    /**
     * 守卫 1：目标状态非枚举值 → 立即抛业务异常
     */
    public function testTransitRejectsInvalidTargetStatus(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING_PAY);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('订单目标状态不合法');

        $this->machine->transit(
            $order,
            toStatus: 999, // 不在 OrderStatus 枚举中
            operatorType: OperatorType::SYSTEM,
            operatorId: null,
            remark: 'fuzz',
        );
    }

    public function testTransitRejectsNegativeTargetStatus(): void
    {
        $order = $this->makeOrder(OrderStatus::PAID);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('订单目标状态不合法');

        $this->machine->transit($order, -1, OperatorType::ADMIN);
    }

    /**
     * 守卫 2：同状态幂等短路 —— 不抛异常，不进入事务
     *
     * 这里隐式断言“没有访问 DB”：如果进入了 $this->transaction()，
     * 在没有 ThinkPHP 容器的纯 PHPUnit 环境里会立刻报错。
     */
    public function testTransitIsIdempotentWhenFromEqualsTo(): void
    {
        $order = $this->makeOrder(OrderStatus::PAID);

        $this->machine->transit(
            $order,
            toStatus: OrderStatus::PAID,
            operatorType: OperatorType::SYSTEM,
        );

        // 幂等短路：状态未被修改，无异常抛出即通过
        $this->assertSame(OrderStatus::PAID, (int) $order->status);
    }

    /**
     * 守卫 2 的边界：即使是终态自环，也走幂等短路（不抛）
     *
     * 这避免了并发场景下两次把同一订单关闭抛一堆误报。
     */
    public function testTransitIsIdempotentForTerminalSelfLoop(): void
    {
        $order = $this->makeOrder(OrderStatus::CLOSED);

        $this->machine->transit($order, OrderStatus::CLOSED, OperatorType::SYSTEM);

        $this->assertSame(OrderStatus::CLOSED, (int) $order->status);
    }

    /**
     * 守卫 3：跨段跳跃 —— PENDING_PAY 不允许直接 SHIPPED
     */
    public function testTransitRejectsPendingToShipped(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING_PAY);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('订单状态不允许从「待支付」流转到「已发货」');

        $this->machine->transit($order, OrderStatus::SHIPPED, OperatorType::ADMIN, 1);
    }

    /**
     * 守卫 3：终态禁出 —— COMPLETED 不得流转回任何状态
     */
    public function testTransitRejectsTransitionFromCompleted(): void
    {
        $order = $this->makeOrder(OrderStatus::COMPLETED);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/从「已完成」流转到「.+」/');

        $this->machine->transit($order, OrderStatus::PAID, OperatorType::SYSTEM);
    }

    /**
     * 守卫 3：终态禁出 —— CLOSED 不得流转回任何状态
     */
    public function testTransitRejectsTransitionFromClosed(): void
    {
        $order = $this->makeOrder(OrderStatus::CLOSED);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/从「已关闭」流转到「.+」/');

        $this->machine->transit($order, OrderStatus::PAID, OperatorType::SYSTEM);
    }

    /**
     * 守卫 3：SHIPPED 不允许回到 PAID（回滚类动作必须走售后单，不走状态机）
     */
    public function testTransitRejectsShippedBackToPaid(): void
    {
        $order = $this->makeOrder(OrderStatus::SHIPPED);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('订单状态不允许从「已发货」流转到「已支付」');

        $this->machine->transit($order, OrderStatus::PAID, OperatorType::ADMIN);
    }

    /**
     * 异常信息必须包含 from / to 的中文文案，便于前端和日志排障
     */
    public function testTransitExceptionCarriesFromAndToText(): void
    {
        $order = $this->makeOrder(OrderStatus::PAID);

        try {
            $this->machine->transit($order, OrderStatus::RECEIVED, OperatorType::ADMIN);
            $this->fail('预期抛出 BusinessException');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('已支付', $e->getMessage());
            $this->assertStringContainsString('已收货', $e->getMessage());
        }
    }

    /**
     * 构造一个仅用于测试守卫的 Order
     *
     * ThinkPHP 8 的 Model 把实例数据放在 private static WeakMap 里，而不是对象属性。
     * 这里做两件事：
     *  1) newInstanceWithoutConstructor() 跳过 __construct，避免 getFields() 触发 DB 连接
     *  2) 反射拿到 \think\Model::$weakMap 静态，手动铺一份最小条目，其中 data.status 预填
     *     → OrderStatusMachine::transit() 里 $order->status 命中 data 短路，__get 不会再去查 schema
     */
    private function makeOrder(int $status): Order
    {
        $ref = new \ReflectionClass(Order::class);
        /** @var Order $order */
        $order = $ref->newInstanceWithoutConstructor();

        $weakMapProp = new \ReflectionProperty(\think\Model::class, 'weakMap');
        $weakMapProp->setAccessible(true);
        /** @var \WeakMap|null $weakMap */
        $weakMap = $weakMapProp->getValue();
        if ($weakMap === null) {
            $weakMap = new \WeakMap();
            $weakMapProp->setValue(null, $weakMap);
        }

        // 最小可用条目：只暴露 data/status，其它键给空数组即可
        $weakMap[$order] = [
            'data'     => ['status' => $status],
            'origin'   => [],
            'get'      => [],
            'schema'   => ['status' => 'int'],
            'type'     => [],
            'disuse'   => [],
            'hidden'   => [],
            'visible'  => [],
            'append'   => [],
            'mapping'  => [],
            'readonly' => [],
            'withAttr' => [],
            'relation' => [],
        ];

        return $order;
    }
}
