<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
use app\command\Docs;
use app\command\ImportRegions;
use app\command\InstallAuto;
use app\command\OrderExpireCommand;
use app\command\SyncPermissions;
use app\command\SyncSettingPermissions;
use app\command\UpgradeAdminSchema;

return [
    // 指令定义
    'commands' => [
        'docs' => Docs::class,
        'region:import' => ImportRegions::class,
        'sync:permissions' => SyncPermissions::class,
        'settings:sync-permissions' => SyncSettingPermissions::class,
        'order:expire' => OrderExpireCommand::class,
        'install:auto' => InstallAuto::class,
        'upgrade:admin-schema' => UpgradeAdminSchema::class,
    ],
];
