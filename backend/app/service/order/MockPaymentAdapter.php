<?php

declare(strict_types=1);

namespace app\service\order;

/**
 * Mock 退款渠道实现
 *
 * 约束：
 *  - 仅用于 MVP 阶段打通"审核通过即退款完成"的闭环
 *  - 不做任何真实资金操作，始终返回 true
 *  - 真实渠道（微信/支付宝）接入后应删除或仅保留在测试环境
 *
 * @see PaymentAdapter
 */
final class MockPaymentAdapter implements PaymentAdapter
{
    public function refund(string $tradeNo, string $amount): bool
    {
        // MVP：模拟渠道侧瞬时完成，不落地任何副作用
        return true;
    }
}
