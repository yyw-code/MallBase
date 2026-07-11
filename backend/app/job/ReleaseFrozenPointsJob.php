<?php

declare(strict_types=1);

namespace app\job;

use app\service\user\UserPointsAccountService;
use mall_base\base\BaseJob;
use think\facade\Cache;
use think\queue\Job as QueueJob;
use Throwable;

class ReleaseFrozenPointsJob extends BaseJob
{
    private const LOCK_KEY = 'job:points:release-frozen';
    private const LOCK_TTL = 55;

    private int $limit = 500;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        parent::__construct();
        $this->limit = max(1, min(2000, (int) ($data['limit'] ?? 500)));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fire(QueueJob $job, array $data): void
    {
        if (isset($data['limit'])) {
            $this->limit = max(1, min(2000, (int) $data['limit']));
        }

        try {
            $this->handle();
        } catch (Throwable $e) {
            $this->logger()->jobError($e);
        } finally {
            $job->delete();
        }
    }

    public function handle(): void
    {
        if (!$this->acquireLock()) {
            return;
        }

        app()->make(UserPointsAccountService::class)->releaseDueRewards($this->limit);
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
