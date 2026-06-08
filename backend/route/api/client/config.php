<?php

use think\facade\Route;

// 客户端公开配置（无鉴权）
// 路径：/client/api/setting/basic
Route::group('setting', function () {
    Route::get('basic', 'basic');
    Route::get('payMethods', 'payMethods');
    Route::get('rechargeMethods', 'rechargeMethods');
    Route::get('uploadConfig', 'uploadConfig');
})->prefix('client.ConfigController/');
