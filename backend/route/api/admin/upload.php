<?php

use think\facade\Route;

Route::group('upload', function () {
    Route::post('single', 'single')->name('UploadSingle')->option([
        '_alias' => '单文件上传',
        '_desc'  => '单文件上传（图片/文件通用）',
        '_auth'  => true,
    ]);

    Route::post('batch', 'batch')->name('UploadBatch')->option([
        '_alias' => '批量上传',
        '_desc'  => '批量文件上传（图片/文件通用）',
        '_auth'  => true,
    ]);
})->prefix('admin.UploadController/')
    ->name('Upload')
    ->option([
        '_group_name' => '文件上传',
        '_path'       => '',
        '_auth'       => true,
    ]);
