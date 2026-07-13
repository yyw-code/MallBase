<?php

declare(strict_types=1);

namespace app\queue;

use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeLifecycle;
use Throwable;
use think\Cache;
use think\Event;
use think\exception\Handle;
use think\Queue;
use think\queue\Worker;

/**
 * Queue 进程的升级活动边界。
 *
 * 每次 connector pop 前先登记持久 pop intent，拿到 Job 后再绑定同一个
 * execution attempt；因此 reserve 与 Job 处理之间的进程冻结也不会被排空误判。
 */
class UpgradeAwareWorker extends Worker
{
    /** @var array<int,UpgradeActivityLease> */
    private array $queueLeases = [];
    private bool $lifecycleRegistered = false;

    public function __construct(
        Queue $queue,
        Event $event,
        Handle $handle,
        ?Cache $cache = null,
        private readonly ?UpgradeGateRepository $upgradeGate = null,
        private readonly ?UpgradeActivityTracker $upgradeActivity = null,
        private readonly ?UpgradeRuntimeContext $upgradeRuntime = null,
        private readonly ?UpgradeRuntimeLifecycle $upgradeLifecycle = null,
        private readonly ?bool $upgradeEnabled = null,
    ) {
        parent::__construct($queue, $event, $handle, $cache);
    }

    protected function getNextJob($connector, $queue)
    {
        if (!$this->isUpgradeEnabled()) {
            return parent::getNextJob($connector, $queue);
        }
        if (!$this->hasUpgradeDependencies()) {
            return null;
        }

        try {
            $owner = $this->owner();
            if (!$this->prepareLifecycle()) {
                return null;
            }
            $this->upgradeLifecycle?->heartbeat();
            $snapshot = $this->upgradeGate->snapshot();
            if (!$owner->matchesGateSnapshot($snapshot)) {
                return null;
            }

            $queues = $this->queueNames((string) $queue);
            $workerId = $this->workerId($owner);
            $connectorType = $this->connectorType($connector);
            $this->upgradeActivity->heartbeatWorker(
                $workerId,
                $connectorType,
                $queues,
                $owner,
                $this->workerHeartbeatTtl(),
            );
            if ($snapshot->state->pausesQueuePop()) {
                $this->upgradeActivity->ackPaused(
                    $workerId,
                    $owner,
                    $snapshot->revision,
                    $this->workerHeartbeatTtl(),
                );

                return null;
            }

            $attemptId = $this->newAttemptId();
            $popLease = $this->upgradeActivity->beginQueuePop(
                $workerId,
                $connectorType,
                $queues,
                $attemptId,
                $owner,
            );
            if ($popLease === null) {
                return null;
            }
            try {
                $job = parent::getNextJob($connector, (string) $queue);
                if ($job === null) {
                    $popLease->release();

                    return null;
                }
                if ($popLease->untracked) {
                    $this->queueLeases[spl_object_id($job)] = $popLease;

                    return $job;
                }
                $this->queueLeases[spl_object_id($job)] = $this->upgradeActivity->bindQueueJob(
                    $popLease,
                    (string) $job->getConnection(),
                    (string) $job->getQueue(),
                    (string) $job->getJobId(),
                );

                return $job;
            } catch (Throwable $exception) {
                $popLease->release();
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->handle->report($exception);

            return null;
        }
    }

    public function process($connection, $job, $maxTries = 0, $delay = 0)
    {
        if (!$this->isUpgradeEnabled()) {
            parent::process($connection, $job, $maxTries, $delay);

            return;
        }
        if (!$this->hasUpgradeDependencies()) {
            return;
        }

        $objectId = spl_object_id($job);
        $lease = $this->queueLeases[$objectId] ?? null;
        if ($lease === null) {
            $lease = $this->beginDirectProcessLease((string) $connection, $job);
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

    public function stop($status = 0)
    {
        try {
            $this->upgradeLifecycle?->stopWorker();
        } finally {
            parent::stop($status);
        }
    }

    private function beginDirectProcessLease(string $connection, object $job): ?UpgradeActivityLease
    {
        try {
            $owner = $this->owner();
            if (!$this->prepareLifecycle()) {
                return null;
            }
            $this->upgradeLifecycle?->heartbeat();
            $snapshot = $this->upgradeGate->snapshot();
            if (!$owner->matchesGateSnapshot($snapshot)) {
                return null;
            }
            $queue = (string) $job->getQueue();
            $popLease = $this->upgradeActivity->beginQueuePop(
                $this->workerId($owner),
                'direct',
                [$queue],
                $this->newAttemptId(),
                $owner,
            );
            if ($popLease === null) {
                return null;
            }
            try {
                return $this->upgradeActivity->bindQueueJob(
                    $popLease,
                    $connection,
                    $queue,
                    (string) $job->getJobId(),
                );
            } catch (Throwable $exception) {
                $popLease->release();
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->handle->report($exception);

            return null;
        }
    }

    private function isUpgradeEnabled(): bool
    {
        return $this->upgradeEnabled ?? (bool) config('upgrade.enabled', false);
    }

    private function workerHeartbeatTtl(): int
    {
        if (!function_exists('config')) {
            return 15;
        }
        try {
            $ttl = (int) config('upgrade.worker_heartbeat_ttl', 15);

            return $ttl >= 1 && $ttl <= 60 ? $ttl : 15;
        } catch (Throwable) {
            return 15;
        }
    }

    private function hasUpgradeDependencies(): bool
    {
        return $this->upgradeGate !== null && $this->upgradeActivity !== null && $this->upgradeRuntime !== null;
    }

    private function owner(): UpgradeRuntimeInstance
    {
        /** @var UpgradeRuntimeInstance $owner */
        $owner = $this->upgradeRuntime->owner('queue');

        return $owner;
    }

    private function prepareLifecycle(): bool
    {
        if ($this->lifecycleRegistered || $this->upgradeLifecycle === null) {
            return true;
        }
        $this->upgradeLifecycle->registerWorker('queue', getmypid(), false);
        $this->lifecycleRegistered = true;

        return true;
    }

    /** @return list<string> */
    private function queueNames(string $queue): array
    {
        $queues = array_values(array_filter(
            array_map('trim', explode(',', $queue)),
            static fn(string $name): bool => $name !== '',
        ));
        if ($queues === []) {
            throw new \InvalidArgumentException('UPGRADE_QUEUE_NAME_INVALID');
        }

        return $queues;
    }

    private function connectorType(object $connector): string
    {
        return str_replace('\\', '.', get_class($connector));
    }

    private function workerId(UpgradeRuntimeInstance $owner): string
    {
        return 'queue-' . $owner->runtimeInstanceId . '-' . $owner->bootId . '-' . getmypid();
    }

    private function newAttemptId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
