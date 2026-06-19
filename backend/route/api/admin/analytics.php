<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('analytics', function () {
    Route::get('cards', 'cards')
        ->name('SystemAnalyticsCards')
        ->option(['_alias' => '经营分析卡片', '_desc' => '获取经营分析关键统计卡片', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('trend', 'trend')
        ->name('SystemAnalyticsTrend')
        ->option(['_alias' => '经营趋势', '_desc' => '获取交易趋势数据', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('monthly-orders', 'monthlyOrders')
        ->name('SystemAnalyticsMonthlyOrders')
        ->option(['_alias' => '月度订单', '_desc' => '获取月度订单趋势', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('health', 'health')
        ->name('SystemAnalyticsHealth')
        ->option(['_alias' => '运营健康度', '_desc' => '获取运营健康度指标', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('order-channels', 'orderChannels')
        ->name('SystemAnalyticsOrderChannels')
        ->option(['_alias' => '订单来源', '_desc' => '获取订单来源占比', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('sales-structure', 'salesStructure')
        ->name('SystemAnalyticsSalesStructure')
        ->option(['_alias' => '商品结构', '_desc' => '获取商品结构分布', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.AnalyticsController/')
    ->option([
        '_parent' => 'Analytics',
        '_auth'   => true,
    ]);
