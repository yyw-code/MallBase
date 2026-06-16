<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('logistics/platform', function () {
    Route::get('list', 'list')->name('SystemLogisticsPlatformList')->option([
        '_alias' => '平台配置', '_desc' => '获取物流平台配置列表', '_auth' => true,
    ]);
    Route::post('save', 'save')->name('SystemLogisticsPlatformSave')->option([
        '_alias' => '保存平台', '_desc' => '新增或更新物流平台配置', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('clear-cache', 'clearCache')->name('SystemLogisticsPlatformClearCache')->option([
        '_alias' => '清缓存', '_desc' => '清理选中物流平台的轨迹查询缓存', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.logistics.PlatformController/')
  ->option([
      '_group_name'      => '平台配置',
      '_group_code'      => 'SystemLogisticsPlatform',
      '_group_name_desc' => '物流平台配置',
      '_parent'          => 'SystemLogistics',
      '_icon'            => 'lucide:truck',
      '_path'            => '/logistics/platform',
      '_auth'            => true,
      '_component'       => '/logistics/platform/index',
  ]);

Route::group('logistics/company', function () {
    Route::get('list', 'list')->name('SystemLogisticsCompanyList')->option([
        '_alias' => '物流公司', '_desc' => '获取平台物流公司目录', '_auth' => true,
    ]);
    Route::get('options', 'options')->name('SystemLogisticsCompanyOptions')->option([
        '_alias' => '公司选项', '_desc' => '获取发货可用物流公司选项', '_auth' => true,
    ]);
    Route::put('status/:id', 'status')->name('SystemLogisticsCompanyStatus')->option([
        '_alias' => '更新状态', '_desc' => '启用或停用平台物流公司', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('save', 'save')->name('SystemLogisticsCompanySave')->option([
        '_alias' => '保存公司', '_desc' => '新增或更新平台物流公司', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::delete('delete/:id', 'delete')->name('SystemLogisticsCompanyDelete')->option([
        '_alias' => '删除公司', '_desc' => '删除平台物流公司', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.logistics.CompanyController/')
  ->option([
      '_group_name'      => '物流公司',
      '_group_code'      => 'SystemLogisticsCompany',
      '_group_name_desc' => '平台物流公司目录',
      '_parent'          => 'SystemLogistics',
      '_icon'            => 'lucide:package-search',
      '_path'            => '/logistics/company',
      '_auth'            => true,
      '_component'       => '/logistics/company/index',
  ]);
