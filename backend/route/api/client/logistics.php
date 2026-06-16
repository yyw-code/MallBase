<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('logistics', function () {
    Route::get('detail/:id', 'detail');
})->prefix('client.logistics.LogisticsController/')->middleware([JwtAuth::class]);
