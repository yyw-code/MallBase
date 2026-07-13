<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Throwable;

final readonly class FileUpgradeDrainCheckpointRepository implements UpgradeDrainCheckpointRepository
{
    public function __construct(private UpgradeSharedFileStore $files)
    {
    }

    public function recordDrainStarted(string $jobId, int $gateRevision, int $startedAt): void
    {
        if ($gateRevision < 1 || $startedAt < 0 || $startedAt > 4_102_444_800) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
        $this->mutate($jobId, static function (array $document) use ($gateRevision, $startedAt): array {
            if ($document['draining_gate_revision'] !== null
                && $document['draining_gate_revision'] !== $gateRevision) {
                throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_CONFLICT');
            }
            if ($document['draining_gate_revision'] === $gateRevision) {
                return $document;
            }
            $document['draining_gate_revision'] = $gateRevision;
            $document['draining_started_at'] = $startedAt;

            return $document;
        });
    }

    public function recordDeferredJobs(string $jobId, int $gateRevision, array $jobs): void
    {
        if ($gateRevision < 1) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
        $jobs = $this->normalizeJobs($jobs);
        $this->mutate($jobId, static function (array $document) use ($gateRevision, $jobs): array {
            if ($document['deferred_gate_revision'] !== null
                && ($document['deferred_gate_revision'] > $gateRevision
                    || ($document['deferred_gate_revision'] === $gateRevision
                        && $document['deferred_jobs'] !== $jobs))) {
                throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_CONFLICT');
            }
            $document['deferred_gate_revision'] = $gateRevision;
            $document['deferred_jobs'] = $jobs;

            return $document;
        });
    }

    public function deferredJobs(string $jobId): array
    {
        try {
            $document = $this->files->readDrainCheckpoint($jobId);
            if ($document === null) {
                return [];
            }
            $decoded = $this->decode($jobId, $document);

            return $decoded['deferred_jobs'];
        } catch (Throwable $exception) {
            if ($exception instanceof UpgradeStateConflict) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutation */
    private function mutate(string $jobId, callable $mutation): void
    {
        try {
            $this->files->withDrainCheckpointLock(function () use ($jobId, $mutation): void {
                $current = $this->files->readDrainCheckpoint($jobId);
                $document = $current === null ? $this->empty($jobId) : $this->decode($jobId, $current);
                $next = $mutation($document);
                $this->files->writeDrainCheckpoint($jobId, (object) $next);
            });
        } catch (Throwable $exception) {
            if ($exception instanceof UpgradeStateConflict) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_WRITE_FAILED');
        }
    }

    /** @return array<string,mixed> */
    private function empty(string $jobId): array
    {
        $this->assertJobId($jobId);

        return [
            'schema_version' => 1,
            'job_id' => $jobId,
            'draining_gate_revision' => null,
            'draining_started_at' => null,
            'deferred_gate_revision' => null,
            'deferred_jobs' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function decode(string $jobId, object $document): array
    {
        $values = get_object_vars($document);
        if (array_keys($values) !== [
            'schema_version', 'job_id', 'draining_gate_revision', 'draining_started_at',
            'deferred_gate_revision', 'deferred_jobs',
        ] || $values['schema_version'] !== 1 || $values['job_id'] !== $jobId
            || (!is_null($values['draining_gate_revision']) && !is_int($values['draining_gate_revision']))
            || (!is_null($values['draining_started_at']) && !is_int($values['draining_started_at']))
            || (!is_null($values['deferred_gate_revision']) && !is_int($values['deferred_gate_revision']))
            || !is_array($values['deferred_jobs']) || !array_is_list($values['deferred_jobs'])
            || ($values['draining_gate_revision'] === null) !== ($values['draining_started_at'] === null)) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
        $this->assertJobId($jobId);
        if ($values['draining_gate_revision'] !== null && ($values['draining_gate_revision'] < 1
                || $values['draining_started_at'] < 0 || $values['draining_started_at'] > 4_102_444_800)) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
        if ($values['deferred_gate_revision'] !== null && $values['deferred_gate_revision'] < 1) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
        $values['deferred_jobs'] = $this->normalizeJobs($values['deferred_jobs']);

        return $values;
    }

    /** @param list<array{connection:string,queue:string,job_id:string}> $jobs
     *  @return list<array{connection:string,queue:string,job_id:string}>
     */
    private function normalizeJobs(array $jobs): array
    {
        new UpgradeQueueInventory([], [], $jobs);
        $byKey = [];
        foreach ($jobs as $job) {
            $byKey[$job['connection'] . "\0" . $job['queue'] . "\0" . $job['job_id']] = $job;
        }
        ksort($byKey, SORT_STRING);

        return array_values($byKey);
    }

    private function assertJobId(string $jobId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_DRAIN_CHECKPOINT_INVALID');
        }
    }
}
