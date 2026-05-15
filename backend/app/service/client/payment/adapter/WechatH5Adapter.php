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
 * 微信 H5 适配器（trade_type=MWEB，外部浏览器场景）
 *
 * 与 JSAPI 关键差异：
 *  - 不需要 openid，但必须传 payer_client_ip
 *  - 需要传 scene_info.h5_info.type / app_name 否则会被拒
 *  - 微信侧返回 h5_url（mweb_url），客户端用 window.location.href 跳转
 *  - 商户后台需要单独配置「H5 支付域名」白名单，否则下单失败
 */
class WechatH5Adapter implements PrepayAdapter
{
    public function __construct(
        private readonly WechatPayFactory $factory,
        private readonly WechatPayClient $client,
    ) {
    }

    public function prepay(PrepayContext $context): PrepayResult
    {
        if ($context->scene !== PayScene::H5) {
            throw new BusinessException('H5 适配器仅支持 H5 场景');
        }
        if ($context->clientIp === '') {
            throw new BusinessException('H5 支付需要客户端 IP');
        }

        // H5 必须传 mch_id 配下的某个 AppID。复用小程序 AppID 即可（共用主体的常见做法）
        $appId = trim((string) getSystemSetting('wechat_mini_appid', ''));
        if ($appId === '') {
            $appId = trim((string) getSystemSetting('wechat_offi_appid', ''));
        }
        if ($appId === '') {
            throw new BusinessException('H5 支付需要先在后台「设置 → 微信配置」中配置至少一个 AppID');
        }

        $app = $this->factory->build();

        $payload = [
            'appid'             => $appId,
            'mchid'             => (string) getSystemSetting('pay_wechat_mchid', ''),
            'description'       => mb_substr($context->description, 0, 127),
            'out_trade_no'      => $context->outTradeNo,
            'notify_url'        => $context->notifyUrl,
            'time_expire'       => $context->expireAt,
            'amount'            => [
                'total'         => $context->amountCents,
                'currency'      => 'CNY',
            ],
            'scene_info'        => [
                'payer_client_ip' => $context->clientIp,
                'h5_info'         => [
                    'type'        => 'Wap',
                ],
            ],
        ];

        $response = $this->client->h5Prepay($app, $payload);
        $h5Url = (string) ($response['h5_url'] ?? '');
        if ($h5Url === '') {
            throw new BusinessException('微信返回的 h5_url 为空');
        }

        return new PrepayResult(
            outTradeNo: $context->outTradeNo,
            prepayId: '',
            mwebUrl: $h5Url,
            payload: ['mweb_url' => $h5Url],
        );
    }
}
