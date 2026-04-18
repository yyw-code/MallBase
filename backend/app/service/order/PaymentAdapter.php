<?php

declare(strict_types=1);

namespace app\service\order;

/**
 * 支付渠道适配器（骨架）
 *
 * 设计动机：
 *  - 售后审核通过后的资金结算抽象到该接口，业务层（RefundOrderAdminService）
 *    仅依赖契约，不感知具体渠道细节（微信/支付宝/银行卡/Mock）
 *  - MVP 使用 {@see MockPaymentAdapter}，同步返回成功
 *  - 真实渠道接入时：实现本接口 → 在 Service 层通过构造注入或 App::make 切换实现，
 *    业务代码与测试用例一行不动即可完成替换
 *
 * 幂等语义：
 *  - 真实实现必须保证同一 tradeNo 多次调用只产生一次实际退款
 *  - 返回 true 表示"已成功或已存在成功记录"，false 表示"确认失败"
 *  - 渠道不可用/网络抖动等不确定状态应抛异常，交由调用方回滚事务
 */
interface PaymentAdapter
{
    /**
     * 按原支付交易号发起退款
     *
     * @param string $tradeNo      原支付交易流水号（对应 mb_order.trade_no）
     * @param string $amount       退款金额（元，decimal 字符串以避免浮点误差）
     * @return bool                true=成功或已成功；false=渠道明确返回失败
     */
    public function refund(string $tradeNo, string $amount): bool;
}
