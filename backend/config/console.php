<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
use app\command\Docs;
use app\command\DemoResetRun;
use app\command\ImportRegions;
use app\command\InstallAuto;
use app\command\OrderExpireCommand;
use app\command\OrderRecoverPaid;
use app\command\OrderRecoverRefund;
use app\command\PointsReleaseCommand;
use app\command\SyncPermissions;
use app\command\SyncSettingPermissions;
use app\command\UpgradeRuntimeCommand;

return [
    // 指令定义
    'commands' => [
        'docs' => Docs::class,
        'demo:reset-run' => DemoResetRun::class,
        'region:import' => ImportRegions::class,
        'sync:permissions' => SyncPermissions::class,
        'settings:sync-permissions' => SyncSettingPermissions::class,
        'order:expire' => OrderExpireCommand::class,
        'order:recover-paid' => OrderRecoverPaid::class,
        'order:recover-refund' => OrderRecoverRefund::class,
        'points:release' => PointsReleaseCommand::class,
        'install:auto' => InstallAuto::class,
        'upgrade:runtime' => UpgradeRuntimeCommand::class,
    ],
];
