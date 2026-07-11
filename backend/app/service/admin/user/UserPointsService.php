<?php
declare(strict_types=1);

namespace app\service\admin\user;

use app\model\user\User;
use app\model\user\UserPoints;
use app\model\user\UserPointsLog;
use app\service\user\UserPointsAccountService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台用户积分服务
 *
 * @extends BaseService<UserPoints>
 */
class UserPointsService extends BaseService
{
    protected string $modelClass = UserPoints::class;

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function logs(array $where, int $page, int $limit): array
    {
        $query = $this->buildLogListQuery($where);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row): array => $this->formatLog($row), $rows);

        return compact('total', 'list');
    }

    protected function buildLogListQuery(array $where)
    {
        $userId = (int) ($where['user_id'] ?? 0);
        $direction = (string) ($where['type'] ?? '');
        $bizType = (string) ($where['biz_type'] ?? '');

        return $this->model(UserPointsLog::class)
            ->when($userId > 0, function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->when(in_array($direction, [UserPointsLog::DIRECTION_INCOME, UserPointsLog::DIRECTION_EXPENSE], true), function ($q) use ($direction) {
                $q->where('direction', $direction);
            })
            ->when($bizType !== '', function ($q) use ($bizType) {
                $q->where('biz_type', $bizType);
            });
    }

    /**
     * @return array{balance_points:int}
     */
    public function adjust(int $userId, string $direction, int $points, string $remark, int $adminId): array
    {
        /** @var User|null $user */
        $user = $this->model(User::class)->where('id', $userId)->whereNull('delete_time')->find();
        if ($user === null) {
            throw new BusinessException('用户不存在');
        }

        return app()->make(UserPointsAccountService::class)
            ->adminAdjust($userId, $direction, $points, $remark, $adminId);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatLog(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'biz_type' => (string) ($row['biz_type'] ?? ''),
            'biz_type_text' => UserPointsLog::bizTypeText((string) ($row['biz_type'] ?? '')),
            'biz_id' => (string) ($row['biz_id'] ?? ''),
            'direction' => (string) ($row['direction'] ?? ''),
            'account_type' => (string) ($row['account_type'] ?? UserPointsLog::ACCOUNT_BALANCE),
            'account_type_text' => UserPointsLog::accountTypeText((string) ($row['account_type'] ?? UserPointsLog::ACCOUNT_BALANCE)),
            'change_points' => (int) ($row['change_points'] ?? 0),
            'before_points' => (int) ($row['before_points'] ?? 0),
            'after_points' => (int) ($row['after_points'] ?? 0),
            'operator_type' => (int) ($row['operator_type'] ?? 0),
            'operator_id' => isset($row['operator_id']) ? (int) $row['operator_id'] : null,
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }
}
