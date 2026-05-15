<?php

declare(strict_types=1);

namespace app\service\client\payment\dto;

/**
 * Prepay 调用结果（readonly DTO）
 *
 * 不同 scene 字段意义：
 *  - JSAPI（mini/offi）：prepayId 必填，payload 含 wx.requestPayment 五元组
 *  - MWEB（h5）：mwebUrl 必填，prepayId 为空，payload 含 {mweb_url}
 */
final class PrepayResult
{
    public function __construct(
        /** 商户单号（与 Context 一致，回传给上层用于写 payment_log） */
        public readonly string $outTradeNo,
        /** 微信 prepay_id（JSAPI 场景） */
        public readonly string $prepayId,
        /** H5 跳转地址（MWEB 场景） */
        public readonly string $mwebUrl,
        /**
         * 前端可直接消费的 payload，结构由 scene 决定：
         *   - JSAPI: ['appId','timeStamp','nonceStr','package','signType','paySign']
         *   - MWEB : ['mweb_url']
         *
         * @var array<string, mixed>
         */
        public readonly array $payload,
    ) {
    }
}
