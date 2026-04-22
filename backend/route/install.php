<?php

use app\middleware\InstallCheckMiddleware;
use think\facade\Route;

Route::group('install', function () {

    Route::get('/', function () {
        $path = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return response(file_get_contents($path), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        }
        abort(404, '安装页面未找到');
    });

    Route::group('api', function () {
        Route::get('status', 'status')->withoutMiddleware([InstallCheckMiddleware::class]);
        Route::get('adminReady', 'adminReady')->withoutMiddleware([InstallCheckMiddleware::class]);
        Route::get('check', 'check');
        Route::get('formDefaults', 'formDefaults');
        Route::post('testDb', 'testDb');
        Route::post('testRedis', 'testRedis');
        Route::post('execute', 'execute');
        Route::post('executeStream', 'executeStream');
    })->prefix('install.InstallController/');

});
