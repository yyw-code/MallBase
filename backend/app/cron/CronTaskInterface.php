<?php

namespace app\cron;

interface CronTaskInterface
{
    /**
     * 注册定时任务（只做 Timer::tick / after）
     */
    public function register(): void;
}