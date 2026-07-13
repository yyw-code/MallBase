<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;

final class UpgradeRuntimeHeartbeatManager
{
    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly UpgradeRuntimeRegistry $registry,
        private readonly UpgradeGateRepository $gate,
        private readonly ?UpgradeRuntimeHeartbeatStore $heartbeats = null,
        ?Closure $clock = null,
        private readonly int $ttl = 15,
    ) {
        if ($this->ttl < 1 || $this->ttl > 60) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_CONFIG_INVALID');
        }
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @param list<string> $queues @return array<string,mixed> */
    public function tick(UpgradeRuntimeInstance $instance, array $queues, bool $cronEnabled): array
    {
        $gate = $this->gate->snapshot();
        $identityFenced = !$instance->matchesGateSnapshot($gate);
        $pausedAck = !$identityFenced && $gate->state->pausesQueuePop() ? $gate->revision : null;

        $record = $this->registry->heartbeat(
            $instance,
            $queues,
            $cronEnabled,
            $gate,
            $identityFenced,
            $pausedAck,
        );
        if ($this->heartbeats !== null) {
            $clock = $this->clock;
            $this->heartbeats->heartbeat($gate, $record, $clock(), $this->ttl);
        }

        return $record;
    }
}
