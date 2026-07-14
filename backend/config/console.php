<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
use app\command\Docs;
use app\command\ImportRegions;
use app\command\InstallAuto;
use app\command\OrderExpireCommand;
use app\command\OrderRecoverPaid;
use app\command\OrderRecoverRefund;
use app\command\PointsReleaseCommand;
use app\command\SyncPermissions;
use app\command\SyncSettingPermissions;
use app\command\StorageCutoverTargetSnapshot;
use app\command\UpgradeBootstrapRetentionFinalize;
use app\command\UpgradeAdminSchema;
use app\command\UpgradeClientDecorationCustomMenu;
use app\command\UpgradeClientSearchSchema;
use app\command\UpgradeUserRegisterType;
use app\command\UpgradeUserWechatSchema;

return [
    // 指令定义
    'commands' => [
        'docs' => Docs::class,
        'region:import' => ImportRegions::class,
        'sync:permissions' => SyncPermissions::class,
        'settings:sync-permissions' => SyncSettingPermissions::class,
        'order:expire' => OrderExpireCommand::class,
        'order:recover-paid' => OrderRecoverPaid::class,
        'order:recover-refund' => OrderRecoverRefund::class,
        'points:release' => PointsReleaseCommand::class,
        'install:auto' => InstallAuto::class,
        'upgrade:storage-cutover-target-snapshot' => StorageCutoverTargetSnapshot::class,
        'upgrade:bootstrap-retention-finalize' => UpgradeBootstrapRetentionFinalize::class,
        'upgrade:admin-schema' => UpgradeAdminSchema::class,
        'upgrade:client-decoration-custom-menu' => UpgradeClientDecorationCustomMenu::class,
        'upgrade:client-search-schema' => UpgradeClientSearchSchema::class,
        'upgrade:user-register-type' => UpgradeUserRegisterType::class,
        'upgrade:user-wechat-schema' => UpgradeUserWechatSchema::class,
    ],
];
