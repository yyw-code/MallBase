<?php

declare(strict_types=1);

namespace app\queue;

use app\service\upgrade\SimpleUpgradeActivityLease;
use app\service\upgrade\SimpleUpgradeGate;
use think\Cache;
use think\Event;
use think\exception\Handle;
use think\Queue;
use think\queue\Worker;
use Throwable;

/**
 * Queue 进程的升级排空边界。
 */
class UpgradeAwareWorker extends Worker
{
    /** @var array<int,SimpleUpgradeActivityLease> */
    private array $queueLeases = [];

    public function __construct(
        Queue $queue,
        Event $event,
        Handle $handle,
        ?Cache $cache = null,
        private readonly ?SimpleUpgradeGate $simpleGate = null,
    ) {
        parent::__construct($queue, $event, $handle, $cache);
    }

    protected function getNextJob($connector, $queue)
    {
        if ($this->simpleGate === null) {
            return parent::getNextJob($connector, $queue);
        }

        try {
            $lease = $this->simpleGate->tryEnter();
            if ($lease === null) {
                return null;
            }
            try {
                $job = parent::getNextJob($connector, (string) $queue);
                if ($job === null) {
                    $lease->release();

                    return null;
                }
                $this->queueLeases[spl_object_id($job)] = $lease;

                return $job;
            } catch (Throwable $exception) {
                $lease->release();
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->handle->report($exception);

            return null;
        }
    }

    public function process($connection, $job, $maxTries = 0, $delay = 0)
    {
        if ($this->simpleGate === null) {
            parent::process($connection, $job, $maxTries, $delay);

            return;
        }

        $objectId = spl_object_id($job);
        $lease = $this->queueLeases[$objectId] ?? null;
        if ($lease === null) {
            try {
                $lease = $this->simpleGate->tryEnter();
            } catch (Throwable $exception) {
                $this->handle->report($exception);

                return;
            }
            if ($lease === null) {
                return;
            }
        }
        try {
            parent::process($connection, $job, $maxTries, $delay);
        } finally {
            unset($this->queueLeases[$objectId]);
            $lease->release();
        }
    }
}
