<?php

use app\client\middleware\JwtAuth;
use think\facade\Route;

// 买家订单（全部需登录）
Route::group('client/order', function () {
    Route::post('create', 'create');
    Route::post('pay/:sn', 'pay');
    Route::post('cancel/:id', 'cancel');
    Route::post('confirmReceive/:id', 'confirmReceive');
    Route::get('list', 'list');
    Route::get('detail/:id', 'detail');
})->prefix('order.OrderController/')->middleware([JwtAuth::class]);
