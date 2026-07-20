<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('system/upgrade', function () {
    Route::get('overview', 'overview')
        ->name('SystemUpgradeOverview')
        ->option([
            '_auth' => true,
            '_permission' => 'SystemUpgrade',
        ]);
    Route::get('releases', 'releases')
        ->name('SystemUpgradeReleases')
        ->option([
            '_auth' => true,
            '_permission' => 'SystemUpgrade',
        ]);
    Route::get('records', 'records')
        ->name('SystemUpgradeRecords')
        ->option([
            '_auth' => true,
            '_permission' => 'SystemUpgrade',
        ]);
    Route::post('jobs', 'createJob')
        ->name('SystemUpgradeJobCreate')
        ->option([
            '_alias' => '创建系统升级任务',
            '_desc' => '创建宿主机一次性升级或回滚任务',
            '_auth' => true,
            '_type' => Permission::TYPE_BUTTON,
        ]);
})->prefix('admin.upgrade.UpgradeController/')
    ->option([
        '_group_name' => '系统升级',
        '_group_code' => 'SystemUpgrade',
        '_group_name_desc' => '查看版本与记录，并创建一次性升级任务',
        '_path' => '/system/upgrade',
        '_auth' => true,
        '_icon' => 'lucide:refresh-cw',
        '_parent' => 'System',
        '_component' => '/system/upgrade/index',
    ]);
