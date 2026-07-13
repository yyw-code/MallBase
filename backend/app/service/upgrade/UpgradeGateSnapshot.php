<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;
use stdClass;

final readonly class UpgradeGateSnapshot
{
    /** @param list<string> $taintedBoots */
    public function __construct(
        public UpgradeState $state,
        public int $revision,
        public ?string $jobId,
        public string $requiredRuntimeVersion,
        public string $requiredDeploymentId,
        public int $requiredStorageLayoutVersion,
        public int $requiredStorageLayoutGeneration,
        public int $deploymentEpoch,
        public int $activityGeneration,
        public string $redisIncarnation,
        public bool $uncertain,
        public array $taintedBoots,
        public bool $platformSyncPending,
        public ?string $failureCode,
        public int $updatedAt,
        public ?int $uncertainRevision = null,
        public ?int $replacementBarrierRevision = null,
        public bool $taintedBootsOverflow = false,
    ) {
        $identity = $this->runtimeIdentity();
        unset($identity);
        if ($this->revision < 0 || $this->deploymentEpoch < 1 || $this->activityGeneration < 1
            || preg_match('/^[0-9a-f]{40}$/D', $this->redisIncarnation) !== 1
            || $this->updatedAt < 0 || $this->updatedAt > 4_102_444_800
            || ($this->state === UpgradeState::Normal && $this->jobId !== null)
            || ($this->state !== UpgradeState::Normal && !$this->validJobId($this->jobId))
            || ($this->failureCode !== null && preg_match('/^[A-Z0-9_]{1,64}$/D', $this->failureCode) !== 1)
            || ($this->uncertain && ($this->uncertainRevision === null || $this->uncertainRevision < 0
                || $this->uncertainRevision > $this->revision))
            || (!$this->uncertain && ($this->uncertainRevision !== null
                || $this->replacementBarrierRevision !== null || $this->taintedBootsOverflow))
            || ($this->replacementBarrierRevision !== null && (!$this->uncertain
                || $this->replacementBarrierRevision < (int) $this->uncertainRevision
                || $this->replacementBarrierRevision > $this->revision))
            || count($this->taintedBoots) > 100) {
            throw new InvalidArgumentException('UPGRADE_GATE_SNAPSHOT_INVALID');
        }
        foreach ($this->taintedBoots as $boot) {
            if (!is_string($boot) || preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $boot) !== 1) {
                throw new InvalidArgumentException('UPGRADE_GATE_SNAPSHOT_INVALID');
            }
        }
    }

    public function runtimeIdentity(): UpgradeRuntimeIdentity
    {
        return new UpgradeRuntimeIdentity(
            $this->requiredRuntimeVersion,
            $this->requiredDeploymentId,
            $this->requiredStorageLayoutVersion,
            $this->requiredStorageLayoutGeneration,
        );
    }

    public function acceptsRuntime(UpgradeRuntimeIdentity $identity): bool
    {
        return $this->runtimeIdentity()->equals($identity);
    }

    public function toDocument(): object
    {
        return (object) [
            'schema_version' => 2,
            'state' => $this->state->value,
            'revision' => $this->revision,
            'job_id' => $this->jobId,
            'required_runtime_version' => $this->requiredRuntimeVersion,
            'required_deployment_id' => $this->requiredDeploymentId,
            'required_storage_layout_version' => $this->requiredStorageLayoutVersion,
            'required_storage_layout_generation' => $this->requiredStorageLayoutGeneration,
            'deployment_epoch' => $this->deploymentEpoch,
            'activity_generation' => $this->activityGeneration,
            'redis_incarnation' => $this->redisIncarnation,
            'uncertain' => $this->uncertain,
            'tainted_boots' => $this->taintedBoots,
            'platform_sync_pending' => $this->platformSyncPending,
            'failure_code' => $this->failureCode,
            'updated_at' => $this->updatedAt,
            'uncertain_revision' => $this->uncertainRevision,
            'replacement_barrier_revision' => $this->replacementBarrierRevision,
            'tainted_boots_overflow' => $this->taintedBootsOverflow,
        ];
    }

    public static function fromDocument(object $document): self
    {
        $values = get_object_vars($document);
        $expected = [
            'schema_version', 'state', 'revision', 'job_id', 'required_runtime_version',
            'required_deployment_id', 'required_storage_layout_version', 'required_storage_layout_generation',
            'deployment_epoch', 'activity_generation', 'redis_incarnation', 'uncertain', 'tainted_boots',
            'platform_sync_pending', 'failure_code', 'updated_at',
            'uncertain_revision', 'replacement_barrier_revision', 'tainted_boots_overflow',
        ];
        if (array_keys($values) !== $expected || ($values['schema_version'] ?? null) !== 2
            || !is_string($values['state']) || UpgradeState::tryFrom($values['state']) === null
            || !is_int($values['revision']) || (!is_null($values['job_id']) && !is_string($values['job_id']))
            || !is_string($values['required_runtime_version']) || !is_string($values['required_deployment_id'])
            || !is_int($values['required_storage_layout_version']) || !is_int($values['required_storage_layout_generation'])
            || !is_int($values['deployment_epoch']) || !is_int($values['activity_generation'])
            || !is_string($values['redis_incarnation']) || !is_bool($values['uncertain'])
            || !is_array($values['tainted_boots']) || !array_is_list($values['tainted_boots'])
            || !is_bool($values['platform_sync_pending'])
            || (!is_null($values['failure_code']) && !is_string($values['failure_code']))
            || !is_int($values['updated_at'])
            || (!is_null($values['uncertain_revision']) && !is_int($values['uncertain_revision']))
            || (!is_null($values['replacement_barrier_revision']) && !is_int($values['replacement_barrier_revision']))
            || !is_bool($values['tainted_boots_overflow'])) {
            throw new InvalidArgumentException('UPGRADE_GATE_SNAPSHOT_INVALID');
        }

        return new self(
            UpgradeState::from($values['state']),
            $values['revision'],
            $values['job_id'],
            $values['required_runtime_version'],
            $values['required_deployment_id'],
            $values['required_storage_layout_version'],
            $values['required_storage_layout_generation'],
            $values['deployment_epoch'],
            $values['activity_generation'],
            $values['redis_incarnation'],
            $values['uncertain'],
            $values['tainted_boots'],
            $values['platform_sync_pending'],
            $values['failure_code'],
            $values['updated_at'],
            $values['uncertain_revision'],
            $values['replacement_barrier_revision'],
            $values['tainted_boots_overflow'],
        );
    }

    private function validJobId(?string $jobId): bool
    {
        return is_string($jobId)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) === 1;
    }
}
