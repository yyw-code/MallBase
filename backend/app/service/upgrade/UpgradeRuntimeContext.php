<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeContext
{
    public function owner(string $role): UpgradeRuntimeInstance;
}
