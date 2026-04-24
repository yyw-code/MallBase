<?php

use think\facade\Route;

/*
|--------------------------------------------------------------------------
| 静态文件访问（上传的文件）
|--------------------------------------------------------------------------
*/
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

