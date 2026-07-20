<?php

declare(strict_types=1);

namespace app\service\client\payment;

use app\model\order\PaymentLog;
use app\service\client\order\OrderService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * 将已经验签、解密或主动查单确认的微信支付成功结果幂等落库。
 *
 * 验签、解密、防重放和协议应答仍属于 NotifyService；本服务只接收可信明文结果。
 * @extends BaseService<PaymentLog>
 */
class WechatPaymentResultService extends BaseService
{
    protected string $modelClass = PaymentLog::class;

    public function __construct(private readonly OrderService $orderService)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{applied:bool,duplicate:bool,transaction_id:string,out_trade_no:string}
     */
    public function applyVerifiedSuccess(
        array $payload,
        string $expectedMerchantId,
        ?string $expectedOutTradeNo = null,
    ): array {
        $merchantId = trim((string) ($payload['mchid'] ?? ''));
        $outTradeNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        $tradeState = strtoupper(trim((string) ($payload['trade_state'] ?? '')));
        $amountTotal = (int) ($payload['amount']['total'] ?? 0);
        $expectedMerchantId = trim($expectedMerchantId);
        $expectedOutTradeNo = $expectedOutTradeNo !== null ? trim($expectedOutTradeNo) : null;

        if ($tradeState !== 'SUCCESS') {
            throw new BusinessException('WECHAT_PAYMENT_RESULT_NOT_SUCCESS');
        }
        if ($expectedMerchantId === '' || $merchantId === '' || !hash_equals($expectedMerchantId, $merchantId)) {
            throw new BusinessException('WECHAT_PAYMENT_MERCHANT_MISMATCH');
        }
        if ($outTradeNo === '' || ($expectedOutTradeNo !== null && !hash_equals($expectedOutTradeNo, $outTradeNo))) {
            throw new BusinessException('WECHAT_PAYMENT_ORDER_MISMATCH');
        }
        if ($transactionId === '') {
            throw new BusinessException('WECHAT_PAYMENT_TRANSACTION_ID_MISSING');
        }
        if ($amountTotal <= 0) {
            throw new BusinessException('WECHAT_PAYMENT_AMOUNT_INVALID');
        }

        $prepay = $this->findActivePrepay($outTradeNo);
        if ($prepay === null) {
            throw new BusinessException('WECHAT_PAYMENT_PREPAY_NOT_ACTIVE');
        }
        if ((int) ($prepay['amount_cents'] ?? 0) !== $amountTotal) {
            throw new BusinessException('WECHAT_PAYMENT_AMOUNT_MISMATCH');
        }
        if ($this->paidResultExists($transactionId)) {
            return $this->result(false, true, $transactionId, $outTradeNo);
        }

        return $this->inTransaction(function () use ($prepay, $payload, $transactionId, $outTradeNo): array {
            if (!$this->appendPaidResult($prepay, $payload)) {
                return $this->result(false, true, $transactionId, $outTradeNo);
            }

            $this->confirmOrderPaid($prepay, $transactionId);

            return $this->result(true, false, $transactionId, $outTradeNo);
        });
    }

    /** @return array<string,mixed>|null */
    protected function findActivePrepay(string $outTradeNo): ?array
    {
        /** @var PaymentLog|null $prepay */
        $prepay = $this->model()
            ->where('out_trade_no', $outTradeNo)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->find();

        return $prepay?->toArray();
    }

    protected function paidResultExists(string $transactionId): bool
    {
        return $this->model()
            ->where('transaction_id', $transactionId)
            ->where('event_type', PaymentLog::EVENT_PAID)
            ->count() > 0;
    }

    /** @param array<string,mixed> $prepay @param array<string,mixed> $payload */
    protected function appendPaidResult(array $prepay, array $payload): bool
    {
        try {
            $paid = $this->model();
            $paid->order_id = (int) ($prepay['order_id'] ?? 0);
            $paid->order_sn = (string) ($prepay['order_sn'] ?? '');
            $paid->out_trade_no = $this->derivedPaidOutTradeNo((string) ($prepay['out_trade_no'] ?? ''));
            $paid->transaction_id = trim((string) ($payload['transaction_id'] ?? ''));
            $paid->pay_method = (int) ($prepay['pay_method'] ?? 0);
            $paid->scene = (int) ($prepay['scene'] ?? 0);
            $paid->event_type = PaymentLog::EVENT_PAID;
            $paid->trade_state = 'SUCCESS';
            $paid->amount_cents = (int) ($prepay['amount_cents'] ?? 0);
            $payerOpenid = trim((string) ($payload['payer']['openid'] ?? ''));
            $paid->payer_openid = $payerOpenid !== '' ? $payerOpenid : ($prepay['payer_openid'] ?? null);
            $paid->raw_notify = $payload;
            $successTime = trim((string) ($payload['success_time'] ?? ''));
            $paid->paid_at = $successTime !== '' ? date('Y-m-d H:i:s', strtotime($successTime)) : date('Y-m-d H:i:s');
            $paid->save();

            return true;
        } catch (Throwable $e) {
            if ($this->isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $prepay */
    protected function confirmOrderPaid(array $prepay, string $transactionId): void
    {
        $this->orderService->confirmPaid(
            sn: (string) ($prepay['order_sn'] ?? ''),
            transactionId: $transactionId,
            payMethod: (int) ($prepay['pay_method'] ?? 0),
            payScene: (int) ($prepay['scene'] ?? 0),
        );
    }

    protected function inTransaction(callable $callback): mixed
    {
        return $this->transaction($callback);
    }

    private function derivedPaidOutTradeNo(string $original): string
    {
        return mb_substr($original . '#PAID', 0, 32);
    }

    private function isDuplicateKey(Throwable $e): bool
    {
        return str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry');
    }

    /** @return array{applied:bool,duplicate:bool,transaction_id:string,out_trade_no:string} */
    private function result(bool $applied, bool $duplicate, string $transactionId, string $outTradeNo): array
    {
        return [
            'applied' => $applied,
            'duplicate' => $duplicate,
            'transaction_id' => $transactionId,
            'out_trade_no' => $outTradeNo,
        ];
    }
}
