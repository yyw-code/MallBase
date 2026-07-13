<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/**
 * 工作进程只持有本进程的 lifetime SH lock；独立心跳进程不持锁。
 */
final class CoordinatedUpgradeRuntimeLifecycle implements UpgradeRuntimeLifecycle
{
    /** @var array<string,UpgradeRuntimeOwnerLock> */
    private array $ownerLocks = [];

    /** @param list<string> $queueNames */
    public function __construct(
        private readonly UpgradeRuntimeContext $runtime,
        private readonly UpgradeRuntimeRegistry $registry,
        private readonly ImmutableUpgradeRuntimeLockPool $lockPool,
        private readonly UpgradeRuntimeRegistrationCoordinator $registration,
        private readonly UpgradeRuntimeHeartbeatManager $heartbeats,
        private readonly array $queueNames,
    ) {
        if (!array_is_list($this->queueNames) || count($this->queueNames) > 100) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_LIFECYCLE_CONFIG_INVALID');
        }
        foreach ($this->queueNames as $queue) {
            if (!is_string($queue) || preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $queue) !== 1) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_LIFECYCLE_CONFIG_INVALID');
            }
        }
    }

    public function registerWorker(string $serverName, int $workerId, bool $cronEnabled): void
    {
        if ($workerId < 0 || strlen($serverName) > 255) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_WORKER_INVALID');
        }

        $roles = [];
        if (str_starts_with($serverName, 'http server')) {
            $roles[] = ['http', [], false];
            if ($cronEnabled) {
                $roles[] = ['cron', [], true];
            }
        } elseif ($serverName === 'queue'
            || preg_match('/^queue \[[0-9A-Za-z_.:\/-]{1,255}\](?: #[0-9]+)?$/D', $serverName) === 1) {
            if ($this->queueNames === []) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_LIFECYCLE_CONFIG_INVALID');
            }
            $roles[] = ['queue', $this->queueNames, false];
        } else {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_WORKER_INVALID');
        }

        $acquired = [];
        try {
            foreach ($roles as [$role, $queues, $roleCronEnabled]) {
                if (isset($this->ownerLocks[$role])) {
                    $this->ownerLocks[$role]->verifyStillCanonical();
                    continue;
                }
                $owner = $this->runtime->owner($role);
                $lock = $this->lockPool->acquireForRegistration(
                    $owner,
                    $this->registry,
                    function (string $slotId) use ($owner, $queues, $roleCronEnabled): void {
                        $registration = $this->registration->register(
                            $owner,
                            $queues,
                            $roleCronEnabled,
                            $slotId,
                        );
                        if (!$registration->mayAcceptBusinessWork) {
                            throw new UpgradeStateConflict('UPGRADE_RUNTIME_IDENTITY_FENCED');
                        }
                        $record = $this->heartbeats->tick($owner, $queues, $roleCronEnabled);
                        if (($record['identity_fenced'] ?? true) !== false) {
                            throw new UpgradeStateConflict('UPGRADE_RUNTIME_IDENTITY_FENCED');
                        }
                    },
                );
                $this->ownerLocks[$role] = $lock;
                $acquired[] = $role;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($acquired) as $role) {
                $this->ownerLocks[$role]->release();
                unset($this->ownerLocks[$role]);
            }
            if ($exception instanceof UpgradeStateConflict || $exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_REGISTRATION_FAILED');
        }
    }

    public function heartbeat(): void
    {
        $matched = 0;
        $seen = [];
        foreach ($this->registry->active() as $record) {
            $role = $record['role'] ?? null;
            if (!is_string($role) || isset($seen[$role]) || !in_array($role, ['http', 'queue', 'cron'], true)) {
                continue;
            }
            $owner = $this->runtime->owner($role);
            if (($record['runtime_instance_id'] ?? null) !== $owner->runtimeInstanceId
                || ($record['boot_id'] ?? null) !== $owner->bootId) {
                continue;
            }
            if ($this->runtimeRecordIdentity($record) !== $owner->toArray()) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_RECORD_INVALID');
            }
            $queues = $record['queues'] ?? null;
            $cronEnabled = $record['cron_enabled'] ?? null;
            if (!is_array($queues) || !array_is_list($queues) || !is_bool($cronEnabled)) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_RECORD_INVALID');
            }
            $this->heartbeats->tick($owner, $queues, $cronEnabled);
            $seen[$role] = true;
            $matched++;
        }
        if ($matched === 0) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_NOT_REGISTERED');
        }
    }

    public function stopWorker(): void
    {
        foreach (array_reverse($this->ownerLocks, true) as $lock) {
            $lock->release();
        }
        $this->ownerLocks = [];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function runtimeRecordIdentity(array $record): array
    {
        return [
            'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
            'boot_id' => $record['boot_id'] ?? null,
            'role' => $record['role'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'deployment_id' => $record['deployment_id'] ?? null,
            'storage_layout_version' => $record['storage_layout_version'] ?? null,
            'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
            'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
        ];
    }
}
