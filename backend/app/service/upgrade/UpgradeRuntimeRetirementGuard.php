<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;

interface UpgradeRuntimeRetirementGuard
{
    /**
     * @param array<string,mixed> $runtimeRecord
     * @param Closure():void $afterDurableTombstone
     */
    public function retireIfProven(array $runtimeRecord, int $now, Closure $afterDurableTombstone): bool;
}
