<?php

use think\facade\Route;
use app\middleware\admin\{
    JwtAuth,
    CheckPermission,
    AdminOperationLogMiddleware,
    RequestLockMiddleware
};

/*
|--------------------------------------------------------------------------
| 后台 API
|--------------------------------------------------------------------------
| MultiApp 已截断 admin/ 前缀，pathinfo 为 api/...
*/
Route::group('api/', function () {

    $apiDir = __DIR__ . DIRECTORY_SEPARATOR . 'api';
    foreach (glob($apiDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
        require $file;
    }

    // API 未匹配兜底
    Route::miss(function () {
        return json([
            'code' => 404,
            'msg' => '接口不存在',
            'data' => null,
        ]);
    });

})
    ->option([
        '_lock' => true,
        '_group_name' => '后台管理'
    ])
    ->middleware([
        JwtAuth::class,
        CheckPermission::class,
        RequestLockMiddleware::class,
        AdminOperationLogMiddleware::class,
    ]);

/*
|--------------------------------------------------------------------------
| 后台 SPA 兜底
|--------------------------------------------------------------------------
| MultiApp 截断后，assets/xxx.js → 需拼回 admin/ 前缀查找静态文件
| 其他非 api/ 路径 → 返回 index.html（SPA history mode）
*/
Route::miss(function () {
    $path = request()->pathinfo();
    $publicPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR;

    // 尝试匹配静态文件（如 assets/xxx.js → public/admin/assets/xxx.js）
    $filePath = $publicPath . 'admin' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
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
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    // SPA fallback: 返回 admin/index.html
    $indexPath = $publicPath . 'admin' . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($indexPath)) {
        return response(file_get_contents($indexPath), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    // 兜底：返回旧的 admin.html
    $legacyPath = $publicPath . 'admin.html';
    if (is_file($legacyPath)) {
        return view($legacyPath);
    }

    abort(404, '前端页面未找到，请先构建前端');
});
