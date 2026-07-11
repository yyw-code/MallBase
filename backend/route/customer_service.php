<?php
declare(strict_types=1);

use app\middleware\connector\CustomerServiceSignature;
use think\facade\Route;

Route::group('customer-service/api/v1', function () {
    Route::get('health', 'health');
    Route::post('context-token', 'contextToken');

    Route::post('products/search', 'productSearch');
    Route::get('products/:id/summary', 'productSummary');
    Route::get('orders/:id/summary', 'orderSummary');
    Route::get('users/:id/summary', 'userSummary');

    Route::post('orders/:id/remarks', 'addOrderRemark');
    Route::post('orders/:id/ship', 'shipOrder');
    Route::post('refunds/:id/approve', 'approveRefund');
    Route::post('refunds/:id/reject', 'rejectRefund');
})
    ->prefix('connector.CustomerServiceController/')
    ->middleware(CustomerServiceSignature::class);
