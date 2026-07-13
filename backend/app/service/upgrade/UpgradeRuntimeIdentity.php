<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class UpgradeRuntimeIdentity
{
    public function __construct(
        public string $version,
        public string $deploymentId,
        public int $storageLayoutVersion,
        public int $storageLayoutGeneration,
    ) {
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $this->version) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->deploymentId) !== 1
            || $this->storageLayoutVersion < 0 || $this->storageLayoutVersion > 1_000_000
            || $this->storageLayoutGeneration < 1 || $this->storageLayoutGeneration > PHP_INT_MAX) {
            throw new InvalidArgumentException('UPGRADE_RUNTIME_IDENTITY_INVALID');
        }
    }

    /** @return array{version:string,deployment_id:string,storage_layout_version:int,storage_layout_generation:int} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'deployment_id' => $this->deploymentId,
            'storage_layout_version' => $this->storageLayoutVersion,
            'storage_layout_generation' => $this->storageLayoutGeneration,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
