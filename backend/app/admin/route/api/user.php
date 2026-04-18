<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

// 前台用户管理（后台管理，需要登录+权限）
Route::group('user', function () {
    Route::get('list', 'list')->name('SystemUserList')->option(['_alias' => '列表', '_desc' => '获取前台用户列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemUserInfo')->option(['_alias' => '详情', '_desc' => '获取前台用户详情', '_auth' => true]);
    Route::post('create', 'create')->name('SystemUserCreate')->option(['_alias' => '新增', '_desc' => '创建前台用户', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemUserUpdate')->option(['_alias' => '编辑', '_desc' => '更新前台用户', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemUserDelete')->option(['_alias' => '删除', '_desc' => '删除前台用户', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('status/:id', 'updateStatus')->name('SystemUserUpdateStatus')->option(['_alias' => '状态', '_desc' => '更新用户状态', '_auth' => true]);
    Route::put('resetPassword/:id', 'resetPassword')->name('SystemUserResetPassword')->option(['_alias' => '重置密码', '_desc' => '重置用户密码', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('user/UserController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户管理',
        '_group_code' => 'SystemClientUserList',
        '_group_name_desc' => '前台用户管理模块的菜单和接口权限',
        '_parent' => 'SystemClientUserManagement',
        '_icon' => 'lucide:users',
        '_path' => '/user',
        '_auth' => true,
        '_component' => '/user/index',
    ]);

// 用户分组管理
Route::group('user/group', function () {
    Route::get('list', 'list')->name('SystemUserGroupList')->option(['_alias' => '分组列表', '_desc' => '获取用户分组列表', '_auth' => true]);
    Route::get('info', 'info')->name('SystemUserGroupInfo')->option(['_alias' => '分组详情', '_desc' => '获取分组详情', '_auth' => true]);
    Route::post('create', 'create')->name('SystemUserGroupCreate')->option(['_alias' => '创建分组', '_desc' => '创建用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update', 'update')->name('SystemUserGroupUpdate')->option(['_alias' => '更新分组', '_desc' => '更新用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete', 'delete')->name('SystemUserGroupDelete')->option(['_alias' => '删除分组', '_desc' => '删除用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus', 'updateStatus')->name('SystemUserGroupUpdateStatus')->option(['_alias' => '分组状态', '_desc' => '更新分组状态', '_auth' => true]);
    Route::get('getUserCount', 'getUserCount')->name('SystemUserGroupGetUserCount')->option(['_alias' => '分组用户数', '_desc' => '获取分组下的用户数', '_auth' => true]);
    Route::post('batchSetUsers', 'batchSetUsers')->name('SystemUserGroupBatchSetUsers')->option(['_alias' => '批量设置分组', '_desc' => '批量设置用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('removeUser', 'removeUser')->name('SystemUserGroupRemoveUser')->option(['_alias' => '移除分组', '_desc' => '移除用户分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('getUserGroups', 'getUserGroups')->name('SystemUserGroupGetUserGroups')->option(['_alias' => '用户分组列表', '_desc' => '获取用户的所有分组', '_auth' => true]);
})->prefix('user.UserGroupController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户分组',
        '_group_code' => 'SystemClientUserGroup',
        '_group_name_desc' => '用户分组管理模块的菜单和接口权限',
        '_parent' => 'SystemClientUserManagement',
        '_icon' => 'lucide:users',
        '_path' => '/user/group',
        '_auth' => true,
        '_component' => '/user/group/index',
    ]);

// 用户标签管理
Route::group('user/tag', function () {
    Route::get('list', 'list')->name('SystemUserTagList')->option(['_alias' => '标签列表', '_desc' => '获取用户标签列表', '_auth' => true]);
    Route::get('info', 'info')->name('SystemUserTagInfo')->option(['_alias' => '标签详情', '_desc' => '获取标签详情', '_auth' => true]);
    Route::post('create', 'create')->name('SystemUserTagCreate')->option(['_alias' => '创建标签', '_desc' => '创建用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update', 'update')->name('SystemUserTagUpdate')->option(['_alias' => '更新标签', '_desc' => '更新用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete', 'delete')->name('SystemUserTagDelete')->option(['_alias' => '删除标签', '_desc' => '删除用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus', 'updateStatus')->name('SystemUserTagUpdateStatus')->option(['_alias' => '标签状态', '_desc' => '更新标签状态', '_auth' => true]);
    Route::get('getUserCount', 'getUserCount')->name('SystemUserTagGetUserCount')->option(['_alias' => '标签用户数', '_desc' => '获取标签下的用户数', '_auth' => true]);
    Route::post('batchSetUsers', 'batchSetUsers')->name('SystemUserTagBatchSetUsers')->option(['_alias' => '批量设置标签', '_desc' => '批量给用户打标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('removeUser', 'removeUser')->name('SystemUserTagRemoveUser')->option(['_alias' => '移除标签', '_desc' => '移除用户标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('getUserTags', 'getUserTags')->name('SystemUserTagGetUserTags')->option(['_alias' => '用户标签列表', '_desc' => '获取用户的所有标签', '_auth' => true]);
})->prefix('user.UserTagController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户标签',
        '_group_code' => 'SystemClientUserTag',
        '_group_name_desc' => '用户标签管理模块的菜单和接口权限',
        '_parent' => 'SystemClientUserManagement',
        '_icon' => 'lucide:tag',
        '_path' => '/user/tag',
        '_auth' => true,
        '_component' => '/user/tag/index',
    ]);
