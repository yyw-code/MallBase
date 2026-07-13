<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeMountedStorageIdentityReader
{
    public function read(
        string $appVersion,
        string $deploymentId,
        string $releaseInventorySha256,
        int $storageLayoutVersion,
        int $storageLayoutGeneration,
    ): UpgradeMountedStorageIdentity;
}
