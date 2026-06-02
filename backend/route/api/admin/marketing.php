<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('marketing/recharge-package', function () {
    Route::get('list', 'list')->name('SystemRechargePackageList')->option(['_alias' => '套餐列表', '_desc' => '获取充值套餐列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemRechargePackageInfo')->option(['_alias' => '套餐详情', '_desc' => '获取充值套餐详情', '_auth' => true]);
    Route::post('create', 'create')->name('SystemRechargePackageCreate')->option(['_alias' => '新增套餐', '_desc' => '新增充值套餐', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemRechargePackageUpdate')->option(['_alias' => '编辑套餐', '_desc' => '编辑充值套餐', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemRechargePackageDelete')->option(['_alias' => '删除套餐', '_desc' => '删除充值套餐', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemRechargePackageUpdateStatus')->option(['_alias' => '套餐状态', '_desc' => '更新充值套餐状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.marketing.RechargePackageController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '充值套餐',
        '_group_code'      => 'SystemRechargePackage',
        '_group_name_desc' => '余额充值套餐运营配置',
        '_parent'          => 'SystemMarketingManagement',
        '_icon'            => 'lucide:badge-dollar-sign',
        '_path'            => '/marketing/recharge-package',
        '_auth'            => true,
        '_component'       => '/marketing/recharge-package/index',
    ]);
