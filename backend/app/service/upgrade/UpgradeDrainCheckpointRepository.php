<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeDrainCheckpointRepository
{
    public function recordDrainStarted(string $jobId, int $gateRevision, int $startedAt): void;

    /** @param list<array{connection:string,queue:string,job_id:string}> $jobs */
    public function recordDeferredJobs(string $jobId, int $gateRevision, array $jobs): void;

    /** @return list<array{connection:string,queue:string,job_id:string}> */
    public function deferredJobs(string $jobId): array;
}
