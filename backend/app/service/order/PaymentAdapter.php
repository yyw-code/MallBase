<?php

declare(strict_types=1);

namespace app\service\order;

use app\service\order\dto\RefundPaymentContext;

/**
 * 支付渠道退款适配器
 *
 * 设计动机：
 *  - 售后审核通过后的资金结算抽象到该接口，业务层（RefundOrderAdminService）
 *    仅依赖契约，不感知具体渠道细节（微信/支付宝/银行卡）
 *  - 真实渠道实现必须使用稳定 out_refund_no 保证幂等
 *
 * 幂等语义：
 *  - 真实实现必须保证同一 out_refund_no 多次调用只产生一次实际退款
 *  - 返回渠道状态：SUCCESS / PROCESSING 等；失败或异常状态应抛 BusinessException
 *  - 渠道不可用/网络抖动等不确定状态应抛异常，交由调用方回滚事务
 */
interface PaymentAdapter
{
    /**
     * 发起退款
     */
    public function refund(RefundPaymentContext $context): string;
}
