<?php

use think\facade\Route;

Route::get('/', function () {
    return redirect('/client/');
});

/*
|--------------------------------------------------------------------------
| 静态文件访问（上传的文件）
|--------------------------------------------------------------------------
*/
Route::miss(function () {
    $path = request()->pathinfo();
    $filePath = public_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

    if (is_dir($filePath)) {
        $indexPath = rtrim($filePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($indexPath)) {
            return response(file_get_contents($indexPath), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        }
    }

    if (!is_file($filePath)) {
        abort(404, '文件不存在');
    }

    $mimeType = mime_content_type($filePath);

    return response(file_get_contents($filePath), 200, [
        'Content-Type'  => $mimeType,
        'Cache-Control' => 'public, max-age=31536000',
    ]);
});
