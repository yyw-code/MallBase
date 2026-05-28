<?php

use think\facade\Route;

/*
|--------------------------------------------------------------------------
| 第三方异步通知（白名单路由）
|--------------------------------------------------------------------------
|
| 约束（遵循 .codex/skills/thinkPHP/payment-notify-idempotency）：
|  - 路径不带 /client、/admin 前缀，避免被业务鉴权中间件覆盖
|  - 路由组**不挂任何 JWT / CSRF 中间件**
|  - 请求体由控制器直接交给 NotifyService，签名验证由 SDK 完成
|  - nginx / OpenResty 必须透传 Wechatpay-* 四个签名头，否则验签必失败
|
*/

Route::group('api/notify', function () {
    // 微信支付（覆盖小程序 / 公众号 / H5 三渠道，统一回调）
    Route::post('wechat/pay', 'client.order.PayNotifyController/wechat');
    // 微信退款（独立回调，避免与支付回调混用）
    Route::post('wechat/refund', 'client.order.PayNotifyController/wechatRefund');
});
