<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeRecordLookup
{
    /** @return array<string,mixed>|null */
    public function findByOwnerKey(string $ownerKey): ?array;
}
