<?php

use think\facade\Route;

// 上传接口路由
Route::group('upload', function () {
    // 获取上传配置（前端 Upload 组件使用，登录即可，无需权限）
    Route::get('config', 'config')->name('UploadConfig')->option([
        '_alias' => '上传配置',
        '_desc' => '获取上传验证规则和文件图标配置',
        '_auth' => false,
    ]);

    // 上传图片
    Route::post('image', 'image')->name('UploadImage')->option([
        '_alias' => '上传图片',
        '_desc' => '上传图片文件',
        '_auth' => true,
    ]);

    // 上传文件
    Route::post('file', 'file')->name('UploadFile')->option([
        '_alias' => '上传文件',
        '_desc' => '上传通用文件',
        '_auth' => true,
    ]);

    // 批量上传图片
    Route::post('batchImage', 'batchImage')->name('UploadBatchImage')->option([
        '_alias' => '批量上传图片',
        '_desc' => '批量上传图片文件',
        '_auth' => true,
    ]);
})->prefix('UploadController/')
    ->name('Upload')
    ->option([
        '_group_name' => '文件上传',
        '_path' => '',
        '_auth' => true,
    ]);