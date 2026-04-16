<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
use app\command\Docs;
use app\command\ImportRegions;
use app\command\OrderExpireCommand;
use app\command\SyncPermissions;

return [
    // 指令定义
    'commands' => [
        'docs' => Docs::class,
        'region:import' => ImportRegions::class,
        'sync:permissions' => SyncPermissions::class,
        'order:expire' => OrderExpireCommand::class,
    ],
];
