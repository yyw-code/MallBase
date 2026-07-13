<?php

declare(strict_types=1);

namespace app\cron;

use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeRuntimeContext;
use mall_base\log\Logger;
use Throwable;

class CronManager
{
    /**
     * 只允许一个 Worker 初始化
     */
    protected int $onlyWorkerId;

    public function __construct(
        private readonly ?UpgradeActivityTracker $upgradeActivity = null,
        private readonly ?UpgradeRuntimeContext $upgradeRuntime = null,
    )
    {
        $this->onlyWorkerId = $this->configuredOnlyWorkerId();
    }

    public function boot(int $workerId, callable $runInSandbox): void
    {
        // Worker 限制
        if ($workerId !== $this->onlyWorkerId) {
            return;
        }

        if (!$this->isInstalled()) {
            $this->logger()?->withData(['worker_id' => $workerId])
                ->info('Cron skipped before install');

            return;
        }

        // 是否启用
        if (!$this->cronEnabled()) {
            $this->logger()?->withData(['worker_id' => $workerId])
                ->info('Cron disabled by env');

            return;
        }

        $log = $this->logger()?->withData([
                'worker_id' => $workerId,
            ]);

        $log?->success('CronManager boot');

        foreach ($this->tasks() as $taskDefinition) {
            $taskClass = is_string($taskDefinition) ? $taskDefinition : get_debug_type($taskDefinition);
            try {
                /** @var CronTaskInterface $task */
                $task = $this->resolveTask($taskDefinition);
                $task->register($this->guardedSandbox($runInSandbox, $taskClass));

                $log?->withData([
                    'task' => $taskClass,
                ])->success('Task registered');
            } catch (Throwable $e) {
                $log?->withData([
                    'task' => $taskClass,
                ])->exception($e, 'Task register failed');
            }
        }
    }

    private function guardedSandbox(callable $runInSandbox, string $taskClass): callable
    {
        if (!$this->upgradeEnabled()) {
            return $runInSandbox;
        }

        return function (callable $callback) use ($runInSandbox, $taskClass): void {
            if ($this->upgradeActivity === null || $this->upgradeRuntime === null) {
                return;
            }
            try {
                $lease = $this->upgradeActivity->tryBeginCron(
                    $taskClass . ':' . bin2hex(random_bytes(8)),
                    $this->upgradeRuntime->owner('cron'),
                );
            } catch (Throwable $exception) {
                $this->logger()?->withData(['task' => $taskClass])
                    ->exception($exception, 'Cron upgrade gate unavailable');

                return;
            }
            if ($lease === null) {
                return;
            }
            try {
                $runInSandbox($callback);
            } finally {
                $lease->release();
            }
        };
    }

    protected function configuredOnlyWorkerId(): int
    {
        return (int) config('cron.only_worker_id', 0);
    }

    protected function logger(): ?Logger
    {
        return Logger::instance('Cron', static::class);
    }

    protected function cronEnabled(): bool
    {
        return (bool) config('cron.enable');
    }

    protected function upgradeEnabled(): bool
    {
        return (bool) config('upgrade.enabled', false);
    }

    /** @return array<int,mixed> */
    protected function tasks(): array
    {
        return (array) config('cron.tasks', []);
    }

    protected function resolveTask(mixed $task): CronTaskInterface
    {
        if ($task instanceof CronTaskInterface) {
            return $task;
        }
        if (!is_string($task) || $task === '') {
            throw new \InvalidArgumentException('CRON_TASK_INVALID');
        }
        $resolved = app()->make($task);
        if (!$resolved instanceof CronTaskInterface) {
            throw new \InvalidArgumentException('CRON_TASK_INVALID');
        }

        return $resolved;
    }

    protected function isInstalled(): bool
    {
        return is_file(runtime_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock');
    }
}
