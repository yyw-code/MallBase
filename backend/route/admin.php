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
| 后台管理页面（HTML）
|--------------------------------------------------------------------------
*/
Route::group('/', function () {
    Route::miss(function () {
        return view(
            app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'admin.html'
        );
    });
});
