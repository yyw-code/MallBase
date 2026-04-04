<?php

use app\admin\model\auth\Permission;
use think\facade\Route;

// ==================== 设置分组管理 ====================
Route::group('setting/group', function () {

    // 菜单权限树（仅纯目录，排除有 component 的菜单，设置模块专用）
    Route::get('permission/tree', 'menuTree')->name('SettingPermissionTree')->option(['_alias' => '菜单权限树', '_desc' => '菜单权限树（仅纯目录，设置模块用）', '_auth' => false]);

    // 分组列表（分页）
    Route::get('list', 'list')->name('SettingGroupList')->option(['_alias' => '分组列表', '_desc' => '设置分组列表', '_auth' => true]);
    // 分组树形列表（不分页）
    Route::get('tree', 'tree')->name('SettingGroupTree')->option(['_alias' => '分组树', '_desc' => '设置分组树形结构', '_auth' => true]);
    // 所有启用的分组（树形结构）
    Route::get('all', 'all')->name('SettingGroupAll')->option(['_alias' => '全部分组', '_desc' => '所有启用的设置分组', '_auth' => true]);
    // 分组详情（编辑回显）
    Route::get('info/:id', 'info')->name('SettingGroupInfo')->option(['_alias' => '分组详情', '_desc' => '分组详情', '_auth' => true]);
    // 创建分组
    Route::post('create', 'create')->name('SettingGroupCreate')->option(['_alias' => '创建分组', '_desc' => '创建设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 更新分组
    Route::put('update/:id', 'update')->name('SettingGroupUpdate')->option(['_alias' => '更新分组', '_desc' => '更新设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 修改分组状态（同步更新对应权限状态，禁用时递归禁用子级）
    Route::put('changeStatus/:id', 'changeStatus')->name('SettingGroupChangeStatus')->option(['_alias' => '修改状态', '_desc' => '修改分组状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 删除分组
    Route::delete('delete/:id', 'delete')->name('SettingGroupDelete')->option(['_alias' => '删除分组', '_desc' => '删除设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);

})->prefix('setting/GroupController/')
    ->option([
        '_group_name' => '设置分组',
        '_group_code' => 'SettingGroup',
        '_path' => '/settings/group',
        '_auth' => true,
        '_icon' => 'lucide:folder-tree',
        '_parent' => 'SystemManagement',
        '_component' => '/settings/group/index',
    ]);

// ==================== 设置项管理 ====================
Route::group('setting/item', function () {

    // 获取表单配置（表单类型选项 + 验证规则类型，前端动态渲染用，无需权限检查）
    Route::get('form/config', 'formConfig')->name('SettingFormConfig')->option(['_alias' => '表单配置', '_desc' => '获取表单类型选项和验证规则类型', '_auth' => false]);

    // 设置项列表（按分组）
    Route::get('list', 'list')->name('SettingItemList')->option(['_alias' => '设置项列表', '_desc' => '分组下的设置项列表', '_auth' => true]);
    // 创建设置项
    Route::post('create', 'create')->name('SettingItemCreate')->option(['_alias' => '创建设置项', '_desc' => '创建设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 更新设置项
    Route::put('update/:id', 'update')->name('SettingItemUpdate')->option(['_alias' => '更新设置项', '_desc' => '更新设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    // 删除设置项
    Route::delete('delete/:id', 'delete')->name('SettingItemDelete')->option(['_alias' => '删除设置项', '_desc' => '删除设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);

    // 获取分组配置（前端渲染表单用，需要登录但不需要权限检查）
    Route::get('config/:groupCode', 'getConfig')->name('SettingConfig')->option(['_alias' => '获取配置', '_desc' => '获取分组配置', '_auth' => false]);
    // 保存分组配置（前端提交表单，需要登录但不需要权限检查）
    Route::post('saveConfig/:groupCode', 'saveConfig')->name('SettingSaveConfig')->option(['_alias' => '保存配置', '_desc' => '保存分组配置', '_auth' => false]);

})->prefix('setting/SettingItemController/')
    ->option([
        '_group_name' => '设置项管理',
        '_group_code' => 'SettingItem',
        '_path' => '/settings/item',
        '_auth' => true,
        '_icon' => 'lucide:list',
        '_parent' => 'SystemManagement',
        '_component' => '/settings/item/index',
    ]);