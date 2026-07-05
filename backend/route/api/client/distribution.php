<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('distribution', function () {
    Route::get('summary', 'summary');
    Route::get('commissions', 'commissions');
    Route::get('logs', 'logs');
    Route::get('team', 'team');
    Route::get('withdraws', 'withdraws');
    Route::post('withdraw', 'applyWithdraw');
    Route::post('bindInvite', 'bindInvite');
})->prefix('client.distribution.DistributionController/')
    ->middleware([JwtAuth::class]);
