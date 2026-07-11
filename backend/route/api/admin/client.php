<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('client/page', function () {
    Route::get('list', 'list')->name('SystemClientPageList')->option(['_alias' => '页面列表', '_desc' => '获取客户端页面列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemClientPageInfo')->option(['_alias' => '页面详情', '_desc' => '获取客户端页面详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('picker', 'picker')->name('SystemClientPagePicker')->option(['_alias' => '页面链接选择', '_desc' => '获取客户端页面链接选择列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemClientPageCreate')->option(['_alias' => '创建页面', '_desc' => '创建客户端页面', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemClientPageUpdate')->option(['_alias' => '更新页面', '_desc' => '更新客户端页面', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemClientPageDelete')->option(['_alias' => '删除页面', '_desc' => '删除客户端页面', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('import', 'import')->name('SystemClientPageImport')->option(['_alias' => '导入页面', '_desc' => '上传或粘贴 UniApp pages.json 导入页面', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.client.PageController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '页面列表',
        '_group_code' => 'SystemClientPage',
        '_group_name_desc' => '客户端页面库管理',
        '_parent' => 'SystemClientPageManagement',
        '_icon' => 'lucide:list',
        '_path' => '/client/page/list',
        '_auth' => true,
        '_component' => '/client/page/index',
        '_sort' => 10,
    ]);

Route::group('client/page/category', function () {
    Route::get('list', 'list')->name('SystemClientPageCategoryList')->option(['_alias' => '页面分类列表', '_desc' => '获取客户端页面分类列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('all', 'all')->name('SystemClientPageCategoryAll')->option(['_alias' => '全部页面分类', '_desc' => '获取全部客户端页面分类', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemClientPageCategoryInfo')->option(['_alias' => '页面分类详情', '_desc' => '获取客户端页面分类详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemClientPageCategoryCreate')->option(['_alias' => '创建页面分类', '_desc' => '创建客户端页面分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemClientPageCategoryUpdate')->option(['_alias' => '更新页面分类', '_desc' => '更新客户端页面分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemClientPageCategoryDelete')->option(['_alias' => '删除页面分类', '_desc' => '删除客户端页面分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemClientPageCategoryUpdateStatus')->option(['_alias' => '页面分类状态', '_desc' => '更新客户端页面分类状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.client.PageCategoryController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '页面分类',
        '_group_code' => 'SystemClientPageCategory',
        '_group_name_desc' => '客户端页面分类管理',
        '_parent' => 'SystemClientPageManagement',
        '_icon' => 'lucide:folder-tree',
        '_path' => '/client/page/category',
        '_auth' => true,
        '_component' => '/client/page/category/index',
        '_sort' => 20,
    ]);

Route::group('client/decorate/scheme', function () {
    Route::get('list', 'list')->name('SystemClientDecorationList')->option(['_alias' => '方案列表', '_desc' => '获取客户端装修方案列表', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::get('info/:id', 'info')->name('SystemClientDecorationInfo')->option(['_alias' => '方案详情', '_desc' => '获取客户端装修方案详情', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::get('product-sources', 'productSources')->name('SystemClientDecorationProductSources')->option(['_alias' => '商品来源选择', '_desc' => '获取首页装修商品来源选择数据', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::get('target-picker', 'targetPicker')->name('SystemClientDecorationTargetPicker')->option(['_alias' => '跳转目标选择', '_desc' => '获取装修跳转目标选择数据', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::post('create', 'create')->name('SystemClientDecorationCreate')->option(['_alias' => '创建方案', '_desc' => '创建客户端装修方案', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemClientDecorationUpdate')->option(['_alias' => '更新方案', '_desc' => '更新客户端装修方案', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('copy/:id', 'copy')->name('SystemClientDecorationCopy')->option(['_alias' => '复制方案', '_desc' => '复制客户端装修方案', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('activate/:id', 'activate')->name('SystemClientDecorationActivate')->option(['_alias' => '启用方案', '_desc' => '启用客户端装修方案', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemClientDecorationDelete')->option(['_alias' => '删除方案', '_desc' => '删除客户端装修方案', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.client.DecorationSchemeController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '装修方案接口',
        '_group_code' => 'SystemClientDecoration',
        '_group_name_desc' => '首页、个人中心、底部导航装修方案接口',
        '_parent' => 'SystemClientDecorationManagement',
        '_icon' => 'lucide:layout-template',
        '_path' => '/client/decorate/scheme',
        '_auth' => true,
        '_is_show' => 0,
        '_sort' => 20,
    ]);

Route::group('client/theme', function () {
    Route::get('list', 'list')->name('SystemClientThemeList')->option(['_alias' => '主题列表', '_desc' => '获取客户端主题列表', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::get('info/:id', 'info')->name('SystemClientThemeInfo')->option(['_alias' => '主题详情', '_desc' => '获取客户端主题详情', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::post('create', 'create')->name('SystemClientThemeCreate')->option(['_alias' => '创建主题', '_desc' => '创建客户端主题', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemClientThemeUpdate')->option(['_alias' => '更新主题', '_desc' => '更新客户端主题', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('copy/:id', 'copy')->name('SystemClientThemeCopy')->option(['_alias' => '复制主题', '_desc' => '复制客户端主题', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('publish/:id', 'publish')->name('SystemClientThemePublish')->option(['_alias' => '发布主题', '_desc' => '发布客户端主题', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemClientThemeDelete')->option(['_alias' => '删除主题', '_desc' => '删除客户端主题', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('setting', 'setting')->name('SystemClientThemeSetting')->option(['_alias' => '主题设置', '_desc' => '获取客户端主题设置', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::put('setting', 'saveSetting')->name('SystemClientThemeSettingSave')->option(['_alias' => '保存主题设置', '_desc' => '保存客户端主题设置', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('policy', 'policy')->name('SystemClientThemePolicy')->option(['_alias' => '主题策略', '_desc' => '获取客户端主题策略', '_auth' => true, '_type' => Permission::TYPE_API]);
    Route::put('policy', 'savePolicy')->name('SystemClientThemePolicySave')->option(['_alias' => '保存主题策略', '_desc' => '保存客户端主题策略', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.client.ThemeController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '主题设置',
        '_group_code' => 'SystemClientTheme',
        '_group_name_desc' => '客户端主题与主题设置管理',
        '_parent' => 'SystemClientDiy',
        '_icon' => 'lucide:palette',
        '_path' => '/client/theme',
        '_auth' => true,
        '_component' => '/client/theme/index',
        '_sort' => 30,
    ]);
