<?php

declare(strict_types=1);

namespace app\service\client\payment;

use EasyWeChat\Pay\Application as PayApplication;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * EasyWeChat Pay 薄包装
 *
 * 存在意义：
 *  - 把对 EasyWeChat 的所有调用收敛到本类的几个明确方法
 *  - 测试时只需 stub 本类（构造一个返回固定数组的子类），不必 mock 整个 SDK 链
 *  - 不在此类内部缓存任何 SDK 实例，每个方法接收 Application 形参由调用方传入
 *
 * 与 Factory 的边界：
 *  - Factory 负责「构造一个 Application」
 *  - Client 负责「用 Application 发请求并把响应规范化为数组」
 */
class WechatPayClient
{
    /**
     * JSAPI 预下单（小程序 / 公众号）
     *
     * @param array<string, mixed> $payload
     * @return array{prepay_id:string}
     */
    public function jsapiPrepay(PayApplication $app, array $payload): array
    {
        return $this->postJson($app, '/v3/pay/transactions/jsapi', $payload, 'prepay_id');
    }

    /**
     * H5 预下单（外部浏览器）
     *
     * @param array<string, mixed> $payload
     * @return array{h5_url:string}
     */
    public function h5Prepay(PayApplication $app, array $payload): array
    {
        return $this->postJson($app, '/v3/pay/transactions/h5', $payload, 'h5_url');
    }

    /**
     * 主动查单（兜底用，notify 丢失时调用）
     *
     * @return array<string, mixed>
     */
    public function queryByOutTradeNo(PayApplication $app, string $mchId, string $outTradeNo): array
    {
        try {
            $response = $app->getClient()->get(
                '/v3/pay/transactions/out-trade-no/' . rawurlencode($outTradeNo),
                ['query' => ['mchid' => $mchId]]
            );
            $body = (string) $response->getContent(false);
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            throw new BusinessException('微信查单失败：' . $e->getMessage());
        }
    }

    /**
     * 微信退款（V3）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function refund(PayApplication $app, array $payload): array
    {
        return $this->postJson($app, '/v3/refund/domestic/refunds', $payload, 'refund_id');
    }

    /**
     * 按商户退款单号查询微信退款结果
     *
     * @return array<string, mixed>
     */
    public function queryRefundByOutRefundNo(PayApplication $app, string $outRefundNo): array
    {
        try {
            $response = $app->getClient()->get(
                '/v3/refund/domestic/refunds/' . rawurlencode($outRefundNo)
            );
            $body = (string) $response->getContent(false);
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            throw new BusinessException('微信退款查询失败：' . $e->getMessage());
        }
    }

    /**
     * 生成 JSAPI 五元组（小程序 / 公众号 调起支付参数）
     *
     * @return array{appId:string, timeStamp:string, nonceStr:string, package:string, signType:string, paySign:string}
     */
    public function buildJsapiSignature(PayApplication $app, string $prepayId, string $appId): array
    {
        $config = $app->getUtils()->buildBridgeConfig($prepayId, $appId);
        return [
            'appId'     => (string) ($config['appId'] ?? $appId),
            'timeStamp' => (string) ($config['timeStamp'] ?? ''),
            'nonceStr'  => (string) ($config['nonceStr'] ?? ''),
            'package'   => (string) ($config['package'] ?? 'prepay_id=' . $prepayId),
            'signType'  => (string) ($config['signType'] ?? 'RSA'),
            'paySign'   => (string) ($config['paySign'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(
        PayApplication $app,
        string $uri,
        array $payload,
        string $expectedKey
    ): array {
        try {
            $response = $app->getClient()->postJson($uri, $payload);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getContent(false);
            $decoded = json_decode($body, true);
        } catch (Throwable $e) {
            throw new BusinessException('微信支付请求异常：' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new BusinessException('微信支付返回报文非法');
        }

        if ($statusCode >= 400 || !isset($decoded[$expectedKey])) {
            $code = (string) ($decoded['code'] ?? 'UNKNOWN');
            $message = (string) ($decoded['message'] ?? '微信支付请求失败');
            throw new BusinessException(sprintf('微信支付请求失败 [%s] %s', $code, $message));
        }

        return $decoded;
    }
}
