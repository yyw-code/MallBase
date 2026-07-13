<?php

declare(strict_types=1);

namespace app\listener\swoole;

use app\cron\CronManager;
use app\service\upgrade\UpgradeRuntimeLifecycle;
use app\service\upgrade\UpgradeRuntimeFailureLatch;
use Closure;
use RuntimeException;
use think\swoole\Manager;
use Throwable;

class WorkerBootListener
{
    public function handle(Manager $manager, string $serverName): void
    {
        $workerId = $manager->getWorkerId();
        if (str_starts_with($serverName, 'http server') || str_starts_with($serverName, 'queue [')) {
            $this->registerUpgradeRuntime($serverName, $workerId);
        }

        // 保持现有 Cron 启动顺序；只有 worker 0 且 cron.enable=true 时实际注册任务。
        $runInSandbox = static function (callable $callback) use ($manager): void {
            $manager->runInSandbox($callback instanceof Closure ? $callback : Closure::fromCallable($callback));
        };

        app()->make(CronManager::class)->boot($workerId, $runInSandbox);
    }

    private function registerUpgradeRuntime(string $serverName, int $workerId): void
    {
        $configuration = config('swoole.upgrade_runtime', []);
        if (($configuration['enable'] ?? false) !== true) {
            return;
        }
        $blocksCommercialStartup = ($configuration['failure_blocks_commercial_startup'] ?? false) === true;

        try {
            if (!app()->bound(UpgradeRuntimeLifecycle::class)) {
                throw new RuntimeException('UPGRADE_RUNTIME_LIFECYCLE_UNAVAILABLE');
            }
            /** @var UpgradeRuntimeLifecycle $lifecycle */
            $lifecycle = app()->make(UpgradeRuntimeLifecycle::class);
            $cronEnabled = str_starts_with($serverName, 'http server')
                && (bool) config('cron.enable', false)
                && $workerId === (int) config('cron.only_worker_id', 0);
            $lifecycle->registerWorker($serverName, $workerId, $cronEnabled);
            defined('MALLBASE_UPGRADE_WORKER_REGISTERED')
                || define('MALLBASE_UPGRADE_WORKER_REGISTERED', true);
        } catch (Throwable $exception) {
            try {
                /** @var UpgradeRuntimeFailureLatch $latch */
                $latch = app()->make(UpgradeRuntimeFailureLatch::class);
                $latch->taintWorker($serverName, $cronEnabled ?? false);
                defined('MALLBASE_AUTOMATIC_UPGRADE_DISABLED')
                    || define('MALLBASE_AUTOMATIC_UPGRADE_DISABLED', true);
                fwrite(STDERR, "[MallBase Upgrade] Worker 运行登记失败，已持久化安全闩；自动升级保持禁用。\n");
            } catch (Throwable $latchFailure) {
                throw new RuntimeException('UPGRADE_RUNTIME_FAILURE_LATCH_UNAVAILABLE', 0, $latchFailure);
            }
            if ($blocksCommercialStartup) {
                throw new RuntimeException('UPGRADE_RUNTIME_WORKER_REGISTRATION_FAILED', 0, $exception);
            }
        }
    }
}
