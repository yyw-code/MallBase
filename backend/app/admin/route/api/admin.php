<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

// 管理员接口路由
Route::group('auth/admin', function () {
    Route::group('', function () {
        // 登录
        Route::post('login', 'login')->option(['_alias' => '登录', '_desc' => '管理员登录']);
        // 刷新 Token
        Route::post('refreshToken', 'refreshToken')->option(['_alias' => '刷新Token', '_desc' => '刷新访问令牌']);
    })->option([
        '_alias' => '无需授权',
        '_auth' => false
    ])->withoutMiddleware([JwtAuth::class, CheckPermission::class]);

    // 登出（需要登录，不需要权限检查）
    Route::post('logout', 'logout')->name('SystemAdminLogout')->option(['_alias' => '登出', '_desc' => '管理员登出', '_auth' => false])->withoutMiddleware([CheckPermission::class]);
    // 列表
    Route::get('list', 'list')->name('SystemAdminList')->option(['_alias' => '列表', '_desc' => '管理员列表', '_auth' => true]);
    // 详情
    Route::get('info/:id', 'info')->name('SystemAdminInfo')->option(['_alias' => '详情', '_desc' => '管理员详情', '_auth' => true]);
    // 当前登录管理员详情
    Route::get('adminInfo', 'adminInfo')->name('SystemLoginAdminInfo')->option(['_alias' => '当前登录详情', '_desc' => '当前登录管理员详情', '_auth' => false]);
    // 创建
    Route::post('create', 'create')->name('SystemAdminCreate')->option(['_alias' => '创建', '_desc' => '创建管理员', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 更新
    Route::put('update/:id', 'update')->name('SystemAdminUpdate')->option(['_alias' => '更新', '_desc' => '更新管理员', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 当前登录管理员更新
    Route::put('adminUpdate', 'adminUpdate')->name('SystemLoginAdminUpdate')->option(['_alias' => '更新当前登录', '_desc' => '更新当前登录管理员', '_auth' => false]);
    // 更新状态
    Route::put('changeStatus/:id', 'changeStatus')->name('SystemAdminChangeStatus')->option(['_alias' => '更新状态', '_desc' => '更新管理员状态', '_auth' => true]);
    // 删除
    Route::delete('delete/:id', 'delete')->name('SystemAdminDelete')->option(['_alias' => '删除', '_desc' => '删除管理员', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 重置密码
    Route::post('resetPassword/:id', 'resetPassword')->name('SystemAdminResetPassword')->option(['_alias' => '重置密码', '_desc' => '重置管理员密码', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 修改密码
    Route::post('changePassword', 'changePassword')->name('SystemAdminChangePassword')->option(['_alias' => '修改密码', '_desc' => '修改管理员密码', '_auth' => true]);
})->prefix('auth/AdminController/')
    ->option([
        '_group_name' => '管理员管理',
        '_group_code' => 'SystemAdmin',
        '_path' => '/admin',
        '_auth' => true,
        '_icon' => 'lucide:users',
        '_parent' => 'SystemPermissionManagement',
        '_component' => '/auth/admin/index',
    ]);
