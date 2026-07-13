<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeDeploymentInventory
{
    /** @return list<string> */
    public function requiredRoles(): array;
}
