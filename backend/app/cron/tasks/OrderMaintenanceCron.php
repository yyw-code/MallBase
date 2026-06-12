<?php

declare(strict_types=1);

namespace app\cron\tasks;

use app\cron\CronTaskInterface;
use app\job\AutoReceiveOrdersJob;
use app\job\CloseExpiredOrdersJob;
use mall_base\queue\JobQueue;
use Swoole\Timer;
use think\facade\Cache;
use think\swoole\Sandbox;
use Throwable;

class OrderMaintenanceCron implements CronTaskInterface
{
    private const LOCK_KEY = 'cron:order-maintenance:dispatch';
    private const LOCK_TTL = 55;

    public function __construct(
        private readonly Sandbox $sandbox,
    ) {
    }

    public function register(): void
    {
        Timer::tick(60000, function (): void {
            $this->runInSandbox(function (): void {
                $this->dispatchJobs();
            });
        });
    }

    private function dispatchJobs(): void
    {
        if (!$this->acquireLock()) {
            return;
        }

        JobQueue::push(CloseExpiredOrdersJob::class, ['limit' => 500]);
        JobQueue::push(AutoReceiveOrdersJob::class, ['limit' => 500]);
    }

    private function runInSandbox(callable $callback): void
    {
        $this->sandbox->run(function () use ($callback): void {
            $callback();
        });
    }

    private function acquireLock(): bool
    {
        try {
            $handler = Cache::handler();
            if (is_object($handler) && method_exists($handler, 'setnx') && method_exists($handler, 'expire')) {
                $acquired = (bool) $handler->setnx(self::LOCK_KEY, 1);
                if (!$acquired) {
                    return false;
                }
                $handler->expire(self::LOCK_KEY, self::LOCK_TTL);
                return true;
            }
        } catch (Throwable) {
            return true;
        }

        if (Cache::has(self::LOCK_KEY)) {
            return false;
        }
        Cache::set(self::LOCK_KEY, 1, self::LOCK_TTL);
        return true;
    }
}
