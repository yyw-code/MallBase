<?php
declare(strict_types=1);

namespace app\service\upload;

use app\model\upload\UploadAsset;
use app\model\upload\UploadAssetCategory;
use app\model\upload\UploadAssetLocation;
use app\model\upload\UploadAssetUsage;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 素材核心服务。
 *
 * @extends BaseService<UploadAsset>
 */
class AssetService extends BaseService
{
    protected string $modelClass = UploadAsset::class;

    /**
     * 上传结果入素材表。
     *
     * @param array<string, mixed> $fileInfo
     * @return array<string, mixed>
     */
    public function createFromUploadedFile(
        array $fileInfo,
        string $driver,
        string $tempPath,
        mixed $file,
        string $module,
        string $uploaderType = 'admin',
        int $uploaderId = 0,
        int $categoryId = 0
    ): array {
        $path = trim((string) ($fileInfo['path'] ?? ''));
        if ($path === '') {
            throw new BusinessException('上传结果缺少素材路径');
        }

        $mime = (string) ($fileInfo['mime'] ?? $this->callFileMethod($file, 'getMime'));
        $type = $this->detectType($mime);
        $originalName = (string) ($this->callFileMethod($file, 'getOriginalName') ?: ($fileInfo['name'] ?? ''));
        $name = (string) ($fileInfo['name'] ?? basename($path));
        $hash = is_file($tempPath) ? (string) hash_file('sha256', $tempPath) : '';
        [$width, $height] = $this->imageSize($tempPath, $type);

        $categoryId = $categoryId > 0 ? $this->normalizeCategoryId($categoryId) : $this->resolveCategoryId($module, $type);
        $assetId = (int) $this->transaction(function () use (
            $fileInfo,
            $driver,
            $path,
            $module,
            $uploaderType,
            $uploaderId,
            $mime,
            $type,
            $originalName,
            $name,
            $hash,
            $width,
            $height,
            $categoryId
        ) {
            $asset = $this->model();
            $asset->save([
                'category_id' => $categoryId,
                'type' => $type,
                'name' => $name,
                'original_name' => $originalName,
                'mime' => $mime,
                'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'size' => (int) ($fileInfo['size'] ?? 0),
                'hash' => $hash,
                'width' => $width,
                'height' => $height,
                'module' => $module,
                'uploader_type' => $uploaderType,
                'uploader_id' => $uploaderId,
                'visibility' => UploadAsset::VISIBILITY_PUBLIC,
                'status' => UploadAsset::STATUS_NORMAL,
                'meta' => [],
            ]);

            $this->model(UploadAssetLocation::class)->save([
                'asset_id' => (int) $asset->id,
                'driver' => $driver,
                'path' => $path,
                'url_prefix' => $this->resolveUrlPrefix($driver),
                'bucket' => (string) getSystemSetting($driver . '_bucket', ''),
                'region' => (string) (getSystemSetting($driver . '_region', '') ?: getSystemSetting($driver . '_endpoint', '')),
                'endpoint' => (string) getSystemSetting($driver . '_endpoint', ''),
                'is_primary' => 1,
                'status' => UploadAssetLocation::STATUS_ENABLED,
                'etag' => '',
                'size' => (int) ($fileInfo['size'] ?? 0),
                'meta' => [
                    'url' => $fileInfo['url'] ?? '',
                    'full_url' => $fileInfo['full_url'] ?? '',
                    'modified' => $fileInfo['modified'] ?? '',
                ],
            ]);

            return (int) $asset->id;
        });

        $fileInfo['asset_id'] = $assetId;
        $fileInfo['category_id'] = $categoryId;
        $fileInfo['driver'] = $driver;
        $fileInfo['original_name'] = $originalName;

        return $fileInfo;
    }

    /**
     * @param array<int, int|string> $assetIds
     */
    public function assertUsableAssets(array $assetIds): void
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $assetIds)), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        $count = $this->model()
            ->whereIn('id', $ids)
            ->where('status', UploadAsset::STATUS_NORMAL)
            ->count();
        if ((int) $count !== count($ids)) {
            throw new BusinessException('素材不存在或已被删除');
        }
    }

    /**
     * @param array<int, int|string> $assetIds
     */
    public function assertUsableImageAssets(array $assetIds): void
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $assetIds)), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        $count = $this->model()
            ->whereIn('id', $ids)
            ->where('status', UploadAsset::STATUS_NORMAL)
            ->where('type', UploadAsset::TYPE_IMAGE)
            ->count();
        if ((int) $count !== count($ids)) {
            throw new BusinessException('图片素材不存在或已被删除');
        }
    }

    /**
     * @param array<int, int|string> $assetIds
     */
    public function syncUsage(string $ownerType, int $ownerId, string $field, array $assetIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $assetIds), static fn(int $id): bool => $id > 0));
        $this->model(UploadAssetUsage::class)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('field', $field)
            ->delete();

        if ($ids === []) {
            return;
        }

        $rows = [];
        foreach ($ids as $sort => $assetId) {
            $rows[] = [
                'asset_id' => $assetId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'field' => $field,
                'sort' => $sort,
            ];
        }
        $this->model(UploadAssetUsage::class)->saveAll($rows);
    }

    public function hasUsage(int $assetId): bool
    {
        return $this->model(UploadAssetUsage::class)
            ->where('asset_id', $assetId)
            ->count() > 0;
    }

    private function detectType(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return UploadAsset::TYPE_IMAGE;
        }
        if (str_starts_with($mime, 'video/')) {
            return UploadAsset::TYPE_VIDEO;
        }
        return UploadAsset::TYPE_FILE;
    }

    private function callFileMethod(mixed $file, string $method): mixed
    {
        if (!is_object($file) || !method_exists($file, $method)) {
            return null;
        }

        return $file->{$method}();
    }

    /**
     * @return array{0:int,1:int}
     */
    private function imageSize(string $path, string $type): array
    {
        if ($type !== UploadAsset::TYPE_IMAGE || !is_file($path)) {
            return [0, 0];
        }

        $size = @getimagesize($path);
        if (!is_array($size)) {
            return [0, 0];
        }

        return [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];
    }

    private function resolveCategoryId(string $module, string $type): int
    {
        $code = match ($module) {
            'goods' => UploadAssetCategory::CODE_GOODS,
            'article', 'content' => UploadAssetCategory::CODE_ARTICLE,
            'rich_text', 'editor' => UploadAssetCategory::CODE_RICH_TEXT,
            'review' => UploadAssetCategory::CODE_REVIEW,
            'avatar', 'user_avatar', 'wechat_avatar' => UploadAssetCategory::CODE_AVATAR,
            'setting', 'dynamic_form' => UploadAssetCategory::CODE_SETTING,
            default => $type === UploadAsset::TYPE_IMAGE && $module === 'client'
                ? UploadAssetCategory::CODE_REVIEW
                : UploadAssetCategory::CODE_OTHER,
        };

        $id = (int) $this->model(UploadAssetCategory::class)
            ->where('code', $code)
            ->value('id');

        return $id > 0 ? $id : 6;
    }

    private function normalizeCategoryId(int $categoryId): int
    {
        $id = (int) $this->model(UploadAssetCategory::class)
            ->where('id', $categoryId)
            ->where('status', 1)
            ->value('id');

        if ($id <= 0) {
            throw new BusinessException('素材分类不存在或已禁用');
        }

        return $id;
    }

    private function resolveUrlPrefix(string $driver): string
    {
        if ($driver === UploadAssetLocation::DRIVER_LOCAL) {
            $baseUrl = rtrim((string) getSystemSetting('local_base_url', ''), '/');
            if ($baseUrl === '') {
                $baseUrl = rtrim((string) getSystemSetting('site_url', ''), '/');
            }
            $urlPrefix = '/' . trim((string) getSystemSetting('local_url_prefix', '/uploads'), '/');
            return $baseUrl === '' ? $urlPrefix : $baseUrl . $urlPrefix;
        }

        return rtrim((string) getSystemSetting($driver . '_url_prefix', ''), '/');
    }
}
