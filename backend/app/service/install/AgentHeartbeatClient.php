<?php

declare(strict_types=1);

namespace app\service\install;

interface AgentHeartbeatClient
{
    /** @param array<string, mixed> $payload */
    public function run(array $payload): AgentHeartbeatResult;
}
