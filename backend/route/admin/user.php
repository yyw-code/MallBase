<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 前台用户管理（后台管理，需要登录+权限）
Route::group('user', function () {
    Route::get('list', 'list')->option(['_alias' => '列表', '_desc' => '获取前台用户列表', '_type' => 'api']);
    Route::get('info/:id', 'info')->option(['_alias' => '详情', '_desc' => '获取前台用户详情', '_type' => 'api']);
    Route::post('create', 'create')->option(['_alias' => '新增', '_desc' => '创建前台用户', '_type' => 'api']);
    Route::put('update/:id', 'update')->option(['_alias' => '编辑', '_desc' => '更新前台用户', '_type' => 'api']);
    Route::delete('delete/:id', 'delete')->option(['_alias' => '删除', '_desc' => '删除前台用户', '_type' => 'api']);
    Route::put('status/:id', 'updateStatus')->option(['_alias' => '状态', '_desc' => '更新用户状态', '_type' => 'api']);
    Route::put('resetPassword/:id', 'resetPassword')->option(['_alias' => '重置密码', '_desc' => '重置用户密码', '_type' => 'api']);
})->prefix('user/UserController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户管理',
        '_group_code' => 'ClientUserList',
        '_group_name_desc' => '前台用户管理模块的菜单和接口权限',
        '_parent' => 'ClientUserManagement',
        '_icon' => 'lucide:users',
        '_path' => '/user',
        '_component' => '/user/index',
    ]);
