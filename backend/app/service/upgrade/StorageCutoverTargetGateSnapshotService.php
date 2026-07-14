<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

/**
 * Produces the maintenance-fence half of target-only storage verification.
 * Image and volume evidence remain the responsibility of the isolated helper.
 */
final readonly class StorageCutoverTargetGateSnapshotService
{
    public function __construct(private UpgradeGateRepository $gate)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(string $jobId): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new RuntimeException('STORAGE_CUTOVER_TARGET_GATE_INVALID');
        }
        $snapshot = $this->gate->snapshot();
        if ($snapshot->jobId !== $jobId || $snapshot->state !== UpgradeState::AwaitingDeployment
            || $snapshot->uncertain || $snapshot->platformSyncPending || $snapshot->failureCode !== null) {
            throw new RuntimeException('STORAGE_CUTOVER_TARGET_GATE_INVALID');
        }

        return [
            'schema_version' => 1,
            'purpose' => 'storage_cutover_php_target_snapshot',
            'job_id' => $jobId,
            'gate_state' => $snapshot->state->value,
            'gate_revision' => $snapshot->revision,
            'required_runtime' => [
                'app_version' => $snapshot->requiredRuntimeVersion,
                'deployment_id' => $snapshot->requiredDeploymentId,
                'storage_layout_version' => $snapshot->requiredStorageLayoutVersion,
                'layout_generation' => $snapshot->requiredStorageLayoutGeneration,
            ],
            'maintenance_fenced' => true,
        ];
    }
}
