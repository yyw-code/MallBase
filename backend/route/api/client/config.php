<?php

use think\facade\Route;

// 客户端公开配置（无鉴权）
// 路径：/client/api/setting/basic
Route::group('setting', function () {
    Route::get('basic', 'basic');
})->prefix('client.ConfigController/');
