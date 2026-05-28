<?php

declare(strict_types=1);

namespace app\service\order\dto;

/**
 * 退款渠道请求上下文
 *
 * 金额使用「分」传递，避免元转分时出现浮点误差。
 */
final readonly class RefundPaymentContext
{
    public function __construct(
        public string $transactionId,
        public string $outRefundNo,
        public int $refundAmountCents,
        public int $totalAmountCents,
        public string $reason = '',
    ) {
    }
}
