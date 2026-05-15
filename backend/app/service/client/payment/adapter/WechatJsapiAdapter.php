<?php

declare(strict_types=1);

namespace app\service\client\payment\adapter;

use app\common\enum\PayScene;
use app\service\client\payment\dto\PrepayContext;
use app\service\client\payment\dto\PrepayResult;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use app\service\order\PrepayAdapter;
use mall_base\exception\BusinessException;

/**
 * 微信 JSAPI 适配器（覆盖小程序 + 公众号）
 *
 * 两个场景共用 V3 同一接口，仅 AppID 来源不同：
 *  - PayScene::MINI → wechat_mini_appid
 *  - PayScene::OFFI → wechat_offi_appid
 *
 * 严格无状态：构造函数只装依赖，方法间不共享状态。
 */
class WechatJsapiAdapter implements PrepayAdapter
{
    public function __construct(
        private readonly WechatPayFactory $factory,
        private readonly WechatPayClient $client,
    ) {
    }

    public function prepay(PrepayContext $context): PrepayResult
    {
        if ($context->scene !== PayScene::MINI && $context->scene !== PayScene::OFFI) {
            throw new BusinessException('JSAPI 适配器仅支持小程序 / 公众号场景');
        }
        if ($context->payerOpenid === '') {
            throw new BusinessException(sprintf('%s 支付需要 openid', PayScene::textOf($context->scene)));
        }

        $appId = $this->factory->appIdOf($context->scene);
        if ($appId === '') {
            throw new BusinessException(sprintf(
                '%s AppID 未配置，请在后台「设置 → 微信配置」补全',
                PayScene::textOf($context->scene)
            ));
        }

        $app = $this->factory->build();

        $payload = [
            'appid'        => $appId,
            'mchid'        => (string) getSystemSetting('pay_wechat_mchid', ''),
            'description'  => mb_substr($context->description, 0, 127),
            'out_trade_no' => $context->outTradeNo,
            'notify_url'   => $context->notifyUrl,
            'time_expire'  => $context->expireAt,
            'amount'       => [
                'total'    => $context->amountCents,
                'currency' => 'CNY',
            ],
            'payer'        => [
                'openid'   => $context->payerOpenid,
            ],
        ];

        $response = $this->client->jsapiPrepay($app, $payload);
        $prepayId = (string) ($response['prepay_id'] ?? '');
        if ($prepayId === '') {
            throw new BusinessException('微信返回的 prepay_id 为空');
        }

        $jsapi = $this->client->buildJsapiSignature($app, $prepayId, $appId);

        return new PrepayResult(
            outTradeNo: $context->outTradeNo,
            prepayId: $prepayId,
            mwebUrl: '',
            payload: $jsapi,
        );
    }
}
