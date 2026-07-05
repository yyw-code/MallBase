<?php
declare(strict_types=1);

namespace app\service\client\user;

use app\model\order\OrderPointsReward;
use app\model\user\UserPoints;
use app\model\user\UserPointsLog;
use app\service\user\UserPointsAccountService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 前台用户积分服务
 *
 * @extends BaseService<UserPoints>
 */
class UserPointsService extends BaseService
{
    protected string $modelClass = UserPoints::class;

    /**
     * @return array{balance_points:int,frozen_points:int,debt_points:int,total_income_points:int,total_expense_points:int,month_income_points:int,month_expense_points:int,frozen_rewards:array<int,array<string,mixed>>,next_release_time:?string}
     */
    public function info(int $userId): array
    {
        /** @var UserPointsAccountService $pointsService */
        $pointsService = app()->make(UserPointsAccountService::class);
        $this->assertEnabled($pointsService);
        $info = $pointsService->info($userId);

        $monthStart = date('Y-m-01 00:00:00');
        $monthIncomePoints = (int) $this->model(UserPointsLog::class)
            ->where('user_id', $userId)
            ->where('direction', UserPointsLog::DIRECTION_INCOME)
            ->where('account_type', UserPointsLog::ACCOUNT_BALANCE)
            ->where('create_time', '>=', $monthStart)
            ->sum('change_points');
        $monthExpensePoints = (int) $this->model(UserPointsLog::class)
            ->where('user_id', $userId)
            ->where('direction', UserPointsLog::DIRECTION_EXPENSE)
            ->where('account_type', UserPointsLog::ACCOUNT_BALANCE)
            ->where('create_time', '>=', $monthStart)
            ->sum('change_points');

        return array_merge($info, [
            'month_income_points' => $monthIncomePoints,
            'month_expense_points' => $monthExpensePoints,
            'frozen_rewards' => $this->frozenRewards($userId),
            'next_release_time' => $this->nextReleaseTime($userId),
        ]);
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function logs(int $userId, array $where, int $page, int $limit): array
    {
        $this->assertEnabled(app()->make(UserPointsAccountService::class));
        $query = $this->buildLogListQuery($userId, $where);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row): array => $this->formatLog($row), $rows);

        return compact('total', 'list');
    }

    private function assertEnabled(UserPointsAccountService $pointsService): void
    {
        if (!$pointsService->isPointsEnabled()) {
            throw new BusinessException('积分功能未开启');
        }
    }

    protected function buildLogListQuery(int $userId, array $where)
    {
        $direction = (string) ($where['type'] ?? '');
        $bizType = (string) ($where['biz_type'] ?? '');
        $range = (string) ($where['range'] ?? 'month');
        $startTime = $this->rangeStartTime($range);

        return $this->model(UserPointsLog::class)
            ->where('user_id', $userId)
            ->when(in_array($direction, [UserPointsLog::DIRECTION_INCOME, UserPointsLog::DIRECTION_EXPENSE], true), function ($q) use ($direction) {
                $q->where('direction', $direction);
            })
            ->when($bizType !== '', function ($q) use ($bizType) {
                $q->where('biz_type', $bizType);
            })
            ->when($startTime !== null, function ($q) use ($startTime) {
                $q->where('create_time', '>=', $startTime);
            });
    }

    private function rangeStartTime(string $range): ?string
    {
        return match ($range) {
            'month' => date('Y-m-01 00:00:00'),
            'three_months' => date('Y-m-d 00:00:00', strtotime('-3 months') ?: time()),
            default => null,
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function frozenRewards(int $userId): array
    {
        $rows = $this->model(OrderPointsReward::class)
            ->where('user_id', $userId)
            ->where('status', OrderPointsReward::STATUS_FROZEN)
            ->where('frozen_points', '>', 0)
            ->order('release_time', 'asc')
            ->limit(5)
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => [
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_sn' => (string) ($row['order_sn'] ?? ''),
            'reward_points' => (int) ($row['reward_points'] ?? 0),
            'frozen_points' => (int) ($row['frozen_points'] ?? 0),
            'released_points' => (int) ($row['released_points'] ?? 0),
            'release_time' => (string) ($row['release_time'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ], $rows);
    }

    private function nextReleaseTime(int $userId): ?string
    {
        $releaseTime = $this->model(OrderPointsReward::class)
            ->where('user_id', $userId)
            ->where('status', OrderPointsReward::STATUS_FROZEN)
            ->where('frozen_points', '>', 0)
            ->order('release_time', 'asc')
            ->value('release_time');

        return $releaseTime !== null && $releaseTime !== '' ? (string) $releaseTime : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatLog(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => UserPointsLog::bizTypeText((string) ($row['biz_type'] ?? '')),
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
