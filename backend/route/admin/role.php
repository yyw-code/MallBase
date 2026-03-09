<?php

use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 角色接口路由
Route::group('auth/role', function () {
    // 列表
    Route::get('list', 'list')->option(['_alias' => '列表', '_desc' => '角色列表', '_auth' => true]);
    // 所有角色
    Route::get('all', 'all')->option(['_alias' => '全部', '_desc' => '获取所有角色', '_auth' => true]);
    // 详情
    Route::get('info/:id', 'info')->option(['_alias' => '详情', '_desc' => '角色详情', '_auth' => true]);
    // 创建
    Route::post('create', 'create')->option(['_alias' => '创建', '_desc' => '创建角色', '_auth' => true]);
    // 更新
    Route::put('update/:id', 'update')->option(['_alias' => '更新', '_desc' => '更新角色', '_auth' => true]);
    // 更新状态
    Route::put('changeStatus/:id', 'changeStatus')->option(['_alias' => '更新状态', '_desc' => '更新角色状态', '_auth' => true]);
    // 删除
    Route::delete('delete/:id', 'delete')->option(['_alias' => '删除', '_desc' => '删除角色', '_auth' => true]);
})->prefix('auth/RoleController/')
    ->option([
        '_group_name' => '角色',
        '_path' => '',
        '_auth' => true,
    ])
    ->middleware([
        JwtAuth::class
    ]);
