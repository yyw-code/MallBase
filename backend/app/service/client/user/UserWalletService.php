<?php
declare(strict_types=1);

namespace app\service\client\user;

use app\model\user\UserWallet;
use app\model\user\UserWalletLog;
use mall_base\base\BaseService;

/**
 * 前台用户余额服务
 *
 * @extends BaseService<UserWallet>
 */
class UserWalletService extends BaseService
{
    protected string $modelClass = UserWallet::class;

    /**
     * @return array{balance:string,frozen_amount:string,total_recharge:string,total_consume:string,month_consume:string}
     */
    public function info(int $userId): array
    {
        $wallet = $this->ensureWallet($userId);

        $monthStart = date('Y-m-01 00:00:00');
        $monthConsumeCents = (int) $this->model(UserWalletLog::class)
            ->where('user_id', $userId)
            ->where('direction', UserWalletLog::DIRECTION_EXPENSE)
            ->where('create_time', '>=', $monthStart)
            ->sum('change_cents');

        return [
            'balance'        => $this->centsToYuan((int) $wallet->balance_cents),
            'frozen_amount'  => $this->centsToYuan((int) $wallet->frozen_cents),
            'total_recharge' => $this->centsToYuan((int) $wallet->total_recharge_cents),
            'total_consume'  => $this->centsToYuan((int) $wallet->total_consume_cents),
            'month_consume'  => $this->centsToYuan($monthConsumeCents),
        ];
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function logs(int $userId, array $where, int $page, int $limit): array
    {
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

    protected function buildLogListQuery(int $userId, array $where)
    {
        $direction = (string) ($where['type'] ?? '');
        $bizType = (string) ($where['biz_type'] ?? '');
        $range = (string) ($where['range'] ?? 'month');
        $startTime = $this->rangeStartTime($range);

        return $this->model(UserWalletLog::class)
            ->where('user_id', $userId)
            ->when(in_array($direction, [UserWalletLog::DIRECTION_INCOME, UserWalletLog::DIRECTION_EXPENSE], true), function ($q) use ($direction) {
                $q->where('direction', $direction);
            })
            ->when($bizType !== '', function ($q) use ($bizType) {
                $q->where('biz_type', $bizType);
            })
            ->when($startTime !== null, function ($q) use ($startTime) {
                $q->where('create_time', '>=', $startTime);
            });
    }

    public function ensureWallet(int $userId): UserWallet
    {
        /** @var UserWallet|null $wallet */
        $wallet = $this->model()->where('user_id', $userId)->find();
        if ($wallet !== null) {
            return $wallet;
        }

        /** @var UserWallet $created */
        $created = $this->model();
        $created->save([
            'user_id' => $userId,
            'balance_cents' => 0,
            'frozen_cents' => 0,
            'total_recharge_cents' => 0,
            'total_consume_cents' => 0,
        ]);

        return $created;
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
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatLog(array $row): array
    {
        $changeCents = (int) ($row['change_cents'] ?? 0);
        $direction = (string) ($row['direction'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => $this->titleOf((string) ($row['biz_type'] ?? '')),
            'biz_type' => (string) ($row['biz_type'] ?? ''),
            'biz_type_text' => UserWalletLog::bizTypeText((string) ($row['biz_type'] ?? '')),
            'biz_id' => (string) ($row['biz_id'] ?? ''),
            'direction' => $direction,
            'change_amount' => $this->centsToYuan($changeCents),
            'before_amount' => $this->centsToYuan((int) ($row['before_cents'] ?? 0)),
            'after_amount' => $this->centsToYuan((int) ($row['after_cents'] ?? 0)),
            'operator_type' => (int) ($row['operator_type'] ?? 0),
            'operator_id' => isset($row['operator_id']) ? (int) $row['operator_id'] : null,
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function titleOf(string $bizType): string
    {
        return UserWalletLog::bizTypeText($bizType);
    }

    private function centsToYuan(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
