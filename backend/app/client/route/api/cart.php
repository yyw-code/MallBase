<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('cart', function () {
    Route::get('list', 'list');
    Route::post('add', 'add');
    Route::put('update/:id', 'update');
    Route::delete('delete', 'remove');
    Route::post('toggleSelected', 'toggleSelected');
})->prefix('order.CartController/')->middleware([JwtAuth::class]);
