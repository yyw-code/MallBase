<?php

declare(strict_types=1);

namespace app\service\install;

interface AgentRuntimeLeaseReader
{
    public function isServeLeaseAlive(int $now): bool;
}
