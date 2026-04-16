<?php

use app\client\middleware\JwtAuth;
use think\facade\Route;

// 买家购物车（全部需登录）
Route::group('client/cart', function () {
    Route::get('list', 'list');
    Route::post('add', 'add');
    Route::put('update/:id', 'update');
    Route::delete('delete', 'remove');
    Route::post('toggleSelected', 'toggleSelected');
})->prefix('order.CartController/')->middleware([JwtAuth::class]);
