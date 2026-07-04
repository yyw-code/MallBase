<?php
declare(strict_types=1);

namespace app\job;

use app\model\upload\UploadAsset;
use app\model\upload\UploadAssetCategory;
use app\model\upload\UploadAssetLocation;
use app\model\upload\UploadAssetMigration;
use app\model\upload\UploadAssetMigrationLog;
use app\model\upload\UploadAssetUsage;
use mall_base\base\BaseJob;
use mall_base\drivers\DriverManager;
use think\facade\Db;
use think\queue\Job as QueueJob;
use Throwable;

/**
 * 素材迁移任务。
 *
 * - legacy_local -> local/oss/cos：扫描旧业务字段，导入 asset/location 并回写素材 ID。
 * - local/oss/cos -> local/oss/cos：复制对象，新增目标 location，切换 primary。
 */
class UploadAssetMigrationJob extends BaseJob
{
    private int $migrationId = 0;

    /** @var array<string,int> */
    private array $legacyAssetCache = [];

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        parent::__construct();
        $this->migrationId = (int) ($data['migration_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function fire(QueueJob $job, array $data): void
    {
        if ($this->migrationId <= 0 && isset($data['migration_id'])) {
            $this->migrationId = (int) $data['migration_id'];
        }

        try {
            $this->handle();
        } catch (Throwable $e) {
            $this->logger()->jobError($e);
        } finally {
            $job->delete();
        }
    }

    public function handle(): void
    {
        if ($this->migrationId <= 0) {
            return;
        }

        $migration = $this->findMigration($this->migrationId);
        $this->updateMigration($migration, ['status' => UploadAssetMigration::STATUS_PROCESSING]);
        $this->clearMigrationLogs((int) $migration->id);

        try {
            $result = $migration->source_driver === 'legacy_local'
                ? $this->runLegacyLocalImport($migration)
                : $this->runStorageMigration($migration);

            $this->updateMigration($migration, [
                'status' => $result['fail_count'] > 0
                    ? UploadAssetMigration::STATUS_FAILED
                    : UploadAssetMigration::STATUS_DONE,
                'total' => $result['total'],
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count'],
                'last_error' => mb_substr($result['last_error'], 0, 1000),
            ]);
        } catch (Throwable $e) {
            $this->createTaskFailureLog($migration, $e->getMessage());
            $this->updateMigration($migration, [
                'status' => UploadAssetMigration::STATUS_FAILED,
                'fail_count' => max(1, (int) $migration->fail_count),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }

    /**
     * @return array{total:int,success_count:int,fail_count:int,last_error:string}
     */
    private function runStorageMigration(UploadAssetMigration $migration): array
    {
        $source = (string) $migration->source_driver;
        $target = (string) $migration->target_driver;
        $options = is_array($migration->options) ? $migration->options : [];
        $batchSize = $this->batchSize($options);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $deleteSource = $this->deleteSourceAfterSuccess($source, $options);
        $lastId = 0;
        $total = 0;
        $success = 0;
        $fail = 0;
        $lastError = '';

        $sourceDriver = $this->uploadDriver($source);
        $targetDriver = $this->uploadDriver($target);

        while (true) {
            $rows = $this->buildStorageMigrationQuery($source, $options)
                ->where('a.id', '>', $lastId)
                ->order('a.id', 'asc')
                ->limit($limit > 0 ? min($batchSize, max(0, $limit - $total)) : $batchSize)
                ->select()
                ->toArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $total++;
                $assetId = (int) $row['id'];
                $path = trim((string) ($row['source_path'] ?? ''));
                $sourceLocationId = (int) ($row['source_location_id'] ?? 0);
                $startedAt = microtime(true);
                $logId = $this->createMigrationLog([
                    'migration_id' => (int) $migration->id,
                    'asset_id' => $assetId,
                    'source_driver' => $source,
                    'target_driver' => $target,
                    'source_path' => $path,
                    'target_path' => $path,
                    'stage' => 'start',
                    'status' => UploadAssetMigrationLog::STATUS_PROCESSING,
                    'delete_source' => $deleteSource ? 1 : 0,
                    'source_deleted' => 0,
                    'message' => '开始迁移',
                    'error_message' => '',
                    'duration_ms' => 0,
                ]);

                try {
                    if ($path === '') {
                        throw new \RuntimeException('素材缺少源路径');
                    }

                    $sourceDeleted = false;
                    $message = '';
                    $existingTargetLocationId = $this->findLocationId($assetId, $target, $path);
                    if ($existingTargetLocationId > 0) {
                        $this->switchPrimaryLocation($assetId, $existingTargetLocationId);
                        $message = '目标位置已存在，已切换主位置';
                    } else {
                        $tmpPath = $this->downloadToTemp($sourceDriver, $path);
                        try {
                            $targetDriver->upload($tmpPath, $path);
                            $info = $targetDriver->getFileInfo($path) ?: $this->fileInfoFromLocal($path, $tmpPath, $targetDriver);
                            $locationId = $this->createLocation($assetId, $target, $path, $info, false);
                            $this->switchPrimaryLocation($assetId, $locationId);
                            $message = '已复制到目标存储并切换主位置';
                        } finally {
                            if (isset($tmpPath) && is_file($tmpPath)) {
                                @unlink($tmpPath);
                            }
                        }
                    }

                    if ($deleteSource) {
                        $this->deleteSourceLocation($sourceLocationId, $sourceDriver, $path);
                        $sourceDeleted = true;
                        $message .= '，已删除源文件';
                    }

                    $success++;
                    $this->finishMigrationLog($logId, [
                        'stage' => $deleteSource ? 'delete_source' : 'done',
                        'status' => UploadAssetMigrationLog::STATUS_SUCCESS,
                        'source_deleted' => $sourceDeleted ? 1 : 0,
                        'message' => $message,
                        'duration_ms' => $this->durationMs($startedAt),
                    ]);
                } catch (Throwable $e) {
                    $fail++;
                    $lastError = sprintf('asset_id=%d: %s', $assetId, $e->getMessage());
                    $this->finishMigrationLog($logId, [
                        'stage' => 'failed',
                        'status' => UploadAssetMigrationLog::STATUS_FAILED,
                        'error_message' => mb_substr($e->getMessage(), 0, 1000),
                        'duration_ms' => $this->durationMs($startedAt),
                    ]);
                }

                $this->updateMigration($migration, [
                    'total' => $total,
                    'success_count' => $success,
                    'fail_count' => $fail,
                    'last_error' => mb_substr($lastError, 0, 1000),
                ]);

                if ($limit > 0 && $total >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'total' => $total,
            'success_count' => $success,
            'fail_count' => $fail,
            'last_error' => $lastError,
        ];
    }

    private function buildStorageMigrationQuery(string $source, array $options)
    {
        $query = Db::name('upload_asset')
            ->alias('a')
            ->join('upload_asset_location l', 'l.asset_id = a.id AND l.is_primary = 1 AND l.status = 1')
            ->where('a.status', UploadAsset::STATUS_NORMAL)
            ->where('l.driver', $source)
            ->field('a.id,a.category_id,a.type,a.module,l.id AS source_location_id,l.path AS source_path,l.size AS source_size');

        $assetIds = $this->intList($options['asset_ids'] ?? []);
        if ($assetIds !== []) {
            $query->whereIn('a.id', $assetIds);
        }
        foreach (['category_id', 'module', 'type'] as $field) {
            if (($options[$field] ?? '') !== '') {
                $query->where('a.' . $field, $options[$field]);
            }
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function deleteSourceAfterSuccess(string $source, array $options): bool
    {
        return $source !== 'legacy_local' && !empty($options['delete_source_after_success']);
    }

    private function deleteSourceLocation(int $sourceLocationId, object $sourceDriver, string $path): void
    {
        if ($sourceLocationId <= 0) {
            throw new \RuntimeException('源存储位置不存在');
        }

        if (!method_exists($sourceDriver, 'delete') || !$sourceDriver->delete($path)) {
            $error = method_exists($sourceDriver, 'getError') ? (string) $sourceDriver->getError() : '';
            throw new \RuntimeException('删除源文件失败' . ($error === '' ? '' : '：' . $error));
        }

        Db::name('upload_asset_location')->where('id', $sourceLocationId)->update([
            'status' => UploadAssetLocation::STATUS_DISABLED,
            'is_primary' => 0,
            'meta' => json_encode([
                'deleted_by_migration_id' => $this->migrationId,
                'deleted_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createMigrationLog(array $data): int
    {
        return (int) Db::name('upload_asset_migration_log')->insertGetId([
            'migration_id' => (int) ($data['migration_id'] ?? 0),
            'asset_id' => (int) ($data['asset_id'] ?? 0),
            'source_driver' => (string) ($data['source_driver'] ?? ''),
            'target_driver' => (string) ($data['target_driver'] ?? ''),
            'source_path' => (string) ($data['source_path'] ?? ''),
            'target_path' => (string) ($data['target_path'] ?? ''),
            'stage' => (string) ($data['stage'] ?? ''),
            'status' => (int) ($data['status'] ?? UploadAssetMigrationLog::STATUS_PROCESSING),
            'delete_source' => (int) ($data['delete_source'] ?? 0),
            'source_deleted' => (int) ($data['source_deleted'] ?? 0),
            'message' => mb_substr((string) ($data['message'] ?? ''), 0, 500),
            'error_message' => mb_substr((string) ($data['error_message'] ?? ''), 0, 1000),
            'duration_ms' => (int) ($data['duration_ms'] ?? 0),
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function finishMigrationLog(int $id, array $data): void
    {
        if ($id <= 0) {
            return;
        }

        $payload = [];
        foreach (['stage', 'status', 'source_deleted', 'message', 'error_message', 'duration_ms'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = is_string($data[$field])
                    ? mb_substr($data[$field], 0, $field === 'error_message' ? 1000 : 500)
                    : $data[$field];
            }
        }
        if ($payload !== []) {
            Db::name('upload_asset_migration_log')->where('id', $id)->update($payload);
        }
    }

    private function clearMigrationLogs(int $migrationId): void
    {
        Db::name('upload_asset_migration_log')->where('migration_id', $migrationId)->delete();
    }

    private function createTaskFailureLog(UploadAssetMigration $migration, string $error): void
    {
        try {
            $this->createMigrationLog([
                'migration_id' => (int) $migration->id,
                'asset_id' => 0,
                'source_driver' => (string) $migration->source_driver,
                'target_driver' => (string) $migration->target_driver,
                'stage' => 'task',
                'status' => UploadAssetMigrationLog::STATUS_FAILED,
                'message' => '任务执行失败',
                'error_message' => $error,
            ]);
        } catch (Throwable) {
            // 日志写入失败不能覆盖原始迁移错误。
        }
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @return array{total:int,success_count:int,fail_count:int,last_error:string}
     */
    private function runLegacyLocalImport(UploadAssetMigration $migration): array
    {
        $options = is_array($migration->options) ? $migration->options : [];
        $target = (string) $migration->target_driver;
        $total = 0;
        $success = 0;
        $fail = 0;
        $lastError = '';

        foreach ($this->legacySpecs() as $spec) {
            $result = $this->migrateLegacyTable($spec, $target, $options);
            $total += $result['total'];
            $success += $result['success_count'];
            $fail += $result['fail_count'];
            $lastError = $result['last_error'] ?: $lastError;
        }

        return [
            'total' => $total,
            'success_count' => $success,
            'fail_count' => $fail,
            'last_error' => $lastError,
        ];
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $options
     * @return array{total:int,success_count:int,fail_count:int,last_error:string}
     */
    private function migrateLegacyTable(array $spec, string $target, array $options): array
    {
        $table = (string) $spec['table'];
        $ownerType = (string) $spec['owner_type'];
        $pk = (string) ($spec['pk'] ?? 'id');
        $ownerIdField = (string) ($spec['owner_id_field'] ?? $pk);
        $usageFieldMap = is_array($spec['usage_field_map'] ?? null) ? $spec['usage_field_map'] : [];
        $batchSize = $this->batchSize($options);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $lastId = 0;
        $total = 0;
        $success = 0;
        $fail = 0;
        $lastError = '';

        while (true) {
            $query = Db::name($table)
                ->where($pk, '>', $lastId)
                ->order($pk, 'asc')
                ->limit($limit > 0 ? min($batchSize, max(0, $limit - $total)) : $batchSize);
            if (($options['table'] ?? '') !== '' && $options['table'] !== $table) {
                return ['total' => 0, 'success_count' => 0, 'fail_count' => 0, 'last_error' => ''];
            }

            $rows = $query->select()->toArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row[$pk];
                if (!$this->rowHasLegacyReference($row, $spec)) {
                    continue;
                }

                $total++;

                try {
                    $changed = false;
                    $updates = [];
                    $usageByField = [];

                    foreach (($spec['single'] ?? []) as $field) {
                        $assetId = $this->importLegacyValue((string) ($row[$field] ?? ''), $target, $ownerType);
                        if ($assetId > 0) {
                            $updates[$field] = $assetId;
                            $usageByField[$field] = [$assetId];
                            $changed = true;
                        } elseif ($this->isAssetId($row[$field] ?? null)) {
                            $usageByField[$field] = [(int) $row[$field]];
                        }
                    }

                    foreach (($spec['multi'] ?? []) as $field) {
                        [$next, $ids, $fieldChanged] = $this->migrateLegacyList($row[$field] ?? null, $target, $ownerType);
                        if ($fieldChanged) {
                            $updates[$field] = json_encode($next, JSON_UNESCAPED_UNICODE);
                            $changed = true;
                        }
                        if ($ids !== []) {
                            $usageByField[$field] = $ids;
                        }
                    }

                    foreach (($spec['spec_meta'] ?? []) as $field) {
                        [$next, $ids, $fieldChanged] = $this->migrateSpecMeta($row[$field] ?? null, $target);
                        if ($fieldChanged) {
                            $updates[$field] = json_encode($next, JSON_UNESCAPED_UNICODE);
                            $changed = true;
                        }
                        if ($ids !== []) {
                            $usageByField[$field] = $ids;
                        }
                    }

                    foreach (($spec['rich_text'] ?? []) as $field) {
                        [$next, $ids, $fieldChanged] = $this->migrateRichText((string) ($row[$field] ?? ''), $target, $ownerType);
                        if ($fieldChanged) {
                            $updates[$field] = $next;
                            $changed = true;
                        }
                        if ($ids !== []) {
                            $usageByField[$field] = $ids;
                        }
                    }

                    if ($updates !== []) {
                        Db::name($table)->where($pk, $lastId)->update($updates);
                    }
                    foreach ($usageByField as $field => $ids) {
                        $ownerId = (int) ($row[$ownerIdField] ?? $lastId);
                        $usageField = (string) ($usageFieldMap[$field] ?? $field);
                        $this->syncUsage($ownerType, $ownerId, $usageField, $ids);
                    }

                    if ($changed) {
                        $success++;
                    }
                } catch (Throwable $e) {
                    $fail++;
                    $lastError = sprintf('%s.%s=%d: %s', $table, $pk, $lastId, $e->getMessage());
                }

                if ($limit > 0 && $total >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'total' => $total,
            'success_count' => $success,
            'fail_count' => $fail,
            'last_error' => $lastError,
        ];
    }

    /**
     * 旧数据迁移只统计仍然保存旧路径的记录；已回写为 asset_id 的记录不再计入迁移进度。
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $spec
     */
    private function rowHasLegacyReference(array $row, array $spec): bool
    {
        foreach (($spec['single'] ?? []) as $field) {
            if ($this->hasLegacyValue($row[$field] ?? null)) {
                return true;
            }
        }

        foreach (($spec['multi'] ?? []) as $field) {
            if ($this->hasLegacyListReference($row[$field] ?? null)) {
                return true;
            }
        }

        foreach (($spec['spec_meta'] ?? []) as $field) {
            if ($this->hasLegacySpecMetaReference($row[$field] ?? null)) {
                return true;
            }
        }

        foreach (($spec['rich_text'] ?? []) as $field) {
            if ($this->hasLegacyRichTextReference((string) ($row[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function hasLegacyListReference(mixed $value): bool
    {
        foreach ($this->decodeListValue($value) as $item) {
            $raw = is_array($item) ? ($item['url'] ?? $item['path'] ?? '') : $item;
            if ($this->hasLegacyValue($raw)) {
                return true;
            }
        }

        return false;
    }

    private function hasLegacySpecMetaReference(mixed $value): bool
    {
        $meta = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($meta)) {
            return false;
        }

        foreach ($meta as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (($group['values'] ?? []) as $specValue) {
                if (is_array($specValue) && $this->hasLegacyValue($specValue['pic'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasLegacyRichTextReference(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (!preg_match_all('/<(img|video)\b[^>]*>/i', $html, $matches)) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            if (preg_match('/data-asset-id=["\']?(\d+)/i', $tag)) {
                continue;
            }
            if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $srcMatch) && $this->hasLegacyValue($srcMatch[1])) {
                return true;
            }
        }

        return false;
    }

    private function hasLegacyValue(mixed $value): bool
    {
        if ($this->isAssetId($value)) {
            return false;
        }

        $raw = trim(is_scalar($value) ? (string) $value : '');
        return $raw !== '' && $this->normalizeLegacyLocalPath($raw) !== '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function legacySpecs(): array
    {
        return [
            ['table' => 'goods', 'owner_type' => 'goods', 'single' => ['main_image', 'main_video'], 'multi' => ['images'], 'spec_meta' => ['spec_meta']],
            ['table' => 'goods_detail', 'owner_type' => 'goods', 'owner_id_field' => 'goods_id', 'rich_text' => ['description']],
            ['table' => 'goods_sku_detail', 'owner_type' => 'goods', 'owner_id_field' => 'goods_id', 'rich_text' => ['description'], 'usage_field_map' => ['description' => 'skus.description']],
            ['table' => 'goods_sku', 'owner_type' => 'goods_sku', 'single' => ['image']],
            ['table' => 'goods_category', 'owner_type' => 'goods_category', 'single' => ['image']],
            ['table' => 'goods_brand', 'owner_type' => 'goods_brand', 'single' => ['logo']],
            ['table' => 'goods_comment', 'owner_type' => 'goods_comment', 'multi' => ['images', 'append_images']],
            ['table' => 'user', 'owner_type' => 'user', 'single' => ['avatar']],
            ['table' => 'order_item', 'owner_type' => 'order_item', 'single' => ['goods_image']],
        ];
    }

    private function importLegacyValue(string $value, string $target, string $module): int
    {
        $path = $this->normalizeLegacyLocalPath($value);
        if ($path === '') {
            return 0;
        }
        if (isset($this->legacyAssetCache[$path])) {
            return $this->legacyAssetCache[$path];
        }

        $existing = (int) Db::name('upload_asset_location')
            ->where('driver', UploadAssetLocation::DRIVER_LOCAL)
            ->where('path', $path)
            ->where('status', UploadAssetLocation::STATUS_ENABLED)
            ->value('asset_id');
        if ($existing > 0) {
            $this->legacyAssetCache[$path] = $existing;
            return $existing;
        }

        $localPath = $this->legacyFullPath($path);
        if (!is_file($localPath)) {
            throw new \RuntimeException('旧本地文件不存在：' . $path);
        }

        $assetId = $this->createAssetFromLocalFile($path, $localPath, $target, $module);
        $this->legacyAssetCache[$path] = $assetId;

        return $assetId;
    }

    /**
     * @return array{0:array<int,int>,1:array<int,int>,2:bool}
     */
    private function migrateLegacyList(mixed $value, string $target, string $module): array
    {
        $items = $this->decodeListValue($value);
        $next = [];
        $ids = [];
        $changed = false;

        foreach ($items as $item) {
            if ($this->isAssetId($item)) {
                $assetId = (int) $item;
            } else {
                $raw = is_array($item) ? (string) ($item['url'] ?? $item['path'] ?? '') : (string) $item;
                $assetId = $this->importLegacyValue($raw, $target, $module);
                if ($assetId <= 0) {
                    continue;
                }
                $changed = true;
            }
            $next[] = $assetId;
            $ids[] = $assetId;
        }

        return [$next, $ids, $changed];
    }

    /**
     * @return array{0:array<int,mixed>,1:array<int,int>,2:bool}
     */
    private function migrateSpecMeta(mixed $value, string $target): array
    {
        $meta = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($meta)) {
            return [[], [], false];
        }

        $ids = [];
        $changed = false;
        foreach ($meta as &$group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (($group['values'] ?? []) as &$specValue) {
                if (!is_array($specValue) || !array_key_exists('pic', $specValue)) {
                    continue;
                }
                if ($this->isAssetId($specValue['pic'])) {
                    $ids[] = (int) $specValue['pic'];
                    continue;
                }
                $assetId = $this->importLegacyValue((string) $specValue['pic'], $target, 'goods');
                if ($assetId > 0) {
                    $specValue['pic'] = $assetId;
                    $ids[] = $assetId;
                    $changed = true;
                }
            }
            unset($specValue);
        }
        unset($group);

        return [$meta, $ids, $changed];
    }

    /**
     * @return array{0:string,1:array<int,int>,2:bool}
     */
    private function migrateRichText(string $html, string $target, string $module): array
    {
        if ($html === '') {
            return ['', [], false];
        }

        $ids = [];
        $changed = false;
        $next = preg_replace_callback('/<(img|video)\b[^>]*>/i', function (array $matches) use ($target, $module, &$ids, &$changed) {
            $tag = $matches[0];
            if (preg_match('/data-asset-id=["\']?(\d+)/i', $tag, $idMatch)) {
                $ids[] = (int) $idMatch[1];
                return $tag;
            }
            if (!preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                return $tag;
            }

            $assetId = $this->importLegacyValue((string) $srcMatch[1], $target, $module);
            if ($assetId <= 0) {
                return $tag;
            }

            $ids[] = $assetId;
            $changed = true;
            return preg_replace('/\s*\/?>$/', ' data-asset-id="' . $assetId . '"$0', $tag) ?: $tag;
        }, $html);

        return [$next ?? $html, $ids, $changed];
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeListValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($this->isAssetId($value)) {
            return [(int) $value];
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function normalizeLegacyLocalPath(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ctype_digit($value) || str_starts_with($value, 'data:') || str_starts_with($value, 'blob:')) {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            $path = (string) parse_url($value, PHP_URL_PATH);
        } else {
            $path = strtok($value, '?') ?: $value;
        }

        $path = rawurldecode($path);
        $path = ltrim($path, '/');
        $localUrlPrefix = trim((string) getSystemSetting('local_url_prefix', '/uploads'), '/');
        foreach (array_filter([$localUrlPrefix, 'uploads', 'public/uploads']) as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix) + 1);
                break;
            }
        }

        if ($path === '' || str_contains($path, '..')) {
            return '';
        }

        return $path;
    }

    private function createAssetFromLocalFile(string $path, string $localPath, string $target, string $module): int
    {
        $mime = (string) (mime_content_type($localPath) ?: '');
        $type = $this->detectType($mime);
        $name = basename($path);
        $size = (int) filesize($localPath);
        $hash = (string) hash_file('sha256', $localPath);
        [$width, $height] = $this->imageSize($localPath, $type);
        $categoryId = $this->resolveCategoryId($module, $type);

        $sourceDriver = $this->legacySourceDriver($path);
        $targetInfo = null;
        if ($target !== UploadAssetLocation::DRIVER_LOCAL || $sourceDriver === UploadAssetLocation::DRIVER_STATIC) {
            $targetDriver = $this->uploadDriver($target);
            $targetDriver->upload($localPath, $path);
            $targetInfo = $targetDriver->getFileInfo($path);
        }

        return (int) Db::transaction(function () use ($categoryId, $type, $name, $mime, $size, $hash, $width, $height, $module, $path, $target, $targetInfo, $sourceDriver, $localPath) {
            $assetId = (int) Db::name('upload_asset')->insertGetId([
                'category_id' => $categoryId,
                'type' => $type,
                'name' => $name,
                'original_name' => $name,
                'mime' => $mime,
                'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'size' => $size,
                'hash' => $hash,
                'width' => $width,
                'height' => $height,
                'module' => $module,
                'uploader_type' => 'migration',
                'uploader_id' => 0,
                'visibility' => UploadAsset::VISIBILITY_PUBLIC,
                'status' => UploadAsset::STATUS_NORMAL,
                'meta' => json_encode(['migration_id' => $this->migrationId], JSON_UNESCAPED_UNICODE),
            ]);

            $sourceIsPrimary = $target === UploadAssetLocation::DRIVER_LOCAL && $sourceDriver !== UploadAssetLocation::DRIVER_STATIC;
            $this->createLocation($assetId, $sourceDriver, $path, [
                'size' => $size,
                'mime' => $mime,
                'url' => ($sourceDriver === UploadAssetLocation::DRIVER_STATIC ? '/' : '/uploads/') . ltrim($path, '/'),
                'full_url' => '',
                'modified' => date('Y-m-d H:i:s', (int) filemtime($localPath)),
            ], $sourceIsPrimary);

            if (!$sourceIsPrimary) {
                $this->createLocation($assetId, $target, $path, $targetInfo ?: [], true);
            }

            return $assetId;
        });
    }

    /**
     * @param array<string,mixed> $info
     */
    private function createLocation(int $assetId, string $driver, string $path, array $info, bool $isPrimary): int
    {
        if ($isPrimary) {
            Db::name('upload_asset_location')->where('asset_id', $assetId)->update(['is_primary' => 0]);
        }

        return (int) Db::name('upload_asset_location')->insertGetId([
            'asset_id' => $assetId,
            'driver' => $driver,
            'path' => $path,
            'url_prefix' => $this->resolveUrlPrefix($driver),
            'bucket' => (string) getSystemSetting($driver . '_bucket', ''),
            'region' => (string) (getSystemSetting($driver . '_region', '') ?: getSystemSetting($driver . '_endpoint', '')),
            'endpoint' => (string) getSystemSetting($driver . '_endpoint', ''),
            'is_primary' => $isPrimary ? 1 : 0,
            'status' => UploadAssetLocation::STATUS_ENABLED,
            'etag' => (string) ($info['etag'] ?? ''),
            'size' => (int) ($info['size'] ?? 0),
            'meta' => json_encode([
                'url' => $info['url'] ?? '',
                'full_url' => $info['full_url'] ?? '',
                'modified' => $info['modified'] ?? '',
                'migration_id' => $this->migrationId,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function switchPrimaryLocation(int $assetId, int $locationId): void
    {
        Db::transaction(function () use ($assetId, $locationId) {
            Db::name('upload_asset_location')->where('asset_id', $assetId)->update(['is_primary' => 0]);
            Db::name('upload_asset_location')
                ->where('id', $locationId)
                ->where('asset_id', $assetId)
                ->update(['is_primary' => 1, 'status' => UploadAssetLocation::STATUS_ENABLED]);
        });
    }

    /**
     * @param array<int,int|string> $ids
     */
    private function syncUsage(string $ownerType, int $ownerId, string $field, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        Db::name('upload_asset_usage')
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
        Db::name('upload_asset_usage')->insertAll($rows);
    }

    private function findMigration(int $id): UploadAssetMigration
    {
        $migration = (new UploadAssetMigration())->find($id);
        if ($migration === null) {
            throw new \RuntimeException('迁移任务不存在');
        }

        return $migration;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function updateMigration(UploadAssetMigration $migration, array $data): void
    {
        (new UploadAssetMigration())->where('id', (int) $migration->id)->update($data);
        foreach ($data as $key => $value) {
            $migration->{$key} = $value;
        }
    }

    private function findLocationId(int $assetId, string $driver, string $path): int
    {
        return (int) Db::name('upload_asset_location')
            ->where('asset_id', $assetId)
            ->where('driver', $driver)
            ->where('path', $path)
            ->where('status', UploadAssetLocation::STATUS_ENABLED)
            ->value('id');
    }

    private function downloadToTemp(object $driver, string $path): string
    {
        $tmpPath = runtime_path() . 'asset_migration/' . date('Ymd') . '/' . md5($path . microtime(true));
        if (!method_exists($driver, 'download') || !$driver->download($path, $tmpPath)) {
            throw new \RuntimeException('下载源对象失败');
        }

        return $tmpPath;
    }

    private function uploadDriver(string $driver): object
    {
        return DriverManager::driver('upload', $driver, $this->driverConfig($driver));
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

    private function localFullPath(string $path): string
    {
        $rootPath = (string) getSystemSetting('local_root_path', 'uploads');
        $root = str_starts_with($rootPath, '/') ? $rootPath : public_path() . $rootPath;
        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    private function legacyFullPath(string $path): string
    {
        $publicPath = public_path() . ltrim($path, '/');
        if (is_file($publicPath)) {
            return $publicPath;
        }

        $installStaticPath = $this->installStaticFullPath($path);
        if (is_file($installStaticPath)) {
            return $installStaticPath;
        }

        return $this->localFullPath($path);
    }

    private function legacySourceDriver(string $path): string
    {
        return is_file(public_path() . ltrim($path, '/')) || is_file($this->installStaticFullPath($path))
            ? UploadAssetLocation::DRIVER_STATIC
            : UploadAssetLocation::DRIVER_LOCAL;
    }

    private function installStaticFullPath(string $path): string
    {
        return app()->getRootPath() . 'install/' . ltrim($path, '/');
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
            'goods', 'goods_sku', 'goods_category', 'goods_brand', 'order_item' => UploadAssetCategory::CODE_GOODS,
            'goods_comment' => UploadAssetCategory::CODE_REVIEW,
            'user' => UploadAssetCategory::CODE_AVATAR,
            default => $type === UploadAsset::TYPE_IMAGE ? UploadAssetCategory::CODE_OTHER : UploadAssetCategory::CODE_OTHER,
        };

        $id = (int) Db::name('upload_asset_category')->where('code', $code)->value('id');
        return $id > 0 ? $id : 6;
    }

    private function isAssetId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }
        if (!is_string($value)) {
            return false;
        }

        return ctype_digit(trim($value)) && (int) trim($value) > 0;
    }

    /**
     * @return array<int,int>
     */
    private function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_unique(array_map('intval', $value)), static fn(int $id): bool => $id > 0));
    }

    private function batchSize(array $options): int
    {
        return max(1, min(500, (int) ($options['batch_size'] ?? 100)));
    }

    private function fileInfoFromLocal(string $path, string $tmpPath, object $driver): array
    {
        return [
            'name' => basename($path),
            'path' => $path,
            'url' => method_exists($driver, 'getUrl') ? $driver->getUrl($path) : '',
            'full_url' => method_exists($driver, 'getFullUrl') ? $driver->getFullUrl($path) : '',
            'size' => is_file($tmpPath) ? (int) filesize($tmpPath) : 0,
            'mime' => is_file($tmpPath) ? (string) (mime_content_type($tmpPath) ?: '') : '',
            'modified' => date('Y-m-d H:i:s'),
        ];
    }
}
