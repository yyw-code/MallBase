<?php

declare(strict_types=1);

namespace app\service\client\payment;

use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use app\common\enum\PayScene;
use app\model\order\Order;
use app\model\order\PaymentLog;
use app\model\user\User;
use app\service\client\payment\adapter\WechatH5Adapter;
use app\service\client\payment\adapter\WechatJsapiAdapter;
use app\service\client\payment\dto\PrepayContext;
use app\service\client\payment\dto\PrepayResult;
use app\service\order\PrepayAdapter;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Request;

/**
 * 支付预下单门面
 *
 * 职责：
 *  - 校验订单状态与归属
 *  - 解析当前用户在目标 scene 下的 openid
 *  - 防重：复用 2 小时内同 scene 的有效 PREPAY 记录；切换 scene 时把旧记录置为 SUPERSEDED
 *  - 生成 out_trade_no = {sn}-{6位随机}（每次新建）
 *  - 选择 PrepayAdapter，落 mb_payment_log 记录
 *
 * 设计原则：
 *  - 「先校验再事务」：所有读校验在事务外完成
 *  - 无状态：所有依赖通过构造注入，方法内不缓存请求级数据
 *
 * @extends BaseService<PaymentLog>
 */
class PrepayService extends BaseService
{
    protected string $modelClass = PaymentLog::class;

    /** PREPAY 默认存活时间（秒）：2 小时，与微信侧 prepay_id 有效期一致 */
    private const PREPAY_TTL_SECONDS = 7200;

    public function __construct(
        private readonly WechatJsapiAdapter $jsapiAdapter,
        private readonly WechatH5Adapter $h5Adapter,
    ) {
    }

    /**
     * 发起预下单（按订单 ID 入参）
     *
     * 设计说明：
     *  - 路径参数使用订单 ID（数值、稳定主键、便于安全约束）
     *  - out_trade_no 仍由 order.sn 派生，保证日志、微信后台可读性
     *
     * @return array{out_trade_no:string, scene:int, prepay_id:string, mweb_url:string, payload:array<string,mixed>}
     */
    public function prepayById(int $userId, int $orderId, string $sceneCode): array
    {
        $scene = PayScene::fromCode($sceneCode);
        if ($scene === null) {
            throw new BusinessException('支付场景不合法');
        }

        $order = $this->loadPayableOrderById($userId, $orderId);
        $openid = $this->resolveOpenid($userId, $scene);

        // 防重：查同 scene + 未过期的 PREPAY
        $existing = $this->findReusablePrepay((int) $order->id, $scene);
        if ($existing !== null) {
            return $this->packResponse($existing);
        }

        // 切 scene：把当前 order 其它 scene 的活跃 PREPAY 全部置为 SUPERSEDED
        $this->supersedeOtherScenes((int) $order->id, $scene);

        $outTradeNo = $this->generateOutTradeNo($order->sn);
        $amountCents = $this->yuanToCents((string) $order->pay_amount);

        $context = new PrepayContext(
            orderId: (int) $order->id,
            orderSn: (string) $order->sn,
            outTradeNo: $outTradeNo,
            scene: $scene,
            amountCents: $amountCents,
            description: '订单 ' . $order->sn,
            payerOpenid: $openid,
            clientIp: $scene === PayScene::H5 ? $this->resolveClientIp() : '',
            notifyUrl: $this->buildNotifyUrl(),
            expireAt: $this->isoExpireAt(),
        );

        $adapter = $this->resolveAdapter($scene);
        $result = $adapter->prepay($context);

        $log = $this->persistPrepayLog($context, $result);

        return [
            'out_trade_no' => $log->out_trade_no,
            'scene'        => (int) $log->scene,
            'prepay_id'    => (string) ($log->prepay_id ?? ''),
            'mweb_url'     => (string) ($log->mweb_url ?? ''),
            'payload'      => $result->payload,
        ];
    }

    private function loadPayableOrderById(int $userId, int $orderId): Order
    {
        /** @var Order|null $order */
        $order = Order::where('id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在或不属于当前用户');
        }
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('订单已支付或已关闭');
        }
        if ($order->expire_at !== null && strtotime((string) $order->expire_at) < time()) {
            throw new BusinessException('订单已超时，请重新下单');
        }
        return $order;
    }

    private function resolveOpenid(int $userId, int $scene): string
    {
        if ($scene === PayScene::H5) {
            return '';
        }
        $field = match ($scene) {
            PayScene::MINI => 'wx_miniapp_openid',
            PayScene::OFFI => 'wx_official_openid',
            default        => throw new BusinessException('支付场景不合法'),
        };
        $user = User::where('id', $userId)->whereNull('delete_time')->find();
        if ($user === null) {
            throw new BusinessException('用户不存在');
        }
        $openid = trim((string) ($user->{$field} ?? ''));
        if ($openid === '') {
            throw new BusinessException(sprintf(
                '当前账号未绑定%s openid，无法发起支付',
                PayScene::textOf($scene)
            ));
        }
        return $openid;
    }

    private function findReusablePrepay(int $orderId, int $scene): ?PaymentLog
    {
        /** @var PaymentLog|null $existing */
        $existing = PaymentLog::where('order_id', $orderId)
            ->where('scene', $scene)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->where('expire_at', '>', date('Y-m-d H:i:s'))
            ->order('id', 'desc')
            ->find();
        return $existing;
    }

    private function supersedeOtherScenes(int $orderId, int $currentScene): void
    {
        PaymentLog::where('order_id', $orderId)
            ->where('scene', '<>', $currentScene)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->update(['event_type' => PaymentLog::EVENT_SUPERSEDED]);
    }

    private function persistPrepayLog(PrepayContext $ctx, PrepayResult $result): PaymentLog
    {
        $log = new PaymentLog();
        $log->order_id     = $ctx->orderId;
        $log->order_sn     = $ctx->orderSn;
        $log->out_trade_no = $ctx->outTradeNo;
        $log->pay_method   = PayMethod::WECHAT;
        $log->scene        = $ctx->scene;
        $log->event_type   = PaymentLog::EVENT_PREPAY;
        $log->amount_cents = $ctx->amountCents;
        $log->prepay_id    = $result->prepayId !== '' ? $result->prepayId : null;
        $log->mweb_url     = $result->mwebUrl !== '' ? $result->mwebUrl : null;
        $log->payer_openid = $ctx->payerOpenid !== '' ? $ctx->payerOpenid : null;
        $log->client_ip    = $ctx->clientIp !== '' ? $ctx->clientIp : null;
        $log->expire_at    = date('Y-m-d H:i:s', time() + self::PREPAY_TTL_SECONDS);
        $log->save();
        return $log;
    }

    /**
     * @return array{out_trade_no:string, scene:int, prepay_id:string, mweb_url:string, payload:array<string,mixed>}
     */
    private function packResponse(PaymentLog $log): array
    {
        $prepayId = (string) ($log->prepay_id ?? '');
        $mwebUrl  = (string) ($log->mweb_url ?? '');
        $scene    = (int) $log->scene;

        // 复用场景需要重新生成 JSAPI 签名（timeStamp/nonceStr 不能复用，否则前端调起失败）
        $payload = [];
        if ($prepayId !== '' && ($scene === PayScene::MINI || $scene === PayScene::OFFI)) {
            $factory = app()->make(WechatPayFactory::class);
            $client  = app()->make(WechatPayClient::class);
            $appId   = $factory->appIdOf($scene);
            $payload = $client->buildJsapiSignature($factory->build(), $prepayId, $appId);
        } elseif ($mwebUrl !== '') {
            $payload = ['mweb_url' => $mwebUrl];
        }

        return [
            'out_trade_no' => (string) $log->out_trade_no,
            'scene'        => $scene,
            'prepay_id'    => $prepayId,
            'mweb_url'     => $mwebUrl,
            'payload'      => $payload,
        ];
    }

    private function resolveAdapter(int $scene): PrepayAdapter
    {
        return match ($scene) {
            PayScene::MINI, PayScene::OFFI => $this->jsapiAdapter,
            PayScene::H5                   => $this->h5Adapter,
            default => throw new BusinessException('支付场景不合法'),
        };
    }

    private function generateOutTradeNo(string $sn): string
    {
        // {sn}-{6 位随机}，控制总长度 ≤ 32
        $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        return mb_substr($sn . '-' . $suffix, 0, 32);
    }

    private function yuanToCents(string $yuan): int
    {
        // 用字符串 + bcmath 规避浮点误差
        if (!extension_loaded('bcmath')) {
            return (int) round(((float) $yuan) * 100);
        }
        return (int) bcmul($yuan, '100', 0);
    }

    private function buildNotifyUrl(): string
    {
        $base = trim((string) getSystemSetting('site_url', ''));
        if ($base === '') {
            // 兜底：从当前请求拼
            $base = (Request::scheme() ?: 'https') . '://' . (Request::host(true) ?: 'localhost');
        }
        return rtrim($base, '/') . '/api/notify/wechat/pay';
    }

    private function resolveClientIp(): string
    {
        try {
            $ip = Request::ip();
            return $ip !== '' ? $ip : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function isoExpireAt(): string
    {
        return date('Y-m-d\TH:i:sP', time() + self::PREPAY_TTL_SECONDS);
    }
}
