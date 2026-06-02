<?php
declare(strict_types=1);

namespace app\service\admin\user;

use app\common\enum\OperatorType;
use app\model\user\User;
use app\model\user\UserWallet;
use app\model\user\UserWalletLog;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台用户余额服务
 *
 * @extends BaseService<UserWallet>
 */
class UserWalletService extends BaseService
{
    private const MAX_WALLET_CENTS = 99_999_999;
    private const UINT_MAX_CENTS = 4_294_967_295;

    protected string $modelClass = UserWallet::class;

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function logs(array $where, int $page, int $limit): array
    {
        $userId = (int) ($where['user_id'] ?? 0);
        $direction = (string) ($where['type'] ?? '');
        $bizType = (string) ($where['biz_type'] ?? '');

        $query = $this->model(UserWalletLog::class)
            ->when($userId > 0, function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->when(in_array($direction, [UserWalletLog::DIRECTION_INCOME, UserWalletLog::DIRECTION_EXPENSE], true), function ($q) use ($direction) {
                $q->where('direction', $direction);
            })
            ->when($bizType !== '', function ($q) use ($bizType) {
                $q->where('biz_type', $bizType);
            });

        $total = (int) $query->count();
        $rows = $query
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row): array => $this->formatLog($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array{balance:string}
     */
    public function adjust(int $userId, string $direction, string $amount, string $remark, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        if (!in_array($direction, [UserWalletLog::DIRECTION_INCOME, UserWalletLog::DIRECTION_EXPENSE], true)) {
            throw new BusinessException('调整方向不合法');
        }
        if (trim($remark) === '') {
            throw new BusinessException('请填写调整原因');
        }

        /** @var User|null $user */
        $user = $this->model(User::class)->where('id', $userId)->whereNull('delete_time')->find();
        if ($user === null) {
            throw new BusinessException('用户不存在');
        }

        $amountCents = $this->decimalToCents($amount);
        if ($amountCents <= 0) {
            throw new BusinessException('调整金额必须大于 0');
        }

        $after = 0;
        $this->transaction(function () use ($userId, $direction, $amountCents, $remark, $adminId, &$after): void {
            $wallet = $this->lockedWallet($userId);
            $before = (int) $wallet->balance_cents;

            if ($direction === UserWalletLog::DIRECTION_INCOME) {
                $after = $before + $amountCents;
                if ($after > self::MAX_WALLET_CENTS) {
                    throw new BusinessException('调整后余额不能超过 999999.99 元');
                }
                $this->assertCentsCapacity((int) $wallet->total_recharge_cents, $amountCents, '累计充值金额超出系统限制');
                $wallet->balance_cents = $after;
                $wallet->total_recharge_cents = (int) $wallet->total_recharge_cents + $amountCents;
            } else {
                if ($before < $amountCents) {
                    throw new BusinessException('用户余额不足，无法扣减');
                }
                $after = $before - $amountCents;
                $this->assertCentsCapacity((int) $wallet->total_consume_cents, $amountCents, '累计消费金额超出系统限制');
                $wallet->balance_cents = $after;
                $wallet->total_consume_cents = (int) $wallet->total_consume_cents + $amountCents;
            }
            $wallet->save();

            UserWalletLog::create([
                'user_id' => $userId,
                'wallet_id' => (int) $wallet->id,
                'biz_type' => UserWalletLog::BIZ_ADMIN_ADJUST,
                'biz_id' => $this->adjustNo(),
                'direction' => $direction,
                'change_cents' => $amountCents,
                'before_cents' => $before,
                'after_cents' => $after,
                'operator_type' => OperatorType::ADMIN,
                'operator_id' => $adminId,
                'remark' => mb_substr($remark, 0, 255),
            ]);
        });

        return ['balance' => $this->centsToYuan($after)];
    }

    private function lockedWallet(int $userId): UserWallet
    {
        /** @var UserWallet|null $wallet */
        $wallet = $this->model()->where('user_id', $userId)->lock(true)->find();
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
            'biz_type_text' => UserWalletLog::bizTypeText((string) ($row['biz_type'] ?? '')),
            'biz_id' => (string) ($row['biz_id'] ?? ''),
            'direction' => (string) ($row['direction'] ?? ''),
            'change_amount' => $this->centsToYuan((int) ($row['change_cents'] ?? 0)),
            'before_amount' => $this->centsToYuan((int) ($row['before_cents'] ?? 0)),
            'after_amount' => $this->centsToYuan((int) ($row['after_cents'] ?? 0)),
            'operator_type' => (int) ($row['operator_type'] ?? 0),
            'operator_id' => isset($row['operator_id']) ? (int) $row['operator_id'] : null,
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }

        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        $normalizedYuan = ltrim($yuan, '0');
        $normalizedYuan = $normalizedYuan === '' ? '0' : $normalizedYuan;
        if (strlen($normalizedYuan) > 6) {
            throw new BusinessException('调整金额不能超过 999999.99 元');
        }

        $cents = ((int) $normalizedYuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
        if ($cents > self::MAX_WALLET_CENTS) {
            throw new BusinessException('调整金额不能超过 999999.99 元');
        }

        return $cents;
    }

    private function assertCentsCapacity(int $currentCents, int $changeCents, string $message): void
    {
        if ($currentCents > self::UINT_MAX_CENTS - $changeCents) {
            throw new BusinessException($message);
        }
    }

    private function centsToYuan(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function adjustNo(): string
    {
        return 'ADJ-' . date('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
