<?php

$projectRoot = dirname(rtrim(root_path(), DIRECTORY_SEPARATOR));
$installLockPath = $projectRoot . DIRECTORY_SEPARATOR . 'deploy'
    . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
if (!is_dir(dirname($installLockPath))) {
    $installLockPath = root_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
}
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
     | 这里只放「任务类名」
     */

    'tasks' => [
        app\cron\tasks\OrderMaintenanceCron::class,
    ],
];
