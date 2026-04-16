<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\common\enum\RefundOrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * 售后订单状态机 - 枚举层白名单单元测试
 *
 * transit() 的事务/落库路径依赖真实 MySQL，由后续集成测试覆盖。
 * 本类只锁死状态白名单，防止后续回归时意外放行新流转。
 *
 * MVP 允许的流转：
 *   PENDING(0)     → COMPLETED(10)  ｜管理员同意并 Mock 退款
 *   PENDING(0)     → REJECTED(20)   ｜管理员驳回
 *   PENDING(0)     → CLOSED(90)     ｜买家取消
 *
 * 除以上三条，其余任何流转都必须拒绝。
 */
final class RefundOrderStatusMachineTest extends TestCase
{
    /**
     * @return iterable<string, array{0:int, 1:int, 2:bool}>
     */
    public static function transitionMatrix(): iterable
    {
        // 允许的三条流转
        yield 'PENDING → COMPLETED (approve)' => [RefundOrderStatus::PENDING, RefundOrderStatus::COMPLETED, true];
        yield 'PENDING → REJECTED (reject)'   => [RefundOrderStatus::PENDING, RefundOrderStatus::REJECTED, true];
        yield 'PENDING → CLOSED (cancel)'     => [RefundOrderStatus::PENDING, RefundOrderStatus::CLOSED, true];

        // 非法流转：自环
        yield 'PENDING → PENDING (self)'      => [RefundOrderStatus::PENDING, RefundOrderStatus::PENDING, false];
        yield 'COMPLETED → COMPLETED (self)'  => [RefundOrderStatus::COMPLETED, RefundOrderStatus::COMPLETED, false];

        // 非法流转：终态 → 任意
        yield 'COMPLETED → PENDING'           => [RefundOrderStatus::COMPLETED, RefundOrderStatus::PENDING, false];
        yield 'COMPLETED → REJECTED'          => [RefundOrderStatus::COMPLETED, RefundOrderStatus::REJECTED, false];
        yield 'REJECTED → COMPLETED'          => [RefundOrderStatus::REJECTED, RefundOrderStatus::COMPLETED, false];
        yield 'REJECTED → PENDING'            => [RefundOrderStatus::REJECTED, RefundOrderStatus::PENDING, false];
        yield 'CLOSED → PENDING'              => [RefundOrderStatus::CLOSED, RefundOrderStatus::PENDING, false];
        yield 'CLOSED → COMPLETED'            => [RefundOrderStatus::CLOSED, RefundOrderStatus::COMPLETED, false];

        // 非法流转：PENDING → 保留态（MVP 不启用 APPROVED/REFUNDING）
        yield 'PENDING → APPROVED (reserved)'  => [RefundOrderStatus::PENDING, RefundOrderStatus::APPROVED, false];
        yield 'PENDING → REFUNDING (reserved)' => [RefundOrderStatus::PENDING, RefundOrderStatus::REFUNDING, false];

        // 非法流转：保留态回 PENDING
        yield 'APPROVED → PENDING'   => [RefundOrderStatus::APPROVED, RefundOrderStatus::PENDING, false];
        yield 'REFUNDING → PENDING'  => [RefundOrderStatus::REFUNDING, RefundOrderStatus::PENDING, false];
    }

    /**
     * @dataProvider transitionMatrix
     */
    public function testCanTransitMatchesMatrix(int $from, int $to, bool $expected): void
    {
        $this->assertSame(
            $expected,
            RefundOrderStatus::canTransit($from, $to),
            sprintf('%s → %s 期望 %s', $from, $to, $expected ? '可流转' : '不可流转')
        );
    }

    public function testCanTransitRejectsInvalidFromStatus(): void
    {
        $this->assertFalse(RefundOrderStatus::canTransit(999, RefundOrderStatus::COMPLETED));
    }

    public function testCanTransitRejectsInvalidToStatus(): void
    {
        $this->assertFalse(RefundOrderStatus::canTransit(RefundOrderStatus::PENDING, 999));
    }

    public function testIsTerminalCoversAllThreeFinalStates(): void
    {
        $this->assertTrue(RefundOrderStatus::isTerminal(RefundOrderStatus::COMPLETED));
        $this->assertTrue(RefundOrderStatus::isTerminal(RefundOrderStatus::REJECTED));
        $this->assertTrue(RefundOrderStatus::isTerminal(RefundOrderStatus::CLOSED));
    }

    public function testIsTerminalReturnsFalseForPendingAndReservedStates(): void
    {
        $this->assertFalse(RefundOrderStatus::isTerminal(RefundOrderStatus::PENDING));
        $this->assertFalse(RefundOrderStatus::isTerminal(RefundOrderStatus::APPROVED));
        $this->assertFalse(RefundOrderStatus::isTerminal(RefundOrderStatus::REFUNDING));
    }

    public function testTerminalStatesNeverAllowOutgoingTransitions(): void
    {
        $terminal = [
            RefundOrderStatus::COMPLETED,
            RefundOrderStatus::REJECTED,
            RefundOrderStatus::CLOSED,
        ];
        $anyValid = [
            RefundOrderStatus::PENDING,
            RefundOrderStatus::APPROVED,
            RefundOrderStatus::REFUNDING,
            RefundOrderStatus::COMPLETED,
            RefundOrderStatus::REJECTED,
            RefundOrderStatus::CLOSED,
        ];

        foreach ($terminal as $from) {
            foreach ($anyValid as $to) {
                $this->assertFalse(
                    RefundOrderStatus::canTransit($from, $to),
                    sprintf('终态 %d 不应允许流转到 %d', $from, $to)
                );
            }
        }
    }
}
