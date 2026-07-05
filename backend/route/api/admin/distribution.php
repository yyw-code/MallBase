<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('distribution', function () {
    Route::get('overview', 'overview')->name('SystemDistributionOverview')->option(['_alias' => '分销概览', '_desc' => '获取分销概览统计', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('releaseDue', 'releaseDue')->name('SystemDistributionReleaseDue')->option(['_alias' => '手动结算到期佣金', '_desc' => '手动结算到期分销佣金', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.DistributionController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分销概览',
        '_group_code'      => 'SystemDistribution',
        '_group_name_desc' => '分销模块概览与基础设置',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:network',
        '_path'            => '/distribution',
        '_auth'            => true,
        '_component'       => '/distribution/index',
        '_sort'            => 10,
    ]);

Route::group('distribution/settings', function () {
    Route::get('info', 'settings')->name('SystemDistributionSettingsInfo')->option(['_alias' => '分销设置详情', '_desc' => '获取分销基础设置', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::put('save', 'saveSettings')->name('SystemDistributionSettingsSave')->option(['_alias' => '保存设置', '_desc' => '保存分销基础设置', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.DistributionController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分销基础设置',
        '_group_code'      => 'SystemDistributionSettings',
        '_group_name_desc' => '分销总开关、结算、提现与默认佣金比例配置',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:settings-2',
        '_path'            => '/distribution/settings',
        '_auth'            => true,
        '_component'       => '/distribution/settings/index',
        '_sort'            => 20,
    ]);

Route::group('distribution/distributor', function () {
    Route::get('list', 'list')->name('SystemDistributionDistributorList')->option(['_alias' => '分销员列表', '_desc' => '获取分销员列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemDistributionDistributorInfo')->option(['_alias' => '分销员详情', '_desc' => '获取分销员详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('open', 'open')->name('SystemDistributionDistributorOpen')->option(['_alias' => '开通分销员', '_desc' => '后台开通分销员资格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('status/:id', 'updateStatus')->name('SystemDistributionDistributorStatus')->option(['_alias' => '分销员状态', '_desc' => '更新分销员状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.DistributorController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分销员',
        '_group_code'      => 'SystemDistributionDistributor',
        '_group_name_desc' => '分销员开通与账户管理',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:user-round-check',
        '_path'            => '/distribution/distributor',
        '_auth'            => true,
        '_component'       => '/distribution/distributor/index',
        '_sort'            => 30,
    ]);

Route::group('distribution/level', function () {
    Route::get('list', 'list')->name('SystemDistributionLevelList')->option(['_alias' => '等级列表', '_desc' => '获取分销员等级列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemDistributionLevelInfo')->option(['_alias' => '等级详情', '_desc' => '获取分销员等级详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemDistributionLevelCreate')->option(['_alias' => '新增等级', '_desc' => '新增分销员等级', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemDistributionLevelUpdate')->option(['_alias' => '编辑等级', '_desc' => '编辑分销员等级', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemDistributionLevelDelete')->option(['_alias' => '删除等级', '_desc' => '删除分销员等级', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemDistributionLevelUpdateStatus')->option(['_alias' => '等级状态', '_desc' => '更新分销员等级状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.LevelController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分销等级',
        '_group_code'      => 'SystemDistributionLevel',
        '_group_name_desc' => '分销员等级与默认佣金比例',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:badge-percent',
        '_path'            => '/distribution/level',
        '_auth'            => true,
        '_component'       => '/distribution/level/index',
        '_sort'            => 40,
    ]);

Route::group('distribution/rule', function () {
    Route::get('list', 'list')->name('SystemDistributionRuleList')->option(['_alias' => '规则列表', '_desc' => '获取分销佣金规则列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemDistributionRuleInfo')->option(['_alias' => '规则详情', '_desc' => '获取分销佣金规则详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemDistributionRuleCreate')->option(['_alias' => '新增规则', '_desc' => '新增分销佣金规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemDistributionRuleUpdate')->option(['_alias' => '编辑规则', '_desc' => '编辑分销佣金规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemDistributionRuleDelete')->option(['_alias' => '删除规则', '_desc' => '删除分销佣金规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemDistributionRuleUpdateStatus')->option(['_alias' => '规则状态', '_desc' => '更新分销佣金规则状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.RuleController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '佣金规则',
        '_group_code'      => 'SystemDistributionRule',
        '_group_name_desc' => '分类、商品、SKU 佣金规则',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:settings-2',
        '_path'            => '/distribution/rule',
        '_auth'            => true,
        '_component'       => '/distribution/rule/index',
        '_sort'            => 50,
    ]);

Route::group('distribution/commission', function () {
    Route::get('list', 'list')->name('SystemDistributionCommissionList')->option(['_alias' => '佣金订单', '_desc' => '获取分销佣金订单列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('logs', 'logs')->name('SystemDistributionCommissionLogList')->option(['_alias' => '佣金流水', '_desc' => '获取分销佣金流水', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('adjust', 'adjust')->name('SystemDistributionCommissionAdjust')->option(['_alias' => '调整佣金', '_desc' => '后台调整分销员佣金', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.CommissionController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分销佣金',
        '_group_code'      => 'SystemDistributionCommission',
        '_group_name_desc' => '分销佣金订单与佣金流水',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:coins',
        '_path'            => '/distribution/commission',
        '_auth'            => true,
        '_component'       => '/distribution/commission/index',
        '_sort'            => 60,
    ]);

Route::group('distribution/withdraw', function () {
    Route::get('list', 'list')->name('SystemDistributionWithdrawList')->option(['_alias' => '提现列表', '_desc' => '获取分销提现申请列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('approve/:id', 'approve')->name('SystemDistributionWithdrawApprove')->option(['_alias' => '通过提现', '_desc' => '审核通过分销提现', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('reject/:id', 'reject')->name('SystemDistributionWithdrawReject')->option(['_alias' => '驳回提现', '_desc' => '审核驳回分销提现', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.distribution.WithdrawController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分销提现',
        '_group_code'      => 'SystemDistributionWithdraw',
        '_group_name_desc' => '分销佣金提现审核',
        '_parent'          => 'SystemDistributionManagement',
        '_icon'            => 'lucide:wallet-cards',
        '_path'            => '/distribution/withdraw',
        '_auth'            => true,
        '_component'       => '/distribution/withdraw/index',
        '_sort'            => 70,
    ]);
