<?php

use think\facade\Route;

Route::group('client', function () {

    Route::group('api/', function () {

        $apiDir = __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'client';
        foreach (glob($apiDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            require $file;
        }

        Route::miss(function () {
            return json([
                'code' => 404,
                'msg'  => '接口不存在',
                'data' => null,
            ]);
        });

    });

});
