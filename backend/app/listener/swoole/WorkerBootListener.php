<?php

namespace app\listener\swoole;

use think\swoole\Manager;
use app\cron\CronManager;

class WorkerBootListener
{
    public function handle(Manager $manager, string $serverName)
    {

        // 启动定时任务（只会在 worker 0 生效）
        // 获取 workerId
        $workerId = $manager->getWorkerId();
        app()->make(CronManager::class)->boot($workerId);
    }
}