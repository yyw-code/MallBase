<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('user', function () {
    Route::get('list', 'list')->name('SystemUserList')->option(['_alias' => '列表', '_desc' => '获取前台用户列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('stats', 'stats')->name('SystemUserStats')->option(['_alias' => '用户统计', '_desc' => '获取前台用户状态统计', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('export', 'export')->name('SystemUserExport')->option(['_alias' => '导出用户', '_desc' => '按筛选条件导出前台用户 CSV', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('info/:id', 'info')->name('SystemUserInfo')->option(['_alias' => '详情', '_desc' => '获取前台用户详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemUserCreate')->option(['_alias' => '新增', '_desc' => '创建前台用户', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemUserUpdate')->option(['_alias' => '编辑', '_desc' => '更新前台用户', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemUserDelete')->option(['_alias' => '删除', '_desc' => '删除前台用户', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('status/:id', 'updateStatus')->name('SystemUserUpdateStatus')->option(['_alias' => '状态', '_desc' => '更新用户状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('resetPassword/:id', 'resetPassword')->name('SystemUserResetPassword')->option(['_alias' => '重置密码', '_desc' => '重置用户密码', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.user.UserController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '用户管理',
        '_group_code'      => 'SystemClientUserList',
        '_group_name_desc' => '前台用户管理模块的菜单和操作权限',
        '_parent'          => 'SystemClientUserManagement',
        '_icon'            => 'lucide:users',
        '_path'            => '/user',
        '_auth'            => true,
        '_component'       => '/user/index',
    ]);

Route::group('user/wallet', function () {
    Route::get('logs', 'logs')->name('SystemUserWalletLog')->option(['_alias' => '余额记录', '_desc' => '查看用户余额流水', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('adjust', 'adjust')->name('SystemUserWalletAdjust')->option(['_alias' => '调整余额', '_desc' => '后台调整用户余额', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.user.UserWalletController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '用户管理',
        '_group_code'      => 'SystemClientUserList',
        '_group_name_desc' => '前台用户余额记录和调整权限',
        '_parent'          => 'SystemClientUserManagement',
        '_auth'            => true,
    ]);

Route::group('user/group', function () {
    Route::get('list', 'list')->name('SystemUserGroupList')->option(['_alias' => '分组列表', '_desc' => '获取用户分组列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info', 'info')->name('SystemUserGroupInfo')->option(['_alias' => '分组详情', '_desc' => '获取分组详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemUserGroupCreate')->option(['_alias' => '创建分组', '_desc' => '创建用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update', 'update')->name('SystemUserGroupUpdate')->option(['_alias' => '更新分组', '_desc' => '更新用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete', 'delete')->name('SystemUserGroupDelete')->option(['_alias' => '删除分组', '_desc' => '删除用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus', 'updateStatus')->name('SystemUserGroupUpdateStatus')->option(['_alias' => '分组状态', '_desc' => '更新分组状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('getUserCount', 'getUserCount')->name('SystemUserGroupGetUserCount')->option(['_alias' => '分组用户数', '_desc' => '获取分组下的用户数', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('batchSetUsers', 'batchSetUsers')->name('SystemUserGroupBatchSetUsers')->option(['_alias' => '批量设置分组', '_desc' => '批量设置用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('removeUser', 'removeUser')->name('SystemUserGroupRemoveUser')->option(['_alias' => '移除分组', '_desc' => '移除用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('getUserGroups', 'getUserGroups')->name('SystemUserGroupGetUserGroups')->option(['_alias' => '用户分组列表', '_desc' => '获取用户的所有分组', '_auth' => true, '_type' => Permission::TYPE_MENU]);
})->prefix('admin.user.UserGroupController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '用户分组',
        '_group_code'      => 'SystemClientUserGroup',
        '_group_name_desc' => '用户分组管理模块的菜单和操作权限',
        '_parent'          => 'SystemClientUserManagement',
        '_icon'            => 'lucide:users',
        '_path'            => '/user/group',
        '_auth'            => true,
        '_component'       => '/user/group/index',
    ]);

Route::group('user/tag', function () {
    Route::get('list', 'list')->name('SystemUserTagList')->option(['_alias' => '标签列表', '_desc' => '获取用户标签列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info', 'info')->name('SystemUserTagInfo')->option(['_alias' => '标签详情', '_desc' => '获取标签详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemUserTagCreate')->option(['_alias' => '创建标签', '_desc' => '创建用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update', 'update')->name('SystemUserTagUpdate')->option(['_alias' => '更新标签', '_desc' => '更新用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete', 'delete')->name('SystemUserTagDelete')->option(['_alias' => '删除标签', '_desc' => '删除用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus', 'updateStatus')->name('SystemUserTagUpdateStatus')->option(['_alias' => '标签状态', '_desc' => '更新标签状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('getUserCount', 'getUserCount')->name('SystemUserTagGetUserCount')->option(['_alias' => '标签用户数', '_desc' => '获取标签下的用户数', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('batchSetUsers', 'batchSetUsers')->name('SystemUserTagBatchSetUsers')->option(['_alias' => '批量设置标签', '_desc' => '批量给用户打标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('removeUser', 'removeUser')->name('SystemUserTagRemoveUser')->option(['_alias' => '移除标签', '_desc' => '移除用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('getUserTags', 'getUserTags')->name('SystemUserTagGetUserTags')->option(['_alias' => '用户标签列表', '_desc' => '获取用户的所有标签', '_auth' => true, '_type' => Permission::TYPE_MENU]);
})->prefix('admin.user.UserTagController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '用户标签',
        '_group_code'      => 'SystemClientUserTag',
        '_group_name_desc' => '用户标签管理模块的菜单和操作权限',
        '_parent'          => 'SystemClientUserManagement',
        '_icon'            => 'lucide:tag',
        '_path'            => '/user/tag',
        '_auth'            => true,
        '_component'       => '/user/tag/index',
    ]);
