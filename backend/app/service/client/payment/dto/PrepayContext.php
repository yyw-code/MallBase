<?php

declare(strict_types=1);

namespace app\service\client\payment\dto;

/**
 * Prepay 调用上下文（readonly DTO）
 *
 * 由 PrepayService 组装并传给具体 PrepayAdapter，承载本次出账所需的全部输入，
 * 避免 Adapter 反查数据库或读上下文。
 *
 * 不可变设计：所有属性 readonly，跨方法传递不会被改写。
 */
final class PrepayContext
{
    public function __construct(
        /** 订单 ID（用于回写日志） */
        public readonly int $orderId,
        /** 订单号（mb_order.sn） */
        public readonly string $orderSn,
        /** 商户单号（{sn}-{6 位随机}，本次调用唯一） */
        public readonly string $outTradeNo,
        /** 支付场景，参见 {@see \app\common\enum\PayScene} */
        public readonly int $scene,
        /** 金额（分） */
        public readonly int $amountCents,
        /** 商品描述（脱敏后展示给用户） */
        public readonly string $description,
        /** 付款人 openid（mini/offi 必填，h5 传空字符串） */
        public readonly string $payerOpenid,
        /** 客户端 IP（h5 必填，mini/offi 可空） */
        public readonly string $clientIp,
        /** 回调通知地址（含 https://） */
        public readonly string $notifyUrl,
        /** prepay 过期时间（ISO 8601） */
        public readonly string $expireAt,
    ) {
    }
}
