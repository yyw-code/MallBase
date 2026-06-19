<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('notification', function () {
    Route::get('pending-shipment', 'pendingShipment')
        ->name('SystemNotificationBell')
        ->option(['_alias' => '通知', '_desc' => '右上角小铃铛通知', '_auth' => true, '_permission' => 'SystemNotificationBell', '_type' => Permission::TYPE_BUTTON]);
    Route::get('refund-pending', 'refundPending')
        ->name('SystemNotificationRefundPending')
        ->option(['_auth' => true, '_permission' => 'SystemNotificationBell']);
    Route::get('stock-warning', 'stockWarning')
        ->name('SystemNotificationStockWarning')
        ->option(['_auth' => true, '_permission' => 'SystemNotificationBell']);
    Route::get('logistics-config', 'logisticsConfig')
        ->name('SystemNotificationLogisticsConfig')
        ->option(['_auth' => true, '_permission' => 'SystemNotificationBell']);
    Route::get('sms-provider-config', 'smsProviderConfig')
        ->name('SystemNotificationSmsProviderConfig')
        ->option(['_auth' => true, '_permission' => 'SystemNotificationBell']);
})->prefix('admin.NotificationController/')
    ->option([
        '_parent' => 'Workspace',
        '_auth'   => true,
    ]);
