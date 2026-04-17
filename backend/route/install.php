<?php

use think\facade\Route;

Route::group('install/api', function () {
    Route::get('check', 'check');
    Route::post('test-db', 'testDb');
    Route::post('test-redis', 'testRedis');
    Route::post('execute', 'execute');
})->prefix('install/InstallController/');
