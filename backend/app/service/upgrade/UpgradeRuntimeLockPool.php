<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;

interface UpgradeRuntimeLockPool
{
    /** @param array<string,mixed> $runtimeRecord @param Closure():void $retire */
    public function tryRetire(array $runtimeRecord, Closure $retire): bool;
}
