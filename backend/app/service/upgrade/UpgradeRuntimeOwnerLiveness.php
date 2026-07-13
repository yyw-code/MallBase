<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeOwnerLiveness
{
    public function canRetire(UpgradeRuntimeInstance $owner): bool;
}
