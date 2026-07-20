<?php

declare(strict_types=1);

namespace app\listener\swoole;

use app\cron\CronManager;
use Closure;
use think\swoole\Manager;

class WorkerBootListener
{
    public function handle(Manager $manager, string $serverName): void
    {
        // 保持现有 Cron 启动顺序；只有 worker 0 且 cron.enable=true 时实际注册任务。
        $runInSandbox = static function (callable $callback) use ($manager): void {
            $manager->runInSandbox($callback instanceof Closure ? $callback : Closure::fromCallable($callback));
        };

        app()->make(CronManager::class)->boot($manager->getWorkerId(), $runInSandbox);
    }
}
