<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('setting/freight-template', function () {
    Route::get('list', 'list')->name('SystemFreightTemplateList')->option(['_alias' => '模板列表', '_desc' => '获取运费模板列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemFreightTemplateInfo')->option(['_alias' => '模板详情', '_desc' => '获取运费模板详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemFreightTemplateCreate')->option(['_alias' => '新增模板', '_desc' => '新增运费模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemFreightTemplateUpdate')->option(['_alias' => '编辑模板', '_desc' => '编辑运费模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemFreightTemplateDelete')->option(['_alias' => '删除模板', '_desc' => '删除运费模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemFreightTemplateUpdateStatus')->option(['_alias' => '模板状态', '_desc' => '更新运费模板状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('refreshInvalid', 'refreshInvalid')->name('SystemFreightTemplateRefreshInvalid')->option(['_alias' => '更新失效数据', '_desc' => '按编码重匹配失效运费规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.setting.FreightTemplateController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '运费模板',
        '_group_code'      => 'SystemFreightTemplate',
        '_group_name_desc' => '运费模板管理',
        '_parent'          => 'SystemGoodsManagement',
        '_icon'            => 'lucide:truck',
        '_path'            => '/settings/freight-template',
        '_auth'            => true,
        '_component'       => '/settings/freight-template/index',
    ]);
