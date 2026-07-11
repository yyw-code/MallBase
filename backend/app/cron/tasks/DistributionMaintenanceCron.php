<?php

declare(strict_types=1);

namespace app\cron\tasks;

use app\cron\CronTaskInterface;
use app\job\ReleaseDistributionCommissionsJob;
use mall_base\queue\JobQueue;
use Swoole\Timer;
use think\facade\Cache;
use Throwable;

/**
 * 分销域周期维护：释放到期冻结佣金。
 */
class DistributionMaintenanceCron implements CronTaskInterface
{
    private const LOCK_KEY = 'cron:distribution-maintenance:dispatch';
    private const LOCK_TTL = 55;

    public function register(callable $runInSandbox): void
    {
        Timer::tick(60000, function () use ($runInSandbox): void {
            $runInSandbox(function (): void {
                $this->dispatchJobs();
            });
        });
    }

    private function dispatchJobs(): void
    {
        if (!$this->acquireLock()) {
            return;
        }

        JobQueue::push(ReleaseDistributionCommissionsJob::class, ['limit' => 500]);
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
