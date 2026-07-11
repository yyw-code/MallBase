<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('customer-service', function () {
    Route::post('context-token', 'contextToken');
})->prefix('client.CustomerServiceController/')->middleware([JwtAuth::class]);
