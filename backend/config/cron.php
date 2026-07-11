<?php

$installLockPath = runtime_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
$isInstalled = is_file($installLockPath);
$cronEnabled = filter_var(env('CRON_ENABLE', false), FILTER_VALIDATE_BOOLEAN);

return [

    // 是否启用 Cron
    'enable' => $isInstalled && $cronEnabled,

    // 只允许哪个 worker 启动
    'only_worker_id' => 0,


    /*
     |--------------------------------------------------------------------------
     | 定时任务类
     |--------------------------------------------------------------------------
     | 按业务域拆分注册，避免所有周期维护集中到单个任务入口。
     */

    'tasks' => [
        app\cron\tasks\OrderMaintenanceCron::class,
        app\cron\tasks\PointsMaintenanceCron::class,
        app\cron\tasks\DistributionMaintenanceCron::class,
    ],
];
