<?php

declare(strict_types=1);

namespace app\service\order;

use app\service\client\payment\dto\PrepayContext;
use app\service\client\payment\dto\PrepayResult;

/**
 * 支付预下单适配器契约
 *
 * 与 {@see PaymentAdapter}（退款）平级共存：
 *  - PrepayAdapter 负责「出账」：生成 prepay_id / mweb_url 返回给前端
 *  - PaymentAdapter 负责「退款」：售后审核通过后的资金回退
 *
 * 真实渠道（微信 JSAPI / H5 / 支付宝）实现本接口；测试用 stub 实现也通过本接口接入。
 *
 * 幂等语义：
 *  - 调用方（PrepayService）负责防重，本接口实现不强制保证 idempotent
 *  - 但实现应对「同 out_trade_no 重发」直接返回相同 prepay_id（微信侧本身保证）
 */
interface PrepayAdapter
{
    /**
     * 发起预下单
     *
     * @throws \mall_base\exception\BusinessException 配置缺失 / 渠道明确失败
     * @throws \Throwable                              渠道不可用 / 网络异常
     */
    public function prepay(PrepayContext $context): PrepayResult;
}
