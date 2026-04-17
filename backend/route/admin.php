<?php

use think\facade\Route;
use app\admin\middleware\{
    JwtAuth,
    CheckPermission,
    AdminOperationLogMiddleware,
    RequestLockMiddleware
};

/*
|--------------------------------------------------------------------------
| 后台 API
|--------------------------------------------------------------------------
*/
Route::group('api/', function () {

    // 加载 admin 子路由
    load_routes('admin');

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
        '_lock' => true, // 请求锁
        '_group_name' => '后台管理'
    ])
    ->middleware([
        JwtAuth::class,
        CheckPermission::class,   // CORS 必须最前
        RequestLockMiddleware::class,   // 防重复提交
        AdminOperationLogMiddleware::class,           // 操作日志（最后）
    ]);
/*
|--------------------------------------------------------------------------
| 静态文件访问（上传的文件）
|--------------------------------------------------------------------------
*/
Route::group('uploads', function () {
    Route::miss(function () {
        $path = request()->pathinfo();
        $filePath = public_path() . DIRECTORY_SEPARATOR . str_replace('/uploads/', '', $path);

        if (!file_exists($filePath)) {
            abort(404, '文件不存在');
        }

        // 获取文件 MIME 类型
        $mimeType = mime_content_type($filePath);

        // 返回文件
        return response(file_get_contents($filePath), 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    });
})->allowCrossDomain();

/*
|--------------------------------------------------------------------------
| 后台前端静态文件（构建产物）
|--------------------------------------------------------------------------
*/
Route::group('admin', function () {
    Route::miss(function () {
        $path = request()->pathinfo();
        $publicPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR;

        // 尝试直接匹配静态文件（如 admin/assets/xxx.js）
        $filePath = $publicPath . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($filePath)) {
            $mimeTypes = [
                'js'   => 'application/javascript',
                'mjs'  => 'application/javascript',
                'css'  => 'text/css',
                'svg'  => 'image/svg+xml',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'ico'  => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2'=> 'font/woff2',
                'ttf'  => 'font/ttf',
                'eot'  => 'application/vnd.ms-fontobject',
                'json' => 'application/json',
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
});

/*
|--------------------------------------------------------------------------
| 根路径兜底
|--------------------------------------------------------------------------
*/
Route::group('/', function () {
    Route::miss(function () {
        // 优先返回 admin/index.html
        $indexPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($indexPath)) {
            return response(file_get_contents($indexPath), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        }

        // 兜底旧 admin.html
        $legacyPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'admin.html';
        if (is_file($legacyPath)) {
            return view($legacyPath);
        }

        abort(404, '页面未找到');
    });
});
