<?php

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
        Route::get('status', 'status');
        Route::get('check', 'check');
        Route::get('form-defaults', 'formDefaults');
        Route::post('test-db', 'testDb');
        Route::post('test-redis', 'testRedis');
        Route::post('execute', 'execute');
        Route::post('execute-stream', 'executeStream');
    })->prefix('install.InstallController/');

});
