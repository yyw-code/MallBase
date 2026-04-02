<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use app\admin\model\auth\Permission;
use think\facade\Route;

// 系统设置路由
Route::group('setting', function () {

    // ==================== 权限菜单树 ====================

    // 菜单权限树（仅纯目录，排除有 component 的菜单，设置模块专用）
    Route::get('permission/tree', 'menuTree')->name('SettingPermissionTree')->option(['_alias' => '菜单权限树', '_desc' => '菜单权限树（仅纯目录，设置模块用）', '_auth' => false]);

    // ==================== 分组管理 ====================

    // 分组列表（分页）
    Route::get('group/list', 'groupList')->name('SettingGroupList')->option(['_alias' => '分组列表', '_desc' => '设置分组列表', '_auth' => true]);
    // 分组树形列表（不分页）
    Route::get('group/tree', 'groupTree')->name('SettingGroupTree')->option(['_alias' => '分组树', '_desc' => '设置分组树形结构', '_auth' => true]);
    // 所有启用的分组（树形结构）
    Route::get('group/all', 'groupAll')->name('SettingGroupAll')->option(['_alias' => '全部分组', '_desc' => '所有启用的设置分组', '_auth' => true]);
    // 分组详情（编辑回显）
    Route::get('group/info/:id', 'groupInfo')->name('SettingGroupInfo')->option(['_alias' => '分组详情', '_desc' => '分组详情', '_auth' => true]);
    // 创建分组
    Route::post('group/create', 'groupCreate')->name('SettingGroupCreate')->option(['_alias' => '创建分组', '_desc' => '创建设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 更新分组
    Route::put('group/update/:id', 'groupUpdate')->name('SettingGroupUpdate')->option(['_alias' => '更新分组', '_desc' => '更新设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 删除分组
    Route::delete('group/delete/:id', 'groupDelete')->name('SettingGroupDelete')->option(['_alias' => '删除分组', '_desc' => '删除设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);

    // ==================== 验证规则类型 ====================

    // 获取所有可用的验证规则类型（前端动态渲染用，无需权限检查）
    Route::get('rule/types', 'ruleTypes')->name('SettingRuleTypes')->option(['_alias' => '规则类型', '_desc' => '获取所有可用的验证规则类型', '_auth' => false]);

    // ==================== 设置项管理 ====================

    // 设置项列表（按分组）
    Route::get('item/list', 'settingList')->name('SettingItemList')->option(['_alias' => '设置项列表', '_desc' => '分组下的设置项列表', '_auth' => true]);
    // 创建设置项
    Route::post('item/create', 'settingCreate')->name('SettingItemCreate')->option(['_alias' => '创建设置项', '_desc' => '创建设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 更新设置项
    Route::put('item/update/:id', 'settingUpdate')->name('SettingItemUpdate')->option(['_alias' => '更新设置项', '_desc' => '更新设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 删除设置项
    Route::delete('item/delete/:id', 'settingDelete')->name('SettingItemDelete')->option(['_alias' => '删除设置项', '_desc' => '删除设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);

    // ==================== 配置读取/保存（前端使用） ====================

    // 获取分组配置（前端渲染表单用，需要登录但不需要权限检查）
    Route::get('config/:groupCode', 'getConfig')->name('SettingConfig')->option(['_alias' => '获取配置', '_desc' => '获取分组配置', '_auth' => false]);
    // 保存分组配置（前端提交表单，需要登录但不需要权限检查）
    Route::post('saveConfig/:groupCode', 'saveConfig')->name('SettingSaveConfig')->option(['_alias' => '保存配置', '_desc' => '保存分组配置', '_auth' => false]);

})->prefix('setting/SettingController/')
    ->option([
        '_group_name' => '设置管理',
        '_group_code' => 'SettingManagement',
        '_path' => '/settings/management',
        '_auth' => true,
        '_icon' => 'lucide:settings',
        '_parent' => 'System',
        '_component' => '/settings/management/index',
    ]);