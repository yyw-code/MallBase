<?php

declare (strict_types=1);

namespace app\listener\swoole;

use app\service\upgrade\UpgradeRuntimeLifecycle;
use Swoole\Timer;
use think\swoole\Manager;
use Throwable;

/**
 * Worker 停止前监听器
 * 触发时机：Worker 进程停止前触发
 */
class BeforeWorkerStopListener
{
    public function handle(Manager $manager): void
    {
        Timer::clearAll();
        defined('WORKER_STOPPING') || define('WORKER_STOPPING', true);

        if (!defined('MALLBASE_UPGRADE_WORKER_REGISTERED')) {
            return;
        }
        try {
            $application = $manager->getApplication();
            if ($application === null || !$application->bound(UpgradeRuntimeLifecycle::class)) {
                throw new \RuntimeException('UPGRADE_RUNTIME_LIFECYCLE_UNAVAILABLE');
            }
            /** @var UpgradeRuntimeLifecycle $lifecycle */
            $lifecycle = $application->make(UpgradeRuntimeLifecycle::class);
            $lifecycle->stopWorker();
        } catch (Throwable) {
            // 退出仍继续，进程退出时内核也会释放该进程持有的 SH lock。
            fwrite(STDERR, "[MallBase Upgrade] Worker 生命周期锁释放失败；自动升级保持安全关闭。\n");
        }
    }
}
