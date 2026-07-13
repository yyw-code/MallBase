<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;

final readonly class VerifiedUpgradeRuntimeRetirementGuard implements UpgradeRuntimeRetirementGuard
{
    public function __construct(
        private UpgradeRuntimeRegistry $runtimes,
        private UpgradeRuntimeHeartbeatStore $heartbeats,
        private UpgradeRuntimeRetirementEvidenceStore $evidence,
        private UpgradeRuntimeLockPool $locks,
        private RedisServerIncarnation $incarnation,
        private int $windowSeconds = 15,
        private ?UpgradeRuntimeRecordLookup $records = null,
    ) {
        if ($this->windowSeconds < 1 || $this->windowSeconds > 300) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_RETIREMENT_CONFIG_INVALID');
        }
    }

    public function retireIfProven(array $runtimeRecord, int $now, Closure $afterDurableTombstone): bool
    {
        $runtime = $this->runtimeFromRecord($runtimeRecord);
        $current = $this->currentRecord($runtime);
        if ($current === null || !in_array($current['state'] ?? null, ['active', 'retired'], true)) {
            return false;
        }
        $runId = $this->incarnation->current();
        $redis = $this->heartbeats->find($runtime->key(), $runId);
        if (!$this->evidence->observe($current, $redis, $now, $this->windowSeconds)) {
            return false;
        }

        $confirmed = false;
        $locked = $this->locks->tryRetire($current, function () use (
            $runtime,
            $now,
            $runId,
            $afterDurableTombstone,
            &$confirmed,
        ): void {
            $rechecked = $this->currentRecord($runtime);
            if ($rechecked === null || !in_array($rechecked['state'] ?? null, ['active', 'retired'], true)) {
                return;
            }
            $redis = $this->heartbeats->find($runtime->key(), $runId);
            if (!$this->evidence->prepareIfUnchanged($rechecked, $redis, $now, $this->windowSeconds)) {
                return;
            }
            $afterDurableTombstone();
            $confirmed = true;
        });

        return $locked && $confirmed;
    }

    /** @return array<string,mixed>|null */
    private function currentRecord(UpgradeRuntimeInstance $runtime): ?array
    {
        if ($this->records !== null) {
            return $this->records->findByOwnerKey($runtime->key());
        }
        foreach ($this->runtimes->active() as $record) {
            $candidate = $this->runtimeFromRecord($record);
            if ($candidate->key() === $runtime->key()) {
                return $record;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $record */
    private function runtimeFromRecord(array $record): UpgradeRuntimeInstance
    {
        return UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
            'boot_id' => $record['boot_id'] ?? null,
            'role' => $record['role'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'deployment_id' => $record['deployment_id'] ?? null,
            'storage_layout_version' => $record['storage_layout_version'] ?? null,
            'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
            'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
        ]);
    }
}
