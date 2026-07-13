<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeRegistry
{
    /** @param list<string> $queues @return array<string,mixed> */
    public function register(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        string $slotId,
    ): array;

    /** @param list<string> $queues @return array<string,mixed> */
    public function heartbeat(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        bool $identityFenced,
        ?int $pausedAckRevision,
    ): array;

    /** @return list<array<string,mixed>> */
    public function active(): array;

    /** @return array<string,mixed> */
    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array;
}
