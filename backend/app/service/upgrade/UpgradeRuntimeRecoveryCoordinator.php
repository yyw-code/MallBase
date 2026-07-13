<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Throwable;

final readonly class UpgradeRuntimeRecoveryCoordinator
{
    public function __construct(
        private UpgradeGateRepository $gate,
        private UpgradeRuntimeRegistry $runtimes,
        private UpgradeActivityLedgerBackend $ledger,
        private RedisServerIncarnation $incarnation,
        private UpgradeRuntimeDeploymentInventory $deployment,
        private UpgradeRuntimeRetirementGuard $retirement,
        private ?UpgradeRuntimeRetirementEvidenceStore $evidence = null,
        private ?UpgradeRuntimeRecordLookup $records = null,
        private ?UpgradeActivityTracker $activity = null,
        private ?QueueInspector $queues = null,
        private ?UpgradeRuntimeOwnerLiveness $owners = null,
    ) {
    }

    public function beginReplacementLedger(): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        if (!$snapshot->uncertain || $snapshot->state !== UpgradeState::Normal) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
        }

        $owners = $this->ownerKeys($this->runtimes->active());
        if ($owners !== []) {
            $snapshot = $this->recordAllOwners($snapshot, $owners);
        }
        if ($snapshot->taintedBootsOverflow) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_MANUAL_RETIREMENT_REQUIRED');
        }

        $runId = $this->incarnation->current();
        if ($snapshot->replacementBarrierRevision === null) {
            $snapshot = $this->gate->beginActivityRecovery($snapshot->revision, $runId);
        } elseif (!hash_equals($snapshot->redisIncarnation, $runId)) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_CHANGED');
        }

        $this->ledger->initialize($snapshot->activityGeneration, $snapshot->redisIncarnation);
        if ($this->ledger->snapshot($snapshot->activityGeneration, $snapshot->redisIncarnation) !== []) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_LEDGER_NOT_EMPTY');
        }

        return $snapshot;
    }

    public function retireEligibleTaintedOwners(int $now): UpgradeGateSnapshot
    {
        if ($now < 0 || $now > 4_102_444_800) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
        }
        $snapshot = $this->gate->snapshot();
        if (!$snapshot->uncertain || $snapshot->replacementBarrierRevision === null) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
        }
        $records = [];
        foreach ($this->runtimes->active() as $record) {
            $runtime = $this->runtimeFromRecord($record);
            $records[$runtime->key()] = [$runtime, $record];
        }

        $pendingOwners = [];
        if ($this->evidence !== null) {
            foreach ($this->evidence->pending() as $pending) {
                $runtime = $this->runtimeFromRecord($pending['runtime_record']);
                if ($runtime->key() !== $pending['owner_key']) {
                    throw new UpgradeStateConflict('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
                }
                $records[$runtime->key()] = [$runtime, $pending['runtime_record']];
                $pendingOwners[$runtime->key()] = true;
            }
        }

        $ownersToProcess = array_values(array_unique([...$snapshot->taintedBoots, ...array_keys($pendingOwners)]));
        sort($ownersToProcess, SORT_STRING);
        foreach ($ownersToProcess as $ownerKey) {
            if (!isset($records[$ownerKey])) {
                if ($this->records !== null) {
                    $record = $this->records->findByOwnerKey($ownerKey);
                    if ($record !== null) {
                        $runtime = $this->runtimeFromRecord($record);
                        $records[$ownerKey] = [$runtime, $record];
                    }
                }
            }
            if (!isset($records[$ownerKey])) {
                continue;
            }
            [$runtime, $record] = $records[$ownerKey];
            $retired = $this->retirement->retireIfProven(
                $record,
                $now,
                function () use (&$snapshot, $runtime, $record, $now): void {
                    if ($this->evidence === null) {
                        $this->runtimes->retire($runtime, $now);
                        $snapshot = $this->recordRetiredOwner($snapshot, $runtime->key());

                        return;
                    }
                    $this->resumeRetirementSaga($runtime, $record, $snapshot, $now);
                },
            );
            if ($retired) {
                unset($records[$ownerKey]);
            }
        }

        return $snapshot;
    }

    public function clearUncertainty(): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        if (!$snapshot->uncertain || $snapshot->replacementBarrierRevision === null
            || $snapshot->taintedBoots !== [] || $snapshot->taintedBootsOverflow) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
        }
        if ($this->evidence !== null && $this->evidence->pending() !== []) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_RETIREMENT_PENDING');
        }
        $this->reconcileRetiredOwners();
        $runId = $this->incarnation->current();
        if (!hash_equals($snapshot->redisIncarnation, $runId)
            || $this->ledger->snapshot($snapshot->activityGeneration, $runId) !== []) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_LEDGER_NOT_EMPTY');
        }

        return $this->gate->clearActivityUncertainty(
            $snapshot->revision,
            $this->deployment->requiredRoles(),
            $this->runtimes->active(),
        );
    }

    /** @param array<string,mixed> $sourceRecord */
    private function resumeRetirementSaga(
        UpgradeRuntimeInstance $runtime,
        array $sourceRecord,
        UpgradeGateSnapshot &$snapshot,
        int $now,
    ): void {
        $pending = $this->pendingFor($runtime->key());
        if ($pending === null) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_RETIREMENT_EVIDENCE_INVALID');
        }
        $retiredAt = $pending['retired_at'];
        $phase = $pending['state'];
        if ($phase === 'prepared') {
            $this->runtimes->retire($runtime, $retiredAt);
            $this->evidence?->advance($runtime->key(), 'prepared', 'registry_retired', $now);
            $phase = 'registry_retired';
        }
        if ($phase === 'registry_retired') {
            $snapshot = $this->recordRetiredOwner($snapshot, $runtime->key());
            $this->evidence?->advance($runtime->key(), 'registry_retired', 'gate_retired', $now);
            $phase = 'gate_retired';
        }
        if ($phase === 'gate_retired') {
            $this->reconcileRetiredOwners();
            $this->evidence?->advance($runtime->key(), 'gate_retired', 'committed', $now);
        }
        unset($sourceRecord);
    }

    private function recordRetiredOwner(UpgradeGateSnapshot $snapshot, string $ownerKey): UpgradeGateSnapshot
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if (!in_array($ownerKey, $snapshot->taintedBoots, true)) {
                return $snapshot;
            }
            try {
                return $this->gate->recordRetiredTaintedOwner($snapshot->revision, $ownerKey);
            } catch (UpgradeStateConflict) {
                $snapshot = $this->gate->snapshot();
            }
        }

        throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_RACE');
    }

    /** @return array{owner_key:string,state:string,runtime_record:array<string,mixed>,retired_at:int}|null */
    private function pendingFor(string $ownerKey): ?array
    {
        foreach ($this->evidence?->pending() ?? [] as $pending) {
            if ($pending['owner_key'] === $ownerKey) {
                return $pending;
            }
        }

        return null;
    }

    private function reconcileRetiredOwners(): void
    {
        if ($this->activity === null || $this->queues === null || $this->owners === null) {
            return;
        }
        $this->activity->reconcileQueueLeases($this->queues->inventory(), $this->owners);
        $this->activity->reconcileOrphanActivityLeases($this->owners);
    }

    /** @param list<string> $owners */
    private function recordAllOwners(UpgradeGateSnapshot $snapshot, array $owners): UpgradeGateSnapshot
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                return $this->gate->recordActivityUncertainty($snapshot->revision, $owners);
            } catch (UpgradeStateConflict) {
                $snapshot = $this->gate->snapshot();
            }
        }

        throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_RACE');
    }

    /** @param list<array<string,mixed>> $records @return list<string> */
    private function ownerKeys(array $records): array
    {
        $owners = [];
        foreach ($records as $record) {
            $owners[] = $this->runtimeFromRecord($record)->key();
        }
        $owners = array_values(array_unique($owners));
        sort($owners, SORT_STRING);

        return $owners;
    }

    /** @param array<string,mixed> $record */
    private function runtimeFromRecord(array $record): UpgradeRuntimeInstance
    {
        try {
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
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_RECORD_INVALID');
        }
    }
}
