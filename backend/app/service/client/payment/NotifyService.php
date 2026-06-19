<?php

declare(strict_types=1);

namespace app\service\client\payment;

use app\common\enum\PayMethod;
use app\model\order\Order;
use app\model\order\PaymentLog;
use app\service\admin\order\RefundOrderAdminService;
use app\service\client\order\OrderService;
use EasyWeChat\Kernel\Message as EasyWechatMessage;
use EasyWeChat\Pay\Application as PayApplication;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\log\Logger;
use Nyholm\Psr7\ServerRequest as PsrServerRequest;
use think\facade\Cache;
use think\facade\Event;
use Throwable;

/**
 * 支付回调处理
 *
 * 处理顺序（严格按 .codex/skills/thinkPHP/payment-notify-idempotency）：
 *  1) 构造 PSR-7 请求 → SDK Validator 验签
 *  2) SDK Server.getRequestMessage() 解密 resource.ciphertext
 *  3) Redis setNX 防重放（5min 窗口）
 *  4) 查 mb_payment_log → 比对 amount.total 与订单金额（逐分）
 *  5) 事务内：写 PAID 日志（唯一索引兜底）+ 状态机转 PAID
 *  6) 事件派发交给监听器异步告警，禁止在事务内发短信
 *
 * 返回：[status:int, body:array{code:string, message:string}]，由 Controller 直接回给微信
 */
class NotifyService extends BaseService
{
    protected string $modelClass = PaymentLog::class;

    /** Redis nonce 防重放窗口（秒） */
    private const NONCE_TTL = 300;

    /** 告警事件名 */
    private const EVENT_VERIFY_FAILED   = 'payment.verify_failed';
    private const EVENT_AMOUNT_MISMATCH = 'payment.amount_mismatch';
    private const EVENT_REPLAY_ATTACK   = 'payment.replay_attack';

    public function __construct(
        private readonly WechatPayFactory $factory,
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * 处理回调（输入 = 原始 HTTP 报文）
     *
     * @param array<string, string|array<string>> $headers 原始 HTTP 头（含 Wechatpay-Signature 等）
     * @param string                              $rawBody 原始请求体（必须未经任何 trim/decode）
     * @return array{status:int, body:array{code:string, message:string}}
     */
    public function handle(array $headers, string $rawBody): array
    {
        // Step 0: 构造 PSR-7 请求
        $psrRequest = new PsrServerRequest(
            'POST',
            '/api/notify/wechat/pay',
            $headers,
            $rawBody
        );

        // Step 1: SDK 构造 + 验签（密钥从 mb_setting 每次重新读取）
        try {
            $app = $this->factory->build();
        } catch (BusinessException $e) {
            Logger::instance()
                ->withData(['error' => $e->getMessage()])
                ->critical('微信支付回调配置缺失');
            $this->fireAlert(self::EVENT_VERIFY_FAILED, ['reason' => 'config_missing', 'message' => $e->getMessage()]);
            return $this->respond(500, 'FAIL', '支付配置缺失');
        }

        try {
            $app->getValidator()->validate($psrRequest);
        } catch (Throwable $e) {
            $serial = $this->headerValue($psrRequest, 'Wechatpay-Serial');
            Logger::instance()->critical('微信支付回调验签失败', [
                'reason'  => $e->getMessage(),
                'serial'  => $serial,
            ]);
            $this->fireAlert(self::EVENT_VERIFY_FAILED, [
                'reason'  => $e->getMessage(),
                'serial'  => $serial,
            ]);
            return $this->respond(401, 'FAIL', '签名错误');
        }

        // Step 2: 解密报文
        try {
            $message = $app->getServer()->getRequestMessage($psrRequest);
        } catch (Throwable $e) {
            Logger::instance()->critical('微信支付回调解密失败', ['error' => $e->getMessage()]);
            return $this->respond(500, 'FAIL', '报文解密失败');
        }

        $attributes = $this->decryptedAttributes($message);
        // 解密后明文字段
        $outTradeNo    = (string) ($attributes['out_trade_no']    ?? '');
        $transactionId = (string) ($attributes['transaction_id']  ?? '');
        $tradeState    = (string) ($attributes['trade_state']     ?? '');
        $payerOpenid   = (string) ($attributes['payer']['openid'] ?? '');
        $amountTotal   = (int)    ($attributes['amount']['total'] ?? 0);
        $successTime   = (string) ($attributes['success_time']    ?? '');

        Logger::instance()
            ->withData([
                'out_trade_no'   => $outTradeNo,
                'transaction_id' => $transactionId,
                'trade_state'    => $tradeState,
                'amount_total'   => $amountTotal,
                'success_time'   => $successTime,
            ])
            ->info('微信支付回调报文已解密');

        // Step 3: Redis nonce 防重放
        $nonce = $this->headerValue($psrRequest, 'Wechatpay-Nonce');
        if ($nonce !== '' && !$this->markNonce($nonce)) {
            Logger::instance()->critical('微信支付回调被识别为重放攻击', [
                'nonce'        => $nonce,
                'out_trade_no' => $outTradeNo,
            ]);
            $this->fireAlert(self::EVENT_REPLAY_ATTACK, [
                'nonce'        => $nonce,
                'out_trade_no' => $outTradeNo,
            ]);
            return $this->respond(401, 'FAIL', '重放攻击');
        }

        if ($outTradeNo === '') {
            Logger::instance()
                ->withData([
                    'transaction_id' => $transactionId,
                    'trade_state'    => $tradeState,
                    'amount_total'   => $amountTotal,
                ])
                ->critical('微信支付回调缺少 out_trade_no');
            return $this->respond(500, 'FAIL', 'out_trade_no 缺失');
        }

        // 非 SUCCESS 状态：仅落日志，不转单（让微信继续重试，或不重试取决于状态语义）
        if ($tradeState !== 'SUCCESS') {
            $this->persistFailureLog($outTradeNo, $transactionId, $tradeState, $amountTotal, $attributes);
            return $this->respond(200, 'SUCCESS', '成功'); // 已收到，不要求微信重试
        }

        // Step 4: 查 prepay 行 + 金额比对
        /** @var PaymentLog|null $prepay */
        $prepay = PaymentLog::where('out_trade_no', $outTradeNo)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->find();
        if ($prepay === null) {
            /** @var PaymentLog|null $matched */
            $matched = PaymentLog::where('out_trade_no', $outTradeNo)->order('id', 'desc')->find();
            Logger::instance()->critical('微信支付回调命中非活跃预支付流水', [
                'out_trade_no' => $outTradeNo,
                'event_type'   => $matched !== null ? (string) $matched->event_type : 'UNKNOWN',
                'order_id'     => $matched !== null ? (int) $matched->order_id : 0,
            ]);
            return $this->respond(500, 'FAIL', '支付流水已关闭或被顶替');
        }

        if ((int) $prepay->amount_cents !== $amountTotal) {
            Logger::instance()->critical('微信支付回调金额校验失败', [
                'out_trade_no' => $outTradeNo,
                'expected'     => (int) $prepay->amount_cents,
                'actual'       => $amountTotal,
            ]);
            $this->fireAlert(self::EVENT_AMOUNT_MISMATCH, [
                'out_trade_no' => $outTradeNo,
                'expected'     => (int) $prepay->amount_cents,
                'actual'       => $amountTotal,
            ]);
            return $this->respond(500, 'FAIL', '金额校验失败');
        }

        // Step 5: 幂等落库 + 转单（事务内）
        try {
            $this->transaction(function () use ($prepay, $transactionId, $tradeState, $attributes, $successTime, $payerOpenid): void {
                // 插入 PAID 日志，依赖 (transaction_id, event_type) 唯一索引兜底幂等
                try {
                    $paidLog = new PaymentLog();
                    $paidLog->order_id      = (int) $prepay->order_id;
                    $paidLog->order_sn      = (string) $prepay->order_sn;
                    $paidLog->out_trade_no  = $this->derivedPaidOutTradeNo((string) $prepay->out_trade_no);
                    $paidLog->transaction_id = $transactionId;
                    $paidLog->pay_method    = (int) $prepay->pay_method;
                    $paidLog->scene         = (int) $prepay->scene;
                    $paidLog->event_type    = PaymentLog::EVENT_PAID;
                    $paidLog->trade_state   = $tradeState;
                    $paidLog->amount_cents  = (int) $prepay->amount_cents;
                    $paidLog->payer_openid  = $payerOpenid !== '' ? $payerOpenid : $prepay->payer_openid;
                    $paidLog->raw_notify    = $attributes;
                    $paidLog->paid_at       = $successTime !== '' ? date('Y-m-d H:i:s', strtotime($successTime)) : date('Y-m-d H:i:s');
                    $paidLog->save();
                } catch (Throwable $e) {
                    // 唯一索引冲突 = 已处理过的回调，仍继续幂等确认订单状态
                    if (!$this->isDuplicateKey($e)) {
                        throw $e;
                    }
                }

                // 状态机转 PAID（OrderStatusMachine 内部对「已 PAID 重复流转」是幂等的）
                $this->orderService->confirmPaid(
                    sn: (string) $prepay->order_sn,
                    transactionId: $transactionId,
                    payMethod: (int) $prepay->pay_method,
                    payScene: (int) $prepay->scene,
                );
            });
        } catch (Throwable $e) {
            Logger::instance()->critical('微信支付回调落库失败', [
                'out_trade_no'   => $outTradeNo,
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
            return $this->respond(500, 'FAIL', '处理异常');
        }

        return $this->respond(200, 'SUCCESS', '成功');
    }

    /**
     * 处理微信退款回调
     *
     * @param array<string, string|array<string>> $headers
     * @return array{status:int, body:array{code:string, message:string}}
     */
    public function handleRefund(array $headers, string $rawBody): array
    {
        $psrRequest = new PsrServerRequest(
            'POST',
            '/api/notify/wechat/refund',
            $headers,
            $rawBody
        );

        try {
            $app = $this->factory->build();
        } catch (BusinessException $e) {
            Logger::instance()
                ->withData(['error' => $e->getMessage()])
                ->critical('微信退款回调配置缺失');
            $this->fireAlert(self::EVENT_VERIFY_FAILED, ['reason' => 'config_missing', 'message' => $e->getMessage()]);
            return $this->respond(500, 'FAIL', '支付配置缺失');
        }

        try {
            $app->getValidator()->validate($psrRequest);
        } catch (Throwable $e) {
            Logger::instance()->critical('微信退款回调验签失败', ['reason' => $e->getMessage()]);
            $this->fireAlert(self::EVENT_VERIFY_FAILED, ['reason' => $e->getMessage()]);
            return $this->respond(401, 'FAIL', '签名错误');
        }

        try {
            $message = $app->getServer()->getRequestMessage($psrRequest);
        } catch (Throwable $e) {
            Logger::instance()->critical('微信退款回调解密失败', ['error' => $e->getMessage()]);
            return $this->respond(500, 'FAIL', '报文解密失败');
        }

        $attributes = $this->decryptedAttributes($message);
        $outRefundNo = (string) ($attributes['out_refund_no'] ?? '');
        $refundStatus = (string) ($attributes['refund_status'] ?? $attributes['status'] ?? '');
        $refundAmount = (int) ($attributes['amount']['refund'] ?? $attributes['amount']['payer_refund'] ?? 0);
        $successTime = (string) ($attributes['success_time'] ?? '');

        Logger::instance()
            ->withData([
                'out_refund_no' => $outRefundNo,
                'refund_status' => $refundStatus,
                'refund_amount' => $refundAmount,
                'success_time'  => $successTime,
            ])
            ->info('微信退款回调报文已解密');

        $nonce = $this->headerValue($psrRequest, 'Wechatpay-Nonce');
        if ($nonce !== '' && !$this->markNonce($nonce)) {
            Logger::instance()->critical('微信退款回调被识别为重放攻击', [
                'nonce'         => $nonce,
                'out_refund_no' => $outRefundNo,
            ]);
            $this->fireAlert(self::EVENT_REPLAY_ATTACK, [
                'nonce'         => $nonce,
                'out_refund_no' => $outRefundNo,
            ]);
            return $this->respond(401, 'FAIL', '重放攻击');
        }

        if ($outRefundNo === '') {
            Logger::instance()
                ->withData([
                    'refund_status' => $refundStatus,
                    'refund_amount' => $refundAmount,
                ])
                ->critical('微信退款回调缺少 out_refund_no');
            return $this->respond(500, 'FAIL', 'out_refund_no 缺失');
        }

        if ($refundStatus !== 'SUCCESS') {
            Logger::instance()->error('微信退款回调状态非成功', [
                'out_refund_no' => $outRefundNo,
                'status'        => $refundStatus,
            ]);
            return $this->respond(200, 'SUCCESS', '成功');
        }

        try {
            /** @var RefundOrderAdminService $refundService */
            $refundService = app()->make(RefundOrderAdminService::class);
            $refundService->completeWechatRefund($outRefundNo, $refundAmount, $successTime);
        } catch (Throwable $e) {
            Logger::instance()->critical('微信退款回调落库失败', [
                'out_refund_no' => $outRefundNo,
                'refund_status' => $refundStatus,
                'refund_amount' => $refundAmount,
                'success_time'  => $successTime,
                'exception'     => get_class($e),
                'error'         => $e->getMessage(),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            return $this->respond(500, 'FAIL', '处理异常');
        }

        return $this->respond(200, 'SUCCESS', '成功');
    }

    /**
     * Redis SETNX 防重放
     */
    private function markNonce(string $nonce): bool
    {
        try {
            $handler = Cache::handler();
            if (is_object($handler) && method_exists($handler, 'set')) {
                // Phpredis: set($key, $value, ['NX', 'EX' => ttl])
                $ok = $handler->set('wxpay:nonce:' . $nonce, '1', ['NX', 'EX' => self::NONCE_TTL]);
                return (bool) $ok;
            }
        } catch (Throwable) {
            // Redis 不可用时降级为「允许通过」，避免回调链路被中间件压垮
            return true;
        }
        return true;
    }

    /**
     * 非 SUCCESS 状态落日志（NOTPAY / CLOSED / USERPAYING / PAYERROR 等）
     *
     * @param array<string, mixed> $attributes
     */
    private function persistFailureLog(string $outTradeNo, string $transactionId, string $tradeState, int $amountTotal, array $attributes): void
    {
        try {
            $log = new PaymentLog();
            $log->out_trade_no  = $this->derivedPaidOutTradeNo($outTradeNo, $tradeState);
            $log->transaction_id = $transactionId !== '' ? $transactionId : null;
            $log->pay_method    = PayMethod::WECHAT;
            $log->scene         = 0; // 未知 scene 时填 0，仅用于审计
            $log->event_type    = $tradeState === 'CLOSED' ? PaymentLog::EVENT_CLOSED : 'OTHER';
            $log->trade_state   = $tradeState;
            $log->amount_cents  = $amountTotal;
            $log->raw_notify    = $attributes;
            $log->order_id      = 0;
            $log->order_sn      = '';
            $log->save();
        } catch (Throwable $e) {
            // 失败日志的落库失败不影响主链路，仅记录
            Logger::instance()->error('非 SUCCESS 回调落日志失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 派生 out_trade_no（PAID 日志不能与 PREPAY 同 out_trade_no，否则违反 uk_out_trade_no）
     *
     * 规则：原 out_trade_no 加事件后缀（最多 32 字符）
     */
    private function derivedPaidOutTradeNo(string $original, string $suffix = 'PAID'): string
    {
        $candidate = $original . '#' . $suffix;
        return mb_substr($candidate, 0, 32);
    }

    private function isDuplicateKey(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry');
    }

    private function headerValue(PsrServerRequest $request, string $name): string
    {
        $value = $request->getHeaderLine($name);
        return $value;
    }

    /**
     * EasyWeChat Pay\Message::getOriginalAttributes() 返回微信 V3 外层事件包
     * （resource/ciphertext），业务字段必须从 SDK 解密后的 attributes 读取。
     *
     * @return array<string, mixed>
     */
    private function decryptedAttributes(EasyWechatMessage $message): array
    {
        return $message->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fireAlert(string $event, array $payload): void
    {
        try {
            Event::trigger($event, $payload);
        } catch (Throwable $e) {
            Logger::instance()->error('支付告警事件派发失败', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{status:int, body:array{code:string, message:string}}
     */
    private function respond(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'body'   => ['code' => $code, 'message' => $message],
        ];
    }
}
