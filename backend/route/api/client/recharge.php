<?php

use think\facade\Route;

Route::group('recharge/package', function () {
    Route::get('list', 'list');
})->prefix('client.recharge.RechargePackageController/');
