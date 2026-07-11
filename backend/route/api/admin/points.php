<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('points/rule', function () {
    Route::get('list', 'list')->name('SystemPointsRuleList')->option(['_alias' => '规则列表', '_desc' => '获取积分规则列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemPointsRuleInfo')->option(['_alias' => '规则详情', '_desc' => '获取积分规则详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('scenes', 'scenes')->name('SystemPointsRuleScenes')->option(['_alias' => '规则场景', '_desc' => '获取积分规则场景选项', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemPointsRuleCreate')->option(['_alias' => '新增规则', '_desc' => '新增积分规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemPointsRuleUpdate')->option(['_alias' => '编辑规则', '_desc' => '编辑积分规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemPointsRuleDelete')->option(['_alias' => '删除规则', '_desc' => '删除积分规则', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemPointsRuleUpdateStatus')->option(['_alias' => '规则状态', '_desc' => '更新积分规则状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.marketing.PointsRuleController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '积分规则',
        '_group_code'      => 'SystemPointsRule',
        '_group_name_desc' => '积分奖励规则配置',
        '_parent'          => 'SystemPointsManagement',
        '_icon'            => 'lucide:settings-2',
        '_path'            => '/points/rule',
        '_auth'            => true,
        '_component'       => '/points/rule/index',
        '_sort'            => 20,
    ]);

Route::group('points/goods', function () {
    Route::get('list', 'list')->name('SystemPointsGoodsList')->option(['_alias' => '积分商品列表', '_desc' => '获取积分商品列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemPointsGoodsInfo')->option(['_alias' => '积分商品详情', '_desc' => '获取积分商品详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemPointsGoodsCreate')->option(['_alias' => '新增积分商品', '_desc' => '新增积分兑换商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemPointsGoodsUpdate')->option(['_alias' => '编辑积分商品', '_desc' => '编辑积分兑换商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemPointsGoodsDelete')->option(['_alias' => '删除积分商品', '_desc' => '删除积分兑换商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemPointsGoodsUpdateStatus')->option(['_alias' => '积分商品状态', '_desc' => '更新积分商品状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.marketing.PointsGoodsController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '积分商品',
        '_group_code'      => 'SystemPointsGoods',
        '_group_name_desc' => '积分商城兑换商品配置',
        '_parent'          => 'SystemPointsManagement',
        '_icon'            => 'lucide:gift',
        '_path'            => '/points/goods',
        '_auth'            => true,
        '_component'       => '/points/goods/index',
        '_sort'            => 30,
    ]);

Route::group('points/exchange-order', function () {
    Route::get('list', 'list')->name('SystemPointsExchangeOrderList')->option(['_alias' => '兑换单列表', '_desc' => '获取积分兑换单列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemPointsExchangeOrderInfo')->option(['_alias' => '兑换单详情', '_desc' => '获取积分兑换单详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('statusOptions', 'statusOptions')->name('SystemPointsExchangeOrderStatusOptions')->option(['_alias' => '兑换单状态', '_desc' => '获取兑换单状态选项', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('ship/:id', 'ship')->name('SystemPointsExchangeOrderShip')->option(['_alias' => '兑换单发货', '_desc' => '积分兑换单发货', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('complete/:id', 'complete')->name('SystemPointsExchangeOrderComplete')->option(['_alias' => '完成兑换单', '_desc' => '完成积分兑换单', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('close/:id', 'close')->name('SystemPointsExchangeOrderClose')->option(['_alias' => '关闭兑换单', '_desc' => '关闭积分兑换单并返还积分库存', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.marketing.PointsExchangeOrderController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '兑换订单',
        '_group_code'      => 'SystemPointsExchangeOrder',
        '_group_name_desc' => '积分商城兑换订单履约',
        '_parent'          => 'SystemPointsManagement',
        '_icon'            => 'lucide:package-check',
        '_path'            => '/points/exchange-order',
        '_auth'            => true,
        '_component'       => '/points/exchange-order/index',
        '_sort'            => 40,
    ]);

Route::group('points/log', function () {
    Route::get('list', 'logs')->name('SystemPointsLogList')->option(['_alias' => '积分流水', '_desc' => '获取积分流水列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
})->prefix('admin.user.UserPointsController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '积分流水',
        '_group_code'      => 'SystemPointsLog',
        '_group_name_desc' => '积分账户流水查询',
        '_parent'          => 'SystemPointsManagement',
        '_icon'            => 'lucide:list-checks',
        '_path'            => '/points/log',
        '_auth'            => true,
        '_component'       => '/points/log/index',
        '_sort'            => 50,
    ]);

Route::group('user/points', function () {
    Route::get('logs', 'logs')->name('SystemUserPointsLog')->option(['_alias' => '用户积分记录', '_desc' => '查看用户积分流水', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('adjust', 'adjust')->name('SystemUserPointsAdjust')->option(['_alias' => '调整用户积分', '_desc' => '后台调整用户积分', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.user.UserPointsController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '用户管理',
        '_group_code'      => 'SystemClientUserList',
        '_group_name_desc' => '前台用户积分记录和调整权限',
        '_parent'          => 'SystemClientUserManagement',
        '_icon'            => 'lucide:users',
        '_path'            => '/user',
        '_component'       => '/user/index',
        '_auth'            => true,
    ]);
