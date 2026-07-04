<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('setting/group', function () {
    Route::get('list', 'list')->name('SettingGroupList')->option(['_alias' => '分组列表', '_desc' => '设置分组列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('tree', 'tree')->name('SettingGroupTree')->option(['_alias' => '分组树', '_desc' => '设置分组树形结构', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('all', 'all')->name('SettingGroupAll')->option(['_alias' => '全部分组', '_desc' => '所有启用的设置分组', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SettingGroupInfo')->option(['_alias' => '分组详情', '_desc' => '分组详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SettingGroupCreate')->option(['_alias' => '创建分组', '_desc' => '创建设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SettingGroupUpdate')->option(['_alias' => '更新分组', '_desc' => '更新设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('changeStatus/:id', 'changeStatus')->name('SettingGroupChangeStatus')->option(['_alias' => '修改状态', '_desc' => '修改分组状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SettingGroupDelete')->option(['_alias' => '删除分组', '_desc' => '删除设置分组', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.setting.GroupController/')
    ->option([
        '_group_name' => '设置分组',
        '_group_code' => 'SettingGroup',
        '_path'       => '/settings/group',
        '_auth'       => true,
        '_icon'       => 'lucide:folder-tree',
        '_parent'     => 'SystemManagement',
        '_component'  => '/settings/group/index',
    ]);

Route::group('setting/item', function () {
    Route::get('form/config', 'formConfig')->name('SettingFormConfig')->option(['_alias' => '表单配置', '_desc' => '获取表单类型选项和验证规则类型', '_auth' => false]);
    Route::get('list', 'list')->name('SettingItemList')->option(['_alias' => '设置项列表', '_desc' => '分组下的设置项列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SettingItemCreate')->option(['_alias' => '创建设置项', '_desc' => '创建设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SettingItemUpdate')->option(['_alias' => '更新设置项', '_desc' => '更新设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SettingItemDelete')->option(['_alias' => '删除设置项', '_desc' => '删除设置项', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('config/:groupCode', 'getConfig')->name('SettingConfig')->option(['_alias' => '获取配置', '_desc' => '获取分组配置', '_auth' => false]);
    Route::post('saveConfig/:groupCode', 'saveConfig')->name('SettingSaveConfig')->option([
        '_alias'             => '保存配置',
        '_desc'              => '保存分组配置',
        '_auth'              => true,
        '_type'              => Permission::TYPE_BUTTON,
        '_permission_param'  => 'groupCode',
        '_permission_prefix' => 'SettingGroup:',
    ]);
})->prefix('admin.setting.SettingItemController/')
    ->option([
        '_group_name' => '设置项管理',
        '_group_code' => 'SettingItem',
        '_path'       => '/settings/item',
        '_auth'       => true,
        '_icon'       => 'lucide:list',
        '_parent'     => 'SystemManagement',
        '_component'  => '/settings/item/index',
    ]);
