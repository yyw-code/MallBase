<?php

namespace app\cron\tasks;

use app\cron\CronTaskInterface;
use Swoole\Timer;
use think\facade\Log;

class TestCron implements CronTaskInterface
{
    public function register(): void
    {
        // 每 1 分钟执行
        Timer::tick(1000, function () {
            echo "1 分钟执行一次\n";
        });
    }
}