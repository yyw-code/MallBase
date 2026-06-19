<?php
declare(strict_types=1);

namespace app\service\upload;

use app\model\upload\UploadAsset;
use app\model\upload\UploadAssetLocation;
use mall_base\base\BaseService;

/**
 * 素材 URL 批量解析服务。
 *
 * @extends BaseService<UploadAsset>
 */
class AssetResolver extends BaseService
{
    protected string $modelClass = UploadAsset::class;

    private const CHUNK_SIZE = 500;

    /**
     * @param array<int, int> $assetIds
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_unique(array_map('intval', $assetIds)), static fn(int $id): bool => $id > 0));
        if ($assetIds === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($assetIds, self::CHUNK_SIZE) as $chunk) {
            $rows = $this->model()
                ->alias('a')
                ->leftJoin('mb_upload_asset_location l', 'l.asset_id = a.id AND l.is_primary = 1 AND l.status = 1')
                ->whereIn('a.id', $chunk)
                ->where('a.status', UploadAsset::STATUS_NORMAL)
                ->field([
                    'a.id',
                    'a.type',
                    'a.name',
                    'a.mime',
                    'a.size',
                    'a.width',
                    'a.height',
                    'l.driver',
                    'l.path',
                    'l.url_prefix',
                    'l.bucket',
                    'l.region',
                    'l.endpoint',
                ])
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $row['full_url'] = $this->buildLocationUrl($row);
                $result[$id] = $row;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $location
     */
    public function buildLocationUrl(array $location): string
    {
        $driver = (string) ($location['driver'] ?? '');
        $path = trim((string) ($location['path'] ?? ''));
        if ($path === '') {
            return '';
        }

        if ($driver === UploadAssetLocation::DRIVER_REMOTE || preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $prefix = rtrim(trim((string) ($location['url_prefix'] ?? '')), '/');
        if ($prefix === '') {
            $prefix = $this->defaultPrefixForDriver($driver);
        }

        if ($prefix === '') {
            return $path;
        }

        return $prefix . '/' . ltrim($path, '/');
    }

    private function defaultPrefixForDriver(string $driver): string
    {
        return match ($driver) {
            UploadAssetLocation::DRIVER_LOCAL => $this->localUrlPrefix(),
            UploadAssetLocation::DRIVER_OSS => rtrim((string) getSystemSetting('oss_url_prefix', ''), '/'),
            UploadAssetLocation::DRIVER_COS => rtrim((string) getSystemSetting('cos_url_prefix', ''), '/'),
            UploadAssetLocation::DRIVER_STATIC => rtrim((string) getSystemSetting('site_url', ''), ''),
            default => '',
        };
    }

    private function localUrlPrefix(): string
    {
        $baseUrl = rtrim((string) getSystemSetting('local_base_url', ''), '/');
        if ($baseUrl === '') {
            $baseUrl = rtrim((string) getSystemSetting('site_url', ''), '/');
        }

        $urlPrefix = '/' . trim((string) getSystemSetting('local_url_prefix', '/uploads'), '/');
        return $baseUrl === '' ? $urlPrefix : $baseUrl . $urlPrefix;
    }
}
