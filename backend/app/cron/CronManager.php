<?php

namespace app\cron;

use mall_base\log\Logger;
use Swoole\Timer;
use think\facade\Log;

class CronManager
{
    /**
     * 只允许一个 Worker 初始化
     */
    protected int $onlyWorkerId = 0;

    public function boot(int $workerId): void
    {
        if ($workerId !== $this->onlyWorkerId) {
            return;
        }
        $log = Logger::instance('Cron', static::class)
            ->withContext([
                'worker_id' => $workerId,
            ]);


        foreach (config('cron.tasks', []) as $taskClass) {
            try {
                /** @var CronTaskInterface $task */
                $task = app()->make($taskClass);
                $task->register();

                $log->withContext([
                    'task' => $taskClass,
                ])->success('Task registered');
            } catch (\Throwable $e) {
                $log->withContext([
                    'task' => $taskClass,
                ])->exception($e, 'Task register failed');
            }
        }
    }
}