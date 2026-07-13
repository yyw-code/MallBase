<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class UpgradeMountedStorageIdentity
{
    /**
     * @param array<string, array{marker_id:string,marker_sha256:string}> $volumeMarkers
     */
    public function __construct(
        public string $purpose,
        public string $installationStorageNamespace,
        public string $appVersion,
        public string $deploymentId,
        public string $releaseInventorySha256,
        public int $storageLayoutVersion,
        public int $storageLayoutGeneration,
        public string $finalizeReceiptSha256,
        public array $volumeMarkers,
    ) {
        if ($this->purpose !== 'business_boot'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $this->installationStorageNamespace) !== 1
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $this->appVersion) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->deploymentId) !== 1
            || !$this->isHash($this->releaseInventorySha256)
            || $this->storageLayoutVersion < 1 || $this->storageLayoutVersion > 1_000_000
            || $this->storageLayoutGeneration < 1
            || !$this->isHash($this->finalizeReceiptSha256)
            || $this->volumeMarkers === [] || count($this->volumeMarkers) > 32) {
            throw new InvalidArgumentException('UPGRADE_MOUNTED_STORAGE_IDENTITY_INVALID');
        }

        $names = array_keys($this->volumeMarkers);
        $sortedNames = $names;
        sort($sortedNames, SORT_STRING);
        if ($names !== $sortedNames) {
            throw new InvalidArgumentException('UPGRADE_MOUNTED_STORAGE_IDENTITY_INVALID');
        }
        foreach ($this->volumeMarkers as $artifact => $marker) {
            $fields = array_keys($marker);
            sort($fields, SORT_STRING);
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $artifact) !== 1
                || $fields !== ['marker_id', 'marker_sha256']
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $marker['marker_id']) !== 1
                || !$this->isHash($marker['marker_sha256'])) {
                throw new InvalidArgumentException('UPGRADE_MOUNTED_STORAGE_IDENTITY_INVALID');
            }
        }
    }

    public function matchesImage(
        string $appVersion,
        string $deploymentId,
        string $releaseInventorySha256,
        int $storageLayoutVersion,
        int $storageLayoutGeneration,
    ): bool {
        return hash_equals($this->appVersion, $appVersion)
            && hash_equals($this->deploymentId, $deploymentId)
            && $this->sameHash($this->releaseInventorySha256, $releaseInventorySha256)
            && $this->storageLayoutVersion === $storageLayoutVersion
            && $this->storageLayoutGeneration === $storageLayoutGeneration;
    }

    private function isHash(string $value): bool
    {
        return preg_match('/^(?:sha256:)?[0-9a-f]{64}$/D', $value) === 1;
    }

    private function sameHash(string $left, string $right): bool
    {
        return hash_equals(
            str_starts_with($left, 'sha256:') ? substr($left, 7) : $left,
            str_starts_with($right, 'sha256:') ? substr($right, 7) : $right,
        );
    }
}
