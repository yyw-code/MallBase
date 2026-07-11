<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('points/mall', function () {
    Route::get('list', 'list');
    Route::get('detail/:id', 'detail');
})->prefix('client.points.PointsMallController/');

Route::group('points/mall', function () {
    Route::post('exchange', 'exchange');
    Route::get('orders', 'orders');
    Route::get('order/:id', 'order');
    Route::post('order/:id/cancel', 'cancel');
})->prefix('client.points.PointsMallController/')->middleware([JwtAuth::class]);

Route::group('points', function () {
    Route::get('', 'info');
    Route::get('info', 'info');
    Route::get('logs', 'logs');
})->prefix('client.user.UserPointsController/')->middleware([JwtAuth::class]);
