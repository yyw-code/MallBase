<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class UpgradeQueueInventory
{
    /** @param list<array{connection:string,queue:string,job_id:string}> $ready
     *  @param list<array{connection:string,queue:string,job_id:string}> $reserved
     *  @param list<array{connection:string,queue:string,job_id:string}> $delayed
     *  @param list<array{connection:string,reason:string}> $unsupported
     */
    public function __construct(
        public array $ready,
        public array $reserved,
        public array $delayed,
        public array $unsupported = [],
    )
    {
        foreach ([$this->ready, $this->reserved, $this->delayed] as $entries) {
            if (!array_is_list($entries) || count($entries) > 1_000_000) {
                throw new InvalidArgumentException('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || array_keys($entry) !== ['connection', 'queue', 'job_id']
                    || !$this->validName($entry['connection']) || !$this->validName($entry['queue'])
                    || !$this->validName($entry['job_id'])) {
                    throw new InvalidArgumentException('UPGRADE_QUEUE_INVENTORY_INVALID');
                }
            }
        }
        if (!array_is_list($this->unsupported) || count($this->unsupported) > 100) {
            throw new InvalidArgumentException('UPGRADE_QUEUE_INVENTORY_INVALID');
        }
        foreach ($this->unsupported as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['connection', 'reason']
                || !$this->validName($entry['connection'])
                || !is_string($entry['reason'])
                || preg_match('/^[A-Z0-9_]{1,64}$/D', $entry['reason']) !== 1) {
                throw new InvalidArgumentException('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
        }
    }

    public function contains(string $connection, string $queue, string $jobId): bool
    {
        foreach ([$this->ready, $this->reserved, $this->delayed] as $entries) {
            foreach ($entries as $entry) {
                if ($entry['connection'] === $connection && $entry['queue'] === $queue && $entry['job_id'] === $jobId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function validName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $value) === 1;
    }
}
