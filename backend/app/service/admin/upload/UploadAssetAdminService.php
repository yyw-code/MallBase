<?php
declare(strict_types=1);

namespace app\service\admin\upload;

use app\model\upload\UploadAsset;
use app\model\upload\UploadAssetCategory;
use app\model\upload\UploadAssetLocation;
use app\model\upload\UploadAssetUsage;
use app\service\upload\AssetResolver;
use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * 后台素材管理服务。
 *
 * @extends BaseService<UploadAsset>
 */
class UploadAssetAdminService extends BaseService
{
    protected string $modelClass = UploadAsset::class;

    protected function buildListQuery(array $where)
    {
        $query = $this->model()
            ->alias('a')
            ->leftJoin('mb_upload_asset_category c', 'c.id = a.category_id')
            ->when(($where['keyword'] ?? '') !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('a.name|a.original_name|a.hash', "%{$keyword}%");
            })
            ->when(($where['category_id'] ?? '') !== '', function ($q) use ($where) {
                $q->where('a.category_id', (int) $where['category_id']);
            })
            ->when(($where['type'] ?? '') !== '', function ($q) use ($where) {
                $q->where('a.type', (string) $where['type']);
            })
            ->when(($where['ext'] ?? '') !== '', function ($q) use ($where) {
                $exts = array_values(array_filter(array_map(
                    static fn(string $ext): string => strtolower(trim($ext, " \t\n\r\0\x0B.")),
                    explode(',', (string) $where['ext'])
                )));
                if ($exts !== []) {
                    $q->whereIn('a.ext', $exts);
                }
            })
            ->when(($where['module'] ?? '') !== '', function ($q) use ($where) {
                $q->where('a.module', (string) $where['module']);
            })
            ->when(($where['uploader_type'] ?? '') !== '', function ($q) use ($where) {
                $q->where('a.uploader_type', (string) $where['uploader_type']);
            })
            ->when(($where['uploader_id'] ?? '') !== '', function ($q) use ($where) {
                $q->where('a.uploader_id', (int) $where['uploader_id']);
            })
            ->when(($where['status'] ?? '') !== '', function ($q) use ($where) {
                $q->where('a.status', (int) $where['status']);
            });

        if (($where['driver'] ?? '') !== '') {
            $assetIds = $this->model(UploadAssetLocation::class)
                ->where('driver', (string) $where['driver'])
                ->where('status', UploadAssetLocation::STATUS_ENABLED)
                ->column('asset_id');
            $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
            $query->whereIn('a.id', $assetIds === [] ? [0] : $assetIds);
        }

        return $query;
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        if (($where['status'] ?? '') === '') {
            $where['status'] = UploadAsset::STATUS_NORMAL;
        }

        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->field($this->listFields())
            ->order('a.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = $this->appendDerivedFields($list);
        return compact('total', 'list');
    }

    /**
     * 素材选择器列表。
     *
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function select(array $where, int $page, int $limit): array
    {
        $where['status'] = UploadAsset::STATUS_NORMAL;
        return $this->getList($where, $page, $limit);
    }

    public function getInfo(int $id): array
    {
        $rows = $this->buildListQuery(['id' => $id])
            ->where('a.id', $id)
            ->field($this->listFields())
            ->select()
            ->toArray();
        if ($rows === []) {
            throw new BusinessException('素材不存在');
        }

        $info = $this->appendDerivedFields([$rows[0]])[0];
        $info['locations'] = $this->model(UploadAssetLocation::class)
            ->where('asset_id', $id)
            ->order('is_primary', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $info['usage'] = $this->getUsage($id);

        return $info;
    }

    public function update(int $id, array $data): bool
    {
        $asset = $this->findAsset($id);
        $payload = [];
        foreach (['name', 'category_id', 'visibility', 'status', 'meta'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (isset($payload['category_id'])) {
            $this->assertCategoryExists((int) $payload['category_id']);
        }
        if ($payload === []) {
            return true;
        }

        $asset->save($payload);
        return true;
    }

    public function move(int $id, int $categoryId): bool
    {
        $this->assertCategoryExists($categoryId);
        $asset = $this->findAsset($id);
        $asset->save(['category_id' => $categoryId]);

        return true;
    }

    public function delete(int $id): bool
    {
        $asset = $this->findAsset($id);
        $this->assertNoUsage($id);
        $asset->save([
            'status' => UploadAsset::STATUS_DELETED,
            'delete_time' => time(),
        ]);

        return true;
    }

    public function restore(int $id): bool
    {
        $asset = $this->findAsset($id);
        $asset->save([
            'status' => UploadAsset::STATUS_NORMAL,
            'delete_time' => null,
        ]);

        return true;
    }

    public function purge(int $id): bool
    {
        $asset = $this->findAsset($id);
        $this->assertNoUsage($id);

        $locations = $this->model(UploadAssetLocation::class)
            ->where('asset_id', $id)
            ->select()
            ->toArray();

        foreach ($locations as $location) {
            $this->deleteLocationObject($location);
        }

        return (bool) $this->transaction(function () use ($id, $asset) {
            $this->model(UploadAssetLocation::class)->where('asset_id', $id)->delete();
            $this->model(UploadAssetUsage::class)->where('asset_id', $id)->delete();
            return $asset->delete();
        });
    }

    /**
     * 清空回收站。
     */
    public function clearRecycleBin(): int
    {
        $count = 0;
        do {
            $ids = $this->model()
                ->where('status', UploadAsset::STATUS_DELETED)
                ->limit(100)
                ->column('id');

            foreach ($ids as $id) {
                $this->purge((int) $id);
                $count++;
            }
        } while ($ids !== []);

        return $count;
    }

    /**
     * 清理过期回收站素材。
     */
    public function cleanupRecycleBin(int $retentionDays, int $batchSize): int
    {
        $retentionDays = max(1, $retentionDays);
        $batchSize = max(1, min(500, $batchSize));
        $expireAt = time() - $retentionDays * 86400;
        $ids = $this->model()
            ->where('status', UploadAsset::STATUS_DELETED)
            ->whereNotNull('delete_time')
            ->where('delete_time', '<=', $expireAt)
            ->limit($batchSize)
            ->column('id');

        $count = 0;
        foreach ($ids as $id) {
            $this->purge((int) $id);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUsage(int $assetId): array
    {
        return $this->model(UploadAssetUsage::class)
            ->where('asset_id', $assetId)
            ->order('owner_type', 'asc')
            ->order('owner_id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @return array<int,string>
     */
    private function listFields(): array
    {
        return [
            'a.*',
            'c.name' => 'category_name',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $list
     * @return array<int,array<string,mixed>>
     */
    private function appendDerivedFields(array $list): array
    {
        if ($list === []) {
            return [];
        }

        $assetIds = array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $list));
        $primaryLocations = $this->model(UploadAssetLocation::class)
            ->whereIn('asset_id', $assetIds)
            ->where('is_primary', 1)
            ->where('status', UploadAssetLocation::STATUS_ENABLED)
            ->select()
            ->toArray();
        $primaryLocationsByAssetId = array_column($primaryLocations, null, 'asset_id');

        $usageRows = $this->model(UploadAssetUsage::class)
            ->whereIn('asset_id', $assetIds)
            ->field('asset_id, COUNT(*) AS usage_count')
            ->group('asset_id')
            ->select()
            ->toArray();
        $usageCounts = array_column($usageRows, 'usage_count', 'asset_id');
        $resolver = app()->make(AssetResolver::class);

        foreach ($list as &$row) {
            $location = $primaryLocationsByAssetId[(int) $row['id']] ?? [];
            $row['full_url'] = $location === [] ? '' : $resolver->buildLocationUrl($location);
            $row['usage_count'] = (int) ($usageCounts[(int) $row['id']] ?? 0);
        }
        unset($row);

        return $list;
    }

    private function findAsset(int $id): UploadAsset
    {
        $asset = $this->model()->find($id);
        if ($asset === null) {
            throw new BusinessException('素材不存在');
        }

        return $asset;
    }

    private function assertNoUsage(int $assetId): void
    {
        $count = $this->model(UploadAssetUsage::class)
            ->where('asset_id', $assetId)
            ->count();
        if ((int) $count > 0) {
            throw new BusinessException('素材仍被业务引用，不能删除');
        }
    }

    private function assertCategoryExists(int $categoryId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        $exists = $this->model(UploadAssetCategory::class)->where('id', $categoryId)->count();
        if ((int) $exists <= 0) {
            throw new BusinessException('素材分类不存在');
        }
    }

    /**
     * @param array<string,mixed> $location
     */
    private function deleteLocationObject(array $location): void
    {
        $driver = (string) ($location['driver'] ?? '');
        $path = (string) ($location['path'] ?? '');
        if ($path === '' || in_array($driver, [UploadAssetLocation::DRIVER_STATIC, UploadAssetLocation::DRIVER_REMOTE], true)) {
            return;
        }

        try {
            $uploadDriver = DriverManager::driver('upload', $driver, $this->driverConfig($driver));
            if (method_exists($uploadDriver, 'delete') && !$uploadDriver->delete($path)) {
                throw new BusinessException('删除存储对象失败');
            }
        } catch (Throwable $e) {
            throw new BusinessException('删除存储对象失败：' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function driverConfig(string $driver): array
    {
        $groupMap = [
            UploadAssetLocation::DRIVER_LOCAL => 'UploadLocal',
            UploadAssetLocation::DRIVER_OSS => 'UploadOss',
            UploadAssetLocation::DRIVER_COS => 'UploadCos',
        ];
        $rawConfig = isset($groupMap[$driver]) ? getSystemSettingGroup($groupMap[$driver]) : [];
        $prefix = $driver . '_';
        $config = [];
        foreach ($rawConfig as $key => $value) {
            $config[str_starts_with((string) $key, $prefix) ? substr((string) $key, strlen($prefix)) : (string) $key] = $value;
        }
        if (empty($config['base_url'])) {
            $config['base_url'] = (string) getSystemSetting('site_url', '');
        }

        return $config;
    }
}
