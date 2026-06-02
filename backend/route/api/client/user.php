<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('user/auth', function () {
    // 登录(三种入口并存,手机号 + 短信验证码登录会自动建号)

    Route::post('login/username', 'loginByUsername');       // 用户名 + 密码
    Route::post('login/sms', 'loginBySms');                 // 手机号 + SMS
    Route::post('login', 'login');                          // 手机号 + 密码

    // 短信验证码下发(scene 由参数指定)
    Route::post('sms/send', 'sendSmsCode');

    // 微信小程序
    Route::post('wechat/bindMobile', 'bindMobile');                   // 手动绑定(force_mobile=false)
    Route::post('wechat/bindMobileByPhoneCode', 'bindMobileByPhoneCode'); // getPhoneNumber 兑换(force_mobile=true)
    Route::post('wechat/bindUserInfo', 'bindUserInfo');               // 头像昵称绑定(force_userinfo=true)
    Route::post('wechat', 'wechatLogin');

    // 微信公众号 OAuth(微信浏览器内打开网页)
    Route::post('wechat/official/oauthUrl', 'wechatOfficialOauthUrl');
    Route::post('wechat/official/bindMobile', 'wechatOfficialBindMobile');
    Route::post('wechat/official', 'wechatOfficialLogin');
})->prefix('client.user.UserController/');

Route::group('user/my', function () {
    Route::get('info', 'getMyInfo');
    Route::put('info', 'updateMyInfo');
    Route::put('password', 'updateMyPassword');
    Route::post('logout', 'logout');
})->prefix('client.user.UserController/')->middleware([JwtAuth::class]);

Route::group('user/wallet', function () {
    Route::get('', 'info');
    Route::get('info', 'info');
    Route::get('logs', 'logs');
})->prefix('client.user.UserWalletController/')->middleware([JwtAuth::class]);

Route::group('user/address', function () {
    Route::get('list', 'list');
    Route::get('info/:id', 'info');
    Route::post('create', 'create');
    Route::put('update/:id', 'update');
    Route::delete('delete/:id', 'delete');
    Route::put('setDefault/:id', 'setDefault');
})->prefix('client.user.UserAddressController/')->middleware([JwtAuth::class]);
