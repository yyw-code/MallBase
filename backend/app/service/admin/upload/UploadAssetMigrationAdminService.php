<?php
declare(strict_types=1);

namespace app\service\admin\upload;

use app\job\UploadAssetMigrationJob;
use app\model\upload\UploadAssetMigration;
use app\model\upload\UploadAssetMigrationLog;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Queue;
use Throwable;

/**
 * 后台素材迁移任务服务。
 *
 * @extends BaseService<UploadAssetMigration>
 */
class UploadAssetMigrationAdminService extends BaseService
{
    protected string $modelClass = UploadAssetMigration::class;

    private const DRIVERS = ['legacy_local', 'local', 'oss', 'cos'];

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['status'] ?? '') !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            })
            ->when(($where['source_driver'] ?? '') !== '', function ($q) use ($where) {
                $q->where('source_driver', (string) $where['source_driver']);
            })
            ->when(($where['target_driver'] ?? '') !== '', function ($q) use ($where) {
                $q->where('target_driver', (string) $where['target_driver']);
            });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    public function create(array $data): int
    {
        $source = trim((string) ($data['source_driver'] ?? ''));
        $target = trim((string) ($data['target_driver'] ?? ''));
        $this->validateDrivers($source, $target);
        $options = $this->normalizeOptions($source, $data['options'] ?? []);

        $id = (int) $this->transaction(function () use ($data, $source, $target, $options) {
            $migration = $this->model();
            $migration->save([
                'name' => trim((string) ($data['name'] ?? '')) ?: $this->defaultName($source, $target),
                'source_driver' => $source,
                'target_driver' => $target,
                'status' => UploadAssetMigration::STATUS_PENDING,
                'total' => 0,
                'success_count' => 0,
                'fail_count' => 0,
                'last_error' => '',
                'options' => $options,
            ]);

            return (int) $migration->id;
        });

        $this->dispatchOrMarkFailed($id, false);
        return $id;
    }

    public function retry(int $id): bool
    {
        $migration = $this->findMigration($id);
        $canRetry = in_array((int) $migration->status, [UploadAssetMigration::STATUS_PENDING, UploadAssetMigration::STATUS_FAILED], true)
            || $this->isStaleProcessing($migration);
        if (!$canRetry) {
            throw new BusinessException('当前任务状态不能重试');
        }

        $migration->save([
            'status' => UploadAssetMigration::STATUS_PENDING,
            'total' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'last_error' => '',
        ]);
        $this->dispatchOrMarkFailed($id, true);

        return true;
    }

    private function isStaleProcessing(UploadAssetMigration $migration): bool
    {
        if ((int) $migration->status !== UploadAssetMigration::STATUS_PROCESSING) {
            return false;
        }

        $updateTime = strtotime((string) $migration->update_time);
        return $updateTime > 0 && $updateTime <= time() - 600;
    }

    public function markProcessing(int $id): UploadAssetMigration
    {
        $migration = $this->findMigration($id);
        $migration->save(['status' => UploadAssetMigration::STATUS_PROCESSING]);
        return $migration;
    }

    public function markDone(int $id, int $total, int $successCount, int $failCount, string $lastError = ''): void
    {
        $migration = $this->findMigration($id);
        $migration->save([
            'status' => $failCount > 0 ? UploadAssetMigration::STATUS_FAILED : UploadAssetMigration::STATUS_DONE,
            'total' => $total,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'last_error' => mb_substr($lastError, 0, 1000),
        ]);
    }

    public function markFailed(int $id, string $error): void
    {
        $migration = $this->findMigration($id);
        $migration->save([
            'status' => UploadAssetMigration::STATUS_FAILED,
            'fail_count' => max(1, (int) $migration->fail_count),
            'last_error' => mb_substr($error, 0, 1000),
        ]);
    }

    public function cleanupDone(int $keepDays = 30): int
    {
        $keepDays = max(1, $keepDays);
        $expireAt = date('Y-m-d H:i:s', time() - $keepDays * 86400);

        return (int) $this->model()
            ->where('status', UploadAssetMigration::STATUS_DONE)
            ->where('update_time', '<=', $expireAt)
            ->delete();
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getLogs(array $where, int $page, int $limit): array
    {
        $total = $this->buildLogQuery($where)->count();
        $list = $this->buildLogQuery($where)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    protected function buildLogQuery(array $where)
    {
        return $this->model(UploadAssetMigrationLog::class)
            ->when(($where['migration_id'] ?? '') !== '', function ($q) use ($where) {
                $q->where('migration_id', (int) $where['migration_id']);
            })
            ->when(($where['status'] ?? '') !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            })
            ->when(($where['keyword'] ?? '') !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->where(function ($subQuery) use ($keyword) {
                    $subQuery->whereLike('source_path|target_path|message|error_message', "%{$keyword}%");
                    if (ctype_digit($keyword)) {
                        $subQuery->whereOr('asset_id', (int) $keyword);
                    }
                });
            });
    }

    /**
     * @param mixed $rawOptions
     * @return array<string,mixed>
     */
    private function normalizeOptions(string $source, mixed $rawOptions): array
    {
        $options = is_array($rawOptions) ? $rawOptions : [];
        $options['delete_source_after_success'] = $source !== 'legacy_local'
            && !empty($options['delete_source_after_success']);

        return $options;
    }

    private function dispatchOrMarkFailed(int $id, bool $throw): void
    {
        try {
            $this->dispatch($id);
        } catch (Throwable $e) {
            $message = '迁移任务入队失败：' . $e->getMessage();
            $this->markFailed($id, $message);
            if ($throw) {
                throw new BusinessException($message);
            }
        }
    }

    private function dispatch(int $id): void
    {
        $connection = $this->migrationQueueConnection();
        Queue::connection($connection)->push(
            UploadAssetMigrationJob::class,
            ['migration_id' => $id],
            UploadAssetMigrationJob::queueName()
        );
    }

    private function migrationQueueConnection(): string
    {
        $connection = trim((string) env('UPLOAD_ASSET_MIGRATION_QUEUE_CONNECTION', ''));
        if ($connection === '') {
            $default = trim((string) config('queue.default', 'sync'));
            $connection = $default === 'sync' ? 'redis' : $default;
        }
        if ($connection === 'sync') {
            throw new BusinessException('素材迁移不能使用 sync 队列连接，请改用 redis 或 database 队列');
        }

        return $connection;
    }

    private function validateDrivers(string $source, string $target): void
    {
        if (!in_array($source, self::DRIVERS, true) || !in_array($target, self::DRIVERS, true)) {
            throw new BusinessException('迁移驱动不支持');
        }
        if ($source === $target) {
            throw new BusinessException('源存储和目标存储不能相同');
        }
        if ($target === 'legacy_local') {
            throw new BusinessException('legacy_local 只能作为旧数据来源');
        }
    }

    private function defaultName(string $source, string $target): string
    {
        return $this->driverLabel($source) . ' -> ' . $this->driverLabel($target);
    }

    private function driverLabel(string $driver): string
    {
        return match ($driver) {
            'legacy_local' => '历史图片路径',
            'local' => '本地',
            'oss' => '阿里云 OSS',
            'cos' => '腾讯云 COS',
            default => strtoupper($driver),
        };
    }

    private function findMigration(int $id): UploadAssetMigration
    {
        $migration = $this->model()->find($id);
        if ($migration === null) {
            throw new BusinessException('迁移任务不存在');
        }

        return $migration;
    }
}
