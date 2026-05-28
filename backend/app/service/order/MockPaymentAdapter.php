<?php

declare(strict_types=1);

namespace app\service\order;

use app\service\order\dto\RefundPaymentContext;

/**
 * Mock 退款渠道实现
 *
 * 约束：
 *  - 仅用于测试或本地演示环境
 *  - 不做任何真实资金操作，始终返回 SUCCESS
 *  - 生产审核路径应使用真实渠道适配器
 *
 * @see PaymentAdapter
 */
class MockPaymentAdapter implements PaymentAdapter
{
    public function refund(RefundPaymentContext $context): string
    {
        // 模拟渠道侧瞬时完成，不落地任何副作用。
        return 'SUCCESS';
    }
}
