<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Throwable;

/**
 * 将无法持有 lifetime lock 的工作角色写入唯一持久安全闩。
 */
final readonly class UpgradeRuntimeFailureLatch
{
    public function __construct(
        private UpgradeGateRepository $gate,
        private UpgradeRuntimeContext $runtime,
        private UpgradeRuntimeRegistry $runtimes,
    ) {
    }

    /** @param list<string> $roles */
    public function taintRoles(array $roles): UpgradeGateSnapshot
    {
        if (!array_is_list($roles) || $roles === [] || count($roles) > 3) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_FAILURE_LATCH_INVALID');
        }
        $owners = [];
        foreach (array_values(array_unique($roles)) as $role) {
            if (!is_string($role) || !in_array($role, ['http', 'queue', 'cron'], true)) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_FAILURE_LATCH_INVALID');
            }
            $owners[] = $this->runtime->owner($role)->key();
        }

        return $this->persist($owners);
    }

    public function taintWorker(string $serverName, bool $cronEnabled): UpgradeGateSnapshot
    {
        if (str_starts_with($serverName, 'http server')) {
            return $this->taintRoles($cronEnabled ? ['http', 'cron'] : ['http']);
        }
        if ($serverName === 'queue'
            || preg_match('/^queue \[[0-9A-Za-z_.:\/-]{1,255}\](?: #[0-9]+)?$/D', $serverName) === 1) {
            return $this->taintRoles(['queue']);
        }

        throw new UpgradeStateConflict('UPGRADE_RUNTIME_FAILURE_LATCH_INVALID');
    }

    public function taintActiveOwners(): UpgradeGateSnapshot
    {
        $owners = [];
        foreach ($this->runtimes->active() as $record) {
            try {
                $owners[] = UpgradeRuntimeInstance::fromArray([
                    'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
                    'boot_id' => $record['boot_id'] ?? null,
                    'role' => $record['role'] ?? null,
                    'app_version' => $record['app_version'] ?? null,
                    'deployment_id' => $record['deployment_id'] ?? null,
                    'storage_layout_version' => $record['storage_layout_version'] ?? null,
                    'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
                    'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
                ])->key();
            } catch (Throwable) {
                throw new UpgradeStateConflict('UPGRADE_RUNTIME_FAILURE_LATCH_INVALID');
            }
        }
        if ($owners === []) {
            $owners[] = $this->runtime->owner('http')->key();
        }

        return $this->persist($owners);
    }

    /** @param list<string> $owners */
    private function persist(array $owners): UpgradeGateSnapshot
    {
        $owners = array_values(array_unique($owners));
        sort($owners, SORT_STRING);
        $snapshot = $this->gate->snapshot();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($snapshot->uncertain
                && ($snapshot->taintedBootsOverflow
                    || array_diff($owners, $snapshot->taintedBoots) === [])) {
                return $snapshot;
            }
            try {
                $snapshot = $this->gate->recordActivityUncertainty($snapshot->revision, $owners);
                if ($snapshot->uncertain && ($snapshot->taintedBootsOverflow
                        || array_diff($owners, $snapshot->taintedBoots) === [])) {
                    return $snapshot;
                }
            } catch (Throwable) {
                $snapshot = $this->gate->snapshot();
            }
        }

        throw new UpgradeStateConflict('UPGRADE_RUNTIME_FAILURE_LATCH_UNAVAILABLE');
    }
}
