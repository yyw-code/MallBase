<?php

use think\facade\Route;

// 客户端装修配置（无鉴权）
Route::group('decorate', function () {
    Route::get('config', 'config');
    Route::get('themes', 'themes');
})->prefix('client.DecorationController/');
