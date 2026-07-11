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

    /*
    |--------------------------------------------------------------------------
    | 客户端 H5 SPA 兜底
    |--------------------------------------------------------------------------
    | pathinfo 包含 client/ 前缀，需要 strip 后查找静态文件。
    | 非静态文件路径统一返回 client/index.html，让前端路由接管。
    */
    Route::miss(function () {
        $path = request()->pathinfo();
        $path = preg_replace('#^client/?#', '', $path);
        $publicPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR;

        $filePath = $publicPath . 'client' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($filePath)) {
            $mimeTypes = [
                'js'    => 'application/javascript',
                'mjs'   => 'application/javascript',
                'css'   => 'text/css',
                'svg'   => 'image/svg+xml',
                'png'   => 'image/png',
                'jpg'   => 'image/jpeg',
                'jpeg'  => 'image/jpeg',
                'gif'   => 'image/gif',
                'ico'   => 'image/x-icon',
                'woff'  => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'   => 'font/ttf',
                'eot'   => 'application/vnd.ms-fontobject',
                'json'  => 'application/json',
            ];

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = $mimeTypes[$ext] ?? mime_content_type($filePath);

            return response(file_get_contents($filePath), 200, [
                'Content-Type'  => $mimeType,
                // 不启用一年强缓存，避免客户端静态文件同名更新后浏览器继续使用旧文件。
                // 'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        $indexPath = $publicPath . 'client' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($indexPath)) {
            return response(file_get_contents($indexPath), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        }

        abort(404, '客户端 H5 页面未找到，请先构建 H5 前端');
    });

});
