<?php

declare(strict_types=1);

namespace app\service\upgrade;

final readonly class RegistryUpgradeRuntimeOwnerLiveness implements UpgradeRuntimeOwnerLiveness
{
    public function __construct(private UpgradeRuntimeRecordLookup $records)
    {
    }

    public function canRetire(UpgradeRuntimeInstance $owner): bool
    {
        $record = $this->records->findByOwnerKey($owner->key());

        return is_array($record) && ($record['state'] ?? null) === 'retired';
    }
}
