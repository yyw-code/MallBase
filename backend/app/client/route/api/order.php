<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('order', function () {
    Route::post('create', 'create');
    Route::post('pay/:sn', 'pay');
    Route::post('cancel/:id', 'cancel');
    Route::post('confirmReceive/:id', 'confirmReceive');
    Route::get('list', 'list');
    Route::get('detail/:id', 'detail');
})->prefix('order.OrderController/')->middleware([JwtAuth::class]);
