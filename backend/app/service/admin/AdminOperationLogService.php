<?php

declare (strict_types=1);

namespace app\service\admin;

use app\model\auth\AdminOperationLog;
use mall_base\base\BaseService;

/**
 * 操作日志服务
 * @extends BaseService<AdminOperationLog>
 */
class AdminOperationLogService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = AdminOperationLog::class;

    /**
     * 获取操作日志列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->buildListQuery($where);

        $total = (int) (clone $query)->count();
        $list = $query->with(['admin'])
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    protected function buildListQuery(array $where)
    {
        $query = $this->model();

        if (!empty($where['admin_id'])) {
            $query->where('admin_id', $where['admin_id']);
        }
        if (!empty($where['username'])) {
            $query->whereLike('username', "%{$where['username']}%");
        }
        if (!empty($where['path'])) {
            $query->whereLike('path', "%{$where['path']}%");
        }
        if (!empty($where['ip'])) {
            $query->whereLike('ip', "%{$where['ip']}%");
        }
        if (isset($where['status']) && $where['status'] !== '') {
            $query->where('status', $where['status']);
        }
        if (!empty($where['time_range'])) {
            $times = explode(' - ', $where['time_range']);
            if (count($times) === 2) {
                $query->whereBetweenTime('create_time', $times[0], $times[1]);
            }
        }

        return $query;
    }

    /**
     * 获取操作日志详情
     */
    public function getInfo(int $id): array
    {
        $log = $this->model()->with(['admin'])->find($id);

        if (!$log) {
            throw new \mall_base\exception\BusinessException('操作日志不存在', 5004);
        }

        return $log->toArray();
    }

    /**
     * 记录操作日志
     */
    public function log(
        int     $adminId,
        string  $username,
        ?string $nickname,
        string  $path,
        string  $method,
        ?array  $params,
        ?array  $response,
        int     $status,
        string  $ip,
        string  $userAgent,
        float   $duration
    ): int
    {
        $logData = [
            'admin_id' => $adminId,
            'username' => $username,
            'nickname' => $nickname,
            'path' => $path,
            'method' => $method,
            'params' => $params,
            'response' => $response,
            'status' => $status,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'duration' => $duration,
        ];

        $log = $this->model();
        $log->save($logData);
        return (int)$log->id;
    }

    /**
     * 删除操作日志
     */
    public function delete(int $id): bool
    {
        $log = $this->model()->find($id);
        if (!$log) {
            throw new \mall_base\exception\BusinessException('操作日志不存在', 5004);
        }

        return $log->delete();
    }

    /**
     * 批量删除操作日志
     */
    public function deleteBatch(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }

        return $this->model()->whereIn('id', $ids)->delete();
    }

    /**
     * 清空操作日志
     */
    public function clear(): bool
    {
        return $this->model()->delete(true);
    }

    /**
     * 获取操作统计
     */
    public function getStatistics(array $where = []): array
    {
        $query = $this->buildStatisticsQuery($where);

        // 总操作次数
        $total = (int) (clone $query)->count();

        // 成功次数
        $successCount = (clone $query)->where('status', 200)->count();

        // 失败次数
        $failCount = $total - $successCount;

        // 平均执行时间
        $avgDuration = (clone $query)->avg('duration');

        // 按日期统计
        $dailyStats = (clone $query)
            ->field('DATE(create_time) as date, COUNT(*) as count')
            ->group('DATE(create_time)')
            ->order('date', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        return [
            'total' => $total,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'avg_duration' => round($avgDuration, 2),
            'daily_stats' => $dailyStats,
        ];
    }

    protected function buildStatisticsQuery(array $where)
    {
        $query = $this->model();

        if (!empty($where['start_time'])) {
            $query->where('create_time', '>=', $where['start_time']);
        }
        if (!empty($where['end_time'])) {
            $query->where('create_time', '<=', $where['end_time']);
        }

        return $query;
    }
}
