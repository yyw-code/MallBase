<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('config', function () {
    Route::get('colorOptions', 'colorOptions')->name('SystemConfigColorOptions')->option([
        '_alias' => '颜色选项',
        '_desc'  => '获取颜色选项列表',
        '_auth'  => true,
        '_type'  => Permission::TYPE_API,
    ]);

    Route::get('uploadConfig', 'uploadConfig')->name('SystemConfigUploadConfig')->option([
        '_alias' => '上传配置',
        '_desc'  => '获取上传验证规则和文件图标配置',
        '_auth'  => false,
        '_type'  => Permission::TYPE_API,
    ])->withoutMiddleware([CheckPermission::class]);

    Route::get('uploadOptions', 'uploadOptions')->name('SystemConfigUploadOptions')->option([
        '_alias' => '上传选项',
        '_desc'  => '获取上传类型、素材类型和当前上传驱动选项',
        '_auth'  => false,
        '_type'  => Permission::TYPE_API,
    ])->withoutMiddleware([CheckPermission::class]);

    // 后台应用元数据（公开：登录前也需要读取 logo/favicon/登录页文案）
    Route::get('appMeta', 'appMeta')
        ->name('SystemConfigAppMeta')
        ->option([
            '_alias' => '应用元数据',
            '_desc'  => '后台 Logo / Favicon / 登录页文案 / 版权信息（公开接口）',
            '_auth'  => false,
            '_type'  => Permission::TYPE_API,
        ])
        ->withoutMiddleware([JwtAuth::class, CheckPermission::class]);
})->prefix('admin.ConfigController/')
    ->middleware([JwtAuth::class])
    ->option([
        '_group_name'      => '系统配置',
        '_group_code'      => 'SystemConfig',
        '_group_name_desc' => '系统配置相关接口',
        '_path'            => '/config',
        '_is_show'         => 0,
        '_auth'            => true,
    ]);
