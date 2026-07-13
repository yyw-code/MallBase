<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeActivityLedgerInitializer
{
    public function initializeLedger(): void;
}
