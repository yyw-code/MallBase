<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('order', function () {
    Route::get('list', 'list')->name('SystemOrderList')->option(['_alias' => '订单列表', '_desc' => '获取订单列表', '_auth' => true]);
    Route::get('detail/:id', 'detail')->name('SystemOrderDetail')->option(['_alias' => '订单详情', '_desc' => '获取订单详情', '_auth' => true]);
    Route::get('statusOptions', 'statusOptions')->name('SystemOrderStatusOptions')->option(['_alias' => '订单枚举', '_desc' => '获取订单状态/支付方式下拉项', '_auth' => true]);
    Route::post('ship/:id', 'ship')->name('SystemOrderShip')->option(['_alias' => '订单发货', '_desc' => '后台发货（PAID→SHIPPED）', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('close/:id', 'close')->name('SystemOrderClose')->option(['_alias' => '关闭订单', '_desc' => '后台关闭订单（同步回滚库存）', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('adjustPrice/:id', 'adjustPrice')->name('SystemOrderAdjustPrice')->option(['_alias' => '订单改价', '_desc' => '调整运费/优惠并重算应付金额（仅待支付订单）', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.order.OrderController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '订单列表',
        '_group_code'      => 'SystemOrder',
        '_group_name_desc' => '订单管理模块',
        '_parent'          => 'SystemOrderManagement',
        '_icon'            => 'lucide:clipboard-list',
        '_path'            => '/order',
        '_auth'            => true,
        '_component'       => '/order/index',
    ]);

Route::group('order/refund', function () {
    Route::get('list', 'list')->name('SystemRefundOrderList')->option(['_alias' => '售后列表', '_desc' => '获取售后订单列表', '_auth' => true]);
    Route::get('detail/:id', 'detail')->name('SystemRefundOrderDetail')->option(['_alias' => '售后详情', '_desc' => '获取售后订单详情', '_auth' => true]);
    Route::post('approve/:id', 'approve')->name('SystemRefundOrderApprove')->option(['_alias' => '同意售后', '_desc' => '审核同意售后申请并发起微信退款', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('reject/:id', 'reject')->name('SystemRefundOrderReject')->option(['_alias' => '驳回售后', '_desc' => '审核驳回售后申请', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('statusOptions', 'statusOptions')->name('SystemRefundOrderStatusOptions')->option(['_alias' => '售后枚举', '_desc' => '获取售后状态/类型/原因下拉项', '_auth' => true]);
    Route::get('reasonOptions', 'reasonOptions')->name('SystemRefundOrderReasonOptions')->option(['_alias' => '售后原因枚举', '_desc' => '获取售后原因下拉项', '_auth' => true]);
})->prefix('admin.order.RefundOrderController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '售后订单',
        '_group_code'      => 'SystemRefundOrder',
        '_group_name_desc' => '售后/退款/退货管理模块',
        '_parent'          => 'SystemOrderManagement',
        '_icon'            => 'lucide:undo-2',
        '_path'            => '/order/refund',
        '_auth'            => true,
        '_component'       => '/order/refund/index',
    ]);
