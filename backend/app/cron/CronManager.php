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
    protected int $onlyWorkerId;

    public function __construct()
    {
        $this->onlyWorkerId = (int)config('cron.only_worker_id', 0);
    }

    public function boot(int $workerId): void
    {
        // Worker 限制
        if ($workerId !== $this->onlyWorkerId) {
            return;
        }

        if (!$this->isInstalled()) {
            Logger::instance('Cron', static::class)
                ->withData(['worker_id' => $workerId])
                ->info('Cron skipped before install');

            return;
        }

        // 是否启用
        if (!config('cron.enable')) {
            Logger::instance('Cron', static::class)
                ->withData(['worker_id' => $workerId])
                ->info('Cron disabled by env');

            return;
        }

        $log = Logger::instance('Cron', static::class)
            ->withData([
                'worker_id' => $workerId,
            ]);

        $log->success('CronManager boot');

        foreach (config('cron.tasks', []) as $taskClass) {
            try {
                /** @var CronTaskInterface $task */
                $task = app()->make($taskClass);
                $task->register();

                $log->withData([
                    'task' => $taskClass,
                ])->success('Task registered');
            } catch (\Throwable $e) {
                $log->withData([
                    'task' => $taskClass,
                ])->exception($e, 'Task register failed');
            }
        }
    }

    private function isInstalled(): bool
    {
        $projectRoot = dirname(rtrim(root_path(), DIRECTORY_SEPARATOR));
        $deployPath = $projectRoot . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
        if (is_dir(dirname($deployPath))) {
            return is_file($deployPath);
        }

        return is_file(root_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock');
    }
}
