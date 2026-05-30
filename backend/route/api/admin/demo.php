<?php

use app\model\auth\Permission;
use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use think\facade\Route;

Route::group('demo', function () {
    Route::post('reset', 'reset')->name('SystemDemoResetExecute')->option([
        '_alias' => '恢复演示数据',
        '_desc'  => '将演示站数据恢复到安装演示状态',
        '_auth'  => true,
        '_type'  => Permission::TYPE_BUTTON,
    ]);
    Route::post('reset/start', 'start')->option([
        '_alias' => '公开发起演示数据恢复',
        '_desc'  => '登录页发起演示站数据恢复任务',
        '_auth'  => false,
    ])->withoutMiddleware([JwtAuth::class, CheckPermission::class]);
    Route::get('reset/status', 'status')->option([
        '_alias' => '公开查询演示数据恢复状态',
        '_desc'  => '登录页查询演示站数据恢复任务状态',
        '_auth'  => false,
    ])->withoutMiddleware([JwtAuth::class, CheckPermission::class]);
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
