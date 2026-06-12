<?php

namespace app\listener\swoole;

use app\cron\CronManager;
use Closure;
use think\swoole\Manager;

class WorkerBootListener
{
    public function handle(Manager $manager, string $serverName)
    {

        // 启动定时任务（只会在 worker 0 生效）
        // 获取 workerId
        $workerId = $manager->getWorkerId();
        $runInSandbox = static function (callable $callback) use ($manager): void {
            $manager->runInSandbox($callback instanceof Closure ? $callback : Closure::fromCallable($callback));
        };

        app()->make(CronManager::class)->boot($workerId, $runInSandbox);
    }
}
