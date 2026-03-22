<?php

use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 权限接口路由
Route::group('auth/permission', function () {
    // 树形列表
    Route::get('tree', 'tree')->name('SystemPermissionTree')->option(['_alias' => '树形列表', '_desc' => '权限树形列表', '_auth' => true]);
    // 菜单列表
    Route::get('menu', 'menu')->name('SystemPermissionMenu')->option(['_alias' => '菜单', '_desc' => '菜单列表']);
    // 列表
    Route::get('list', 'list')->name('SystemPermissionList')->option(['_alias' => '列表', '_desc' => '权限列表', '_auth' => true]);
    // 详情
    Route::get('info/:id', 'info')->name('SystemPermissionInfo')->option(['_alias' => '详情', '_desc' => '权限详情', '_auth' => true]);
    // 创建
    Route::post('create', 'create')->name('SystemPermissionCreate')->option(['_alias' => '创建', '_desc' => '创建权限', '_auth' => true]);
    // 更新
    Route::put('update/:id', 'update')->name('SystemPermissionUpdate')->option(['_alias' => '更新', '_desc' => '更新权限', '_auth' => true]);
    // 批量更新
    Route::put('batchUpdate/:id', 'batchUpdate')->name('SystemPermissionBatchUpdate')->option(['_alias' => '批量更新', '_desc' => '批量更新字段', '_auth' => true]);
    // 删除
    Route::delete('delete/:id', 'delete')->name('SystemPermissionDelete')->option(['_alias' => '删除', '_desc' => '删除权限', '_auth' => true]);
})->prefix('auth/PermissionController/')
    ->name('SystemPermission')
    ->option([
        '_group_name' => '权限设置',
        '_group_code' => 'SystemPermission',
        '_path' => '/permission',
        '_auth' => true,
        '_icon' => 'lucide:lock',
        '_parent' => 'SystemPermissionManagement',
        '_component' => 'system/permission/index',
    ])
    ->middleware([
        JwtAuth::class
    ]);