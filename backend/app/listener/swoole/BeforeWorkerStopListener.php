<?php

declare (strict_types=1);

namespace app\listener\swoole;

use Swoole\Timer;
use think\swoole\Manager;

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
    }
}
