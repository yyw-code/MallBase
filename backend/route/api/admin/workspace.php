<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('workspace', function () {
    Route::get('todos/pendingShipment', 'pendingShipmentTodo')->name('SystemWorkspaceTodos')->option([
        '_alias'      => '待办事项',
        '_desc'       => '获取工作台待办事项',
        '_auth'       => true,
        '_permission' => 'SystemWorkspaceTodos',
        '_type'       => Permission::TYPE_BUTTON,
    ]);
    Route::get('todos/refundPending', 'refundPendingTodo')->name('SystemWorkspaceTodoRefundPending')->option([
        '_auth'       => true,
        '_permission' => 'SystemWorkspaceTodos',
    ]);
    Route::get('todos/stockWarning', 'stockWarningTodo')->name('SystemWorkspaceTodoStockWarning')->option([
        '_auth'       => true,
        '_permission' => 'SystemWorkspaceTodos',
    ]);
    Route::get('todos/logisticsConfig', 'logisticsConfigTodo')->name('SystemWorkspaceTodoLogisticsConfig')->option([
        '_auth'       => true,
        '_permission' => 'SystemWorkspaceTodos',
    ]);
    Route::get('todos/smsProviderConfig', 'smsProviderConfigTodo')->name('SystemWorkspaceTodoSmsProviderConfig')->option([
        '_auth'       => true,
        '_permission' => 'SystemWorkspaceTodos',
    ]);
    Route::get('shortcuts', 'shortcuts')->name('SystemWorkspaceShortcuts')->option([
        '_alias' => '快捷入口列表',
        '_desc'  => '获取工作台快捷入口',
        '_auth'  => true,
        '_type'  => Permission::TYPE_BUTTON,
    ]);
    Route::get('menu-options', 'menuOptions')->name('SystemWorkspaceMenuOptions')->option([
        '_alias' => '工作台菜单选项',
        '_desc'  => '获取工作台菜单选项',
        '_auth'  => true,
        '_type'  => Permission::TYPE_BUTTON,
    ]);

    Route::put('shortcuts', 'updateShortcuts')->name('SystemWorkspaceShortcutsUpdate')->option([
        '_alias' => '保存工作台快捷入口',
        '_desc'  => '保存当前管理员的工作台快捷入口',
        '_auth'  => true,
        '_type'  => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.WorkspaceController/')
    ->option([
        '_parent' => 'Workspace',
        '_auth'   => true,
    ]);
