<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('order', function () {
    Route::post('create', 'create');
    Route::post('preview', 'preview');
    Route::post('pay/:id', 'pay');
    Route::post('cancel/:id', 'cancel');
    Route::post('confirmReceive/:id', 'confirmReceive');
    Route::get('list', 'list');
    Route::get('detail/:id', 'detail');
})->prefix('client.order.OrderController/')->middleware([JwtAuth::class]);
