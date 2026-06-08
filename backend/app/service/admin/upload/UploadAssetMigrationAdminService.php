<?php
declare(strict_types=1);

namespace app\service\admin\upload;

use app\job\UploadAssetMigrationJob;
use app\model\upload\UploadAssetMigration;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\queue\JobQueue;

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

        $id = (int) $this->transaction(function () use ($data, $source, $target) {
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
                'options' => is_array($data['options'] ?? null) ? $data['options'] : [],
            ]);

            return (int) $migration->id;
        });

        $this->dispatch($id);
        return $id;
    }

    public function retry(int $id): bool
    {
        $migration = $this->findMigration($id);
        if (!in_array((int) $migration->status, [UploadAssetMigration::STATUS_PENDING, UploadAssetMigration::STATUS_FAILED], true)) {
            throw new BusinessException('当前任务状态不能重试');
        }

        $migration->save([
            'status' => UploadAssetMigration::STATUS_PENDING,
            'last_error' => '',
        ]);
        $this->dispatch($id);

        return true;
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

    private function dispatch(int $id): void
    {
        JobQueue::push(UploadAssetMigrationJob::class, ['migration_id' => $id]);
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
        return strtoupper($source) . ' -> ' . strtoupper($target);
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
