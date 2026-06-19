<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('refund', function () {
    Route::post('apply', 'apply');
    Route::post('batchApply', 'batchApply');
    Route::post('cancel/:id', 'cancel');
    Route::post('return/:id', 'submitReturn');
    Route::get('list', 'list');
    Route::get('detail/:id', 'detail');
    Route::get('reasonOptions', 'reasonOptions');
})->prefix('client.order.RefundOrderController/')->middleware([JwtAuth::class]);
