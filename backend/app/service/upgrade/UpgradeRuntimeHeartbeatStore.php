<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeHeartbeatStore
{
    /** @param array<string,mixed> $runtimeRecord */
    public function heartbeat(UpgradeGateSnapshot $gate, array $runtimeRecord, int $now, int $ttl): array;

    /** @return array<string,mixed>|null */
    public function find(string $ownerKey, string $expectedServerRunId): ?array;
}
