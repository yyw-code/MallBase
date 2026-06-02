<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\common\enum\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * 订单状态枚举单元测试
 *
 * 覆盖：
 *  - 状态文案映射完整性（6 个有效状态 + 未知兜底）
 *  - isValid / isTerminal 判定
 *  - canTransit 白名单完整覆盖（合法路径 + 典型非法路径 + 终态禁出）
 *  - options 前端下拉结构契约
 *
 * 设计意图：
 *  - 这是订单状态机的“真相来源”，白名单变更必须同步本测试以防止静默放宽
 *  - OrderStatusMachine 在 transit() 里依赖此枚举，因此此处覆盖等于给状态机兜底
 */
final class OrderStatusTest extends TestCase
{
    /**
     * 6 个有效状态全部命中文案，未知值降级为“未知”
     */
    public function testTextOfCoversAllKnownStates(): void
    {
        $this->assertSame('待支付', OrderStatus::textOf(OrderStatus::PENDING_PAY));
        $this->assertSame('已支付', OrderStatus::textOf(OrderStatus::PAID));
        $this->assertSame('已发货', OrderStatus::textOf(OrderStatus::SHIPPED));
        $this->assertSame('已收货', OrderStatus::textOf(OrderStatus::RECEIVED));
        $this->assertSame('已完成', OrderStatus::textOf(OrderStatus::COMPLETED));
        $this->assertSame('已关闭', OrderStatus::textOf(OrderStatus::CLOSED));
    }

    public function testTextOfUnknownReturnsFallback(): void
    {
        $this->assertSame('未知', OrderStatus::textOf(-1));
        $this->assertSame('未知', OrderStatus::textOf(999));
        $this->assertSame('未知', OrderStatus::textOf(15));
    }

    public function testIsValidForAllKnownStates(): void
    {
        $this->assertTrue(OrderStatus::isValid(OrderStatus::PENDING_PAY));
        $this->assertTrue(OrderStatus::isValid(OrderStatus::PAID));
        $this->assertTrue(OrderStatus::isValid(OrderStatus::SHIPPED));
        $this->assertTrue(OrderStatus::isValid(OrderStatus::RECEIVED));
        $this->assertTrue(OrderStatus::isValid(OrderStatus::COMPLETED));
        $this->assertTrue(OrderStatus::isValid(OrderStatus::CLOSED));
    }

    public function testIsValidRejectsUnknown(): void
    {
        $this->assertFalse(OrderStatus::isValid(-1));
        $this->assertFalse(OrderStatus::isValid(1));
        $this->assertFalse(OrderStatus::isValid(100));
    }

    public function testIsTerminalOnlyForCompletedAndClosed(): void
    {
        $this->assertTrue(OrderStatus::isTerminal(OrderStatus::COMPLETED));
        $this->assertTrue(OrderStatus::isTerminal(OrderStatus::CLOSED));
    }

    public function testIsTerminalRejectsInFlightStatuses(): void
    {
        $this->assertFalse(OrderStatus::isTerminal(OrderStatus::PENDING_PAY));
        $this->assertFalse(OrderStatus::isTerminal(OrderStatus::PAID));
        $this->assertFalse(OrderStatus::isTerminal(OrderStatus::SHIPPED));
        $this->assertFalse(OrderStatus::isTerminal(OrderStatus::RECEIVED));
    }

    /**
     * 白名单允许的流转全部打勾
     *
     * 白名单（与 OrderStatus::TRANSITIONS 同步）：
     *  - PENDING_PAY → PAID / CLOSED
     *  - PAID        → SHIPPED / CLOSED
     *  - SHIPPED     → RECEIVED / CLOSED（售后全量退款关闭）
     *  - RECEIVED    → COMPLETED / CLOSED（售后全量退款关闭）
     */
    public function testCanTransitAllowedEdges(): void
    {
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::PENDING_PAY, OrderStatus::PAID));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::PENDING_PAY, OrderStatus::CLOSED));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::PAID, OrderStatus::SHIPPED));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::PAID, OrderStatus::CLOSED));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::SHIPPED, OrderStatus::RECEIVED));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::SHIPPED, OrderStatus::CLOSED));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::RECEIVED, OrderStatus::COMPLETED));
        $this->assertTrue(OrderStatus::canTransit(OrderStatus::RECEIVED, OrderStatus::CLOSED));
    }

    /**
     * 典型非法路径：跨段跳跃不允许
     */
    public function testCanTransitRejectsCrossSegmentJumps(): void
    {
        // PENDING_PAY 不允许直接跳过 PAID 发货
        $this->assertFalse(OrderStatus::canTransit(OrderStatus::PENDING_PAY, OrderStatus::SHIPPED));
        $this->assertFalse(OrderStatus::canTransit(OrderStatus::PENDING_PAY, OrderStatus::RECEIVED));
        $this->assertFalse(OrderStatus::canTransit(OrderStatus::PENDING_PAY, OrderStatus::COMPLETED));
        // PAID 不允许直接完成
        $this->assertFalse(OrderStatus::canTransit(OrderStatus::PAID, OrderStatus::RECEIVED));
        $this->assertFalse(OrderStatus::canTransit(OrderStatus::PAID, OrderStatus::COMPLETED));
        $this->assertFalse(OrderStatus::canTransit(OrderStatus::SHIPPED, OrderStatus::COMPLETED));
    }

    /**
     * 终态禁出：COMPLETED / CLOSED 不得再流转到任何状态
     */
    public function testCanTransitRejectsFromTerminalStatuses(): void
    {
        foreach ([OrderStatus::PENDING_PAY, OrderStatus::PAID, OrderStatus::SHIPPED, OrderStatus::RECEIVED, OrderStatus::COMPLETED, OrderStatus::CLOSED] as $to) {
            $this->assertFalse(
                OrderStatus::canTransit(OrderStatus::COMPLETED, $to),
                sprintf('COMPLETED 不应允许流转到 %d', $to),
            );
            $this->assertFalse(
                OrderStatus::canTransit(OrderStatus::CLOSED, $to),
                sprintf('CLOSED 不应允许流转到 %d', $to),
            );
        }
    }

    /**
     * 未知 from 状态一律不允许流转
     */
    public function testCanTransitRejectsUnknownFrom(): void
    {
        $this->assertFalse(OrderStatus::canTransit(-1, OrderStatus::PAID));
        $this->assertFalse(OrderStatus::canTransit(999, OrderStatus::CLOSED));
    }

    /**
     * 自环不在白名单中（幂等由 OrderStatusMachine::transit() 单独短路）
     */
    public function testCanTransitRejectsSelfLoops(): void
    {
        foreach ([
            OrderStatus::PENDING_PAY,
            OrderStatus::PAID,
            OrderStatus::SHIPPED,
            OrderStatus::RECEIVED,
            OrderStatus::COMPLETED,
            OrderStatus::CLOSED,
        ] as $status) {
            $this->assertFalse(
                OrderStatus::canTransit($status, $status),
                sprintf('自环 %d->%d 不应出现在白名单', $status, $status),
            );
        }
    }

    /**
     * 前端下拉选项：6 个有效状态按定义顺序全量输出
     */
    public function testOptionsHasAllStatesInOrder(): void
    {
        $options = OrderStatus::options();

        $this->assertCount(6, $options);
        $expectedValues = [
            OrderStatus::PENDING_PAY,
            OrderStatus::PAID,
            OrderStatus::SHIPPED,
            OrderStatus::RECEIVED,
            OrderStatus::COMPLETED,
            OrderStatus::CLOSED,
        ];
        foreach ($options as $idx => $opt) {
            $this->assertArrayHasKey('value', $opt);
            $this->assertArrayHasKey('label', $opt);
            $this->assertSame($expectedValues[$idx], $opt['value']);
            $this->assertSame(OrderStatus::textOf($expectedValues[$idx]), $opt['label']);
        }
    }

    /**
     * 数值间隔必须保持 10 的倍数（方便后续在中间插入新状态）
     *
     * 这是一个显式契约测试 —— 如果后续有人把某个状态改成非 10 倍数（比如 15），
     * 测试会失败提醒重新评估数值分配。
     */
    public function testStatusValuesUseMultiplesOfTenExceptTerminal(): void
    {
        $this->assertSame(0, OrderStatus::PENDING_PAY);
        $this->assertSame(10, OrderStatus::PAID);
        $this->assertSame(20, OrderStatus::SHIPPED);
        $this->assertSame(30, OrderStatus::RECEIVED);
        $this->assertSame(40, OrderStatus::COMPLETED);
        // 90 为终态保留槽位，后续不再新增中间态
        $this->assertSame(90, OrderStatus::CLOSED);
    }
}
