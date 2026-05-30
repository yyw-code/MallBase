<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('demo', function () {
    Route::post('reset', 'reset')->name('SystemDemoResetExecute')->option([
        '_alias' => '恢复演示数据',
        '_desc'  => '将演示站数据恢复到安装演示状态',
        '_auth'  => true,
        '_type'  => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.demo.DemoResetController/')
    ->option([
        '_group_name' => '演示站维护',
        '_group_code' => 'SystemDemoReset',
        '_path'       => '/demo/reset',
        '_auth'       => true,
        '_icon'       => 'lucide:refresh-cw',
        '_parent'     => 'System',
        '_component'  => '',
        '_is_show'    => 0,
    ]);
