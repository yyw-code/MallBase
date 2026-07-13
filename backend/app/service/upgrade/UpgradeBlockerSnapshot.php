<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class UpgradeBlockerSnapshot
{
    /** @param list<string> $missingWorkerAcks
     *  @param list<array{connection:string,queue:string,job_id:string}> $allowedDeferredJobs
     */
    public function __construct(
        public UpgradeState $state,
        public int $gateRevision,
        public UpgradeActivitySnapshot $activity,
        public UpgradeQueueInventory $queues,
        public array $missingWorkerAcks,
        public array $allowedDeferredJobs,
        public bool $safe,
    ) {
        if ($this->gateRevision < 0 || !array_is_list($this->missingWorkerAcks)
            || !array_is_list($this->allowedDeferredJobs)) {
            throw new InvalidArgumentException('UPGRADE_BLOCKER_SNAPSHOT_INVALID');
        }
        foreach ($this->missingWorkerAcks as $workerId) {
            if (!is_string($workerId) || preg_match('~^[0-9A-Za-z_.:/-]{1,255}$~D', $workerId) !== 1) {
                throw new InvalidArgumentException('UPGRADE_BLOCKER_SNAPSHOT_INVALID');
            }
        }
        new UpgradeQueueInventory([], [], $this->allowedDeferredJobs);
    }
}
