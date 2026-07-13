<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class UpgradeRuntimeInstance
{
    public function __construct(
        public string $runtimeInstanceId,
        public string $bootId,
        public string $role,
        public UpgradeRuntimeIdentity $identity,
        public int $observedDeploymentEpoch,
    ) {
        if (!$this->validUuid($this->runtimeInstanceId) || !$this->validUuid($this->bootId)
            || !in_array($this->role, ['http', 'queue', 'cron'], true)
            || $this->observedDeploymentEpoch < 1) {
            throw new InvalidArgumentException('UPGRADE_RUNTIME_INSTANCE_INVALID');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'runtime_instance_id' => $this->runtimeInstanceId,
            'boot_id' => $this->bootId,
            'role' => $this->role,
            'app_version' => $this->identity->version,
            'deployment_id' => $this->identity->deploymentId,
            'storage_layout_version' => $this->identity->storageLayoutVersion,
            'storage_layout_generation' => $this->identity->storageLayoutGeneration,
            'observed_deployment_epoch' => $this->observedDeploymentEpoch,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $expected = [
            'runtime_instance_id', 'boot_id', 'role', 'app_version', 'deployment_id',
            'storage_layout_version', 'storage_layout_generation', 'observed_deployment_epoch',
        ];
        if (array_keys($value) !== $expected || !is_string($value['runtime_instance_id'])
            || !is_string($value['boot_id']) || !is_string($value['role']) || !is_string($value['app_version'])
            || !is_string($value['deployment_id']) || !is_int($value['storage_layout_version'])
            || !is_int($value['storage_layout_generation']) || !is_int($value['observed_deployment_epoch'])) {
            throw new InvalidArgumentException('UPGRADE_RUNTIME_INSTANCE_INVALID');
        }

        return new self(
            $value['runtime_instance_id'],
            $value['boot_id'],
            $value['role'],
            new UpgradeRuntimeIdentity(
                $value['app_version'],
                $value['deployment_id'],
                $value['storage_layout_version'],
                $value['storage_layout_generation'],
            ),
            $value['observed_deployment_epoch'],
        );
    }

    public function key(): string
    {
        return $this->runtimeInstanceId . ':' . $this->bootId . ':' . $this->role;
    }

    public function matchesGateSnapshot(UpgradeGateSnapshot $gate): bool
    {
        return $gate->acceptsRuntime($this->identity)
            && $this->observedDeploymentEpoch === $gate->deploymentEpoch;
    }

    public function isCleanReplacementFor(
        UpgradeGateSnapshot $gate,
        int $bootRegistrationRevision,
        int $activityGeneration,
        string $redisIncarnation,
    ): bool {
        return $gate->replacementBarrierRevision !== null
            && $bootRegistrationRevision >= $gate->replacementBarrierRevision
            && $activityGeneration === $gate->activityGeneration
            && hash_equals($redisIncarnation, $gate->redisIncarnation)
            && $this->matchesGateSnapshot($gate);
    }

    private function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
