<?php

use app\admin\controller\ConfigController;
use app\admin\middleware\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

// 系统配置接口（需要登录）
Route::group('config', function () {
    // 颜色选项
    Route::get('colorOptions', 'colorOptions')->name('SystemConfigColorOptions')->option([
        '_alias' => '颜色选项',
        '_desc' => '获取颜色选项列表',
        '_auth' => true,
        '_type' => Permission::TYPE_API,
    ]);

    // 上传配置
    Route::get('uploadConfig', 'uploadConfig')->name('SystemConfigUploadConfig')->option([
        '_alias' => '上传配置',
        '_desc' => '获取上传验证规则和文件图标配置',
        '_auth' => false,
        '_type' => Permission::TYPE_API,
    ]);
})->prefix('ConfigController/')
    ->middleware([JwtAuth::class])
    ->option([
        '_group_name' => '系统配置',
        '_group_code' => 'SystemConfig',
        '_group_name_desc' => '系统配置相关接口',
        '_path' => '/config',
        '_is_show' => 0,
        '_auth' => true,
    ]);
