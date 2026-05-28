<?php

declare(strict_types=1);

namespace app\service\order;

use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use app\service\order\dto\RefundPaymentContext;
use mall_base\exception\BusinessException;

/**
 * 微信支付 V3 退款适配器
 *
 * 使用 out_refund_no 作为微信侧幂等键；同一售后单重复请求不会产生多笔退款。
 */
class WechatRefundAdapter implements PaymentAdapter
{
    public function __construct(
        private readonly WechatPayFactory $factory,
        private readonly WechatPayClient $client,
    ) {
    }

    public function refund(RefundPaymentContext $context): string
    {
        if ($context->transactionId === '') {
            throw new BusinessException('微信支付交易号缺失，无法发起退款');
        }
        if ($context->outRefundNo === '') {
            throw new BusinessException('退款单号缺失，无法发起退款');
        }
        if ($context->refundAmountCents <= 0) {
            throw new BusinessException('退款金额必须大于 0');
        }
        if ($context->totalAmountCents <= 0) {
            throw new BusinessException('原订单支付金额必须大于 0');
        }
        if ($context->refundAmountCents > $context->totalAmountCents) {
            throw new BusinessException('退款金额不能超过原订单支付金额');
        }

        $payload = [
            'transaction_id' => $context->transactionId,
            'out_refund_no'  => $context->outRefundNo,
            'reason'         => mb_substr($context->reason, 0, 80),
            'amount'         => [
                'refund'   => $context->refundAmountCents,
                'total'    => $context->totalAmountCents,
                'currency' => 'CNY',
            ],
        ];

        $response = $this->client->refund($this->factory->build(), $payload);
        $status = strtoupper((string) ($response['status'] ?? ''));
        if (in_array($status, ['SUCCESS', 'PROCESSING'], true)) {
            return $status;
        }

        throw new BusinessException(sprintf('微信退款状态异常：%s', $status !== '' ? $status : 'UNKNOWN'));
    }
}
