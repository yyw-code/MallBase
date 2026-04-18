<?php

use think\facade\Route;

/*
|--------------------------------------------------------------------------
| 静态文件访问（上传的文件）
|--------------------------------------------------------------------------
*/
Route::group('uploads', function () {
    Route::miss(function () {
        $path = request()->pathinfo();
        $filePath = public_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (!file_exists($filePath)) {
            abort(404, '文件不存在');
        }

        $mimeType = mime_content_type($filePath);

        return response(file_get_contents($filePath), 200, [
            'Content-Type'  => $mimeType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    });
})->allowCrossDomain();

/*
|--------------------------------------------------------------------------
| 全局兜底
|--------------------------------------------------------------------------
*/
Route::miss(function () {
    if (request()->isAjax() || str_contains(request()->header('accept', ''), 'application/json')) {
        return json(['code' => 404, 'msg' => '接口不存在', 'data' => null]);
    }
    abort(404);
});
