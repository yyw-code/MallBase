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
        // 1️⃣ 清理所有定时器
        Timer::clearAll();

        // 2️⃣ 标记 worker 正在退出（可选）
        defined('WORKER_STOPPING') || define('WORKER_STOPPING', true);

        // 3️⃣ 关闭自定义资源（示例）
        // MyTcpClient::close();
        // MyRedisPool::close();

        echo "[Swoole] Worker stopping, resources cleaned\n";
    }
}