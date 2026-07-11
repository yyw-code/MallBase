<?php
declare(strict_types=1);

namespace app\service\distribution;

use app\common\enum\OperatorType;
use app\model\distribution\DistributionCommissionLog;
use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionLevel;
use app\model\user\User;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 分销员账户与佣金账本服务
 *
 * @extends BaseService<DistributionDistributor>
 */
class DistributionAccountService extends BaseService
{
    private const UINT_MAX_CENTS = 4_294_967_295;

    protected string $modelClass = DistributionDistributor::class;

    /**
     * 后台开通或重新启用分销员。
     */
    public function openDistributor(int $userId, int $levelId, int $adminId, string $remark = ''): int
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        return $this->doOpenDistributor($userId, $levelId, $adminId, 'admin', $remark);
    }

    public function autoOpenDistributor(int $userId, int $levelId, string $source, string $remark = ''): int
    {
        $source = trim($source);
        if ($source === '') {
            throw new BusinessException('开通来源不能为空');
        }
        return $this->doOpenDistributor($userId, $levelId, null, $source, $remark);
    }

    private function doOpenDistributor(int $userId, int $levelId, ?int $adminId, string $source, string $remark): int
    {
        if (!app()->make(DistributionConfigService::class)->isEnabled()) {
            throw new BusinessException('分销功能未开启');
        }
        $this->assertUserExists($userId);
        $this->assertLevelUsable($levelId);

        return (int) $this->transaction(function () use ($userId, $levelId, $adminId, $source, $remark): int {
            /** @var DistributionDistributor|null $account */
            $account = $this->model()
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            if ($account === null) {
                /** @var DistributionDistributor $created */
                $created = $this->model();
                $created->save([
                    'user_id' => $userId,
                    'level_id' => $levelId,
                    'invite_code' => $this->generateInviteCode($userId),
                    'status' => DistributionDistributor::STATUS_ENABLED,
                    'open_source' => mb_substr($source, 0, 32),
                    'opened_by' => $adminId,
                    'opened_at' => date('Y-m-d H:i:s'),
                    'remark' => mb_substr(trim($remark), 0, 255),
                ]);
                return (int) $created->id;
            }

            $account->level_id = $levelId;
            $account->status = DistributionDistributor::STATUS_ENABLED;
            $account->open_source = mb_substr($source, 0, 32);
            $account->opened_by = $adminId;
            $account->opened_at = date('Y-m-d H:i:s');
            $account->remark = mb_substr(trim($remark), 0, 255);
            if (trim((string) $account->invite_code) === '') {
                $account->invite_code = $this->generateInviteCode($userId);
            }
            $account->save();

            return (int) $account->id;
        });
    }

    public function updateStatus(int $userId, int $status, int $adminId): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        if (!in_array($status, [DistributionDistributor::STATUS_DISABLED, DistributionDistributor::STATUS_ENABLED], true)) {
            throw new BusinessException('状态不合法');
        }

        /** @var DistributionDistributor|null $account */
        $account = $this->model()->where('user_id', $userId)->find();
        if ($account === null) {
            throw new BusinessException('分销员不存在');
        }
        $account->status = $status;
        $account->save();
    }

    /**
     * @param array<int> $userIds
     * @return array<int, DistributionDistributor>
     */
    public function activeDistributorsByUserIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->model()
            ->whereIn('user_id', $ids)
            ->where('status', DistributionDistributor::STATUS_ENABLED)
            ->select();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->user_id] = $row;
        }
        return $map;
    }

    public function activeDistributorByInviteCode(string $inviteCode): ?DistributionDistributor
    {
        $code = strtoupper(trim($inviteCode));
        if ($code === '') {
            return null;
        }

        /** @var DistributionDistributor|null $account */
        $account = $this->model()
            ->where('invite_code', $code)
            ->where('status', DistributionDistributor::STATUS_ENABLED)
            ->find();

        return $account;
    }

    public function addFrozen(
        int $userId,
        int $amountCents,
        int $commissionId,
        string $bizId,
        string $remark
    ): void {
        $this->increaseAccount(
            userId: $userId,
            accountType: DistributionCommissionLog::ACCOUNT_FROZEN,
            amountCents: $amountCents,
            bizType: DistributionCommissionLog::BIZ_ORDER_FROZEN,
            bizId: $bizId,
            operatorType: OperatorType::SYSTEM,
            operatorId: null,
            remark: $remark,
            commissionId: $commissionId,
        );
    }

    public function settleFrozenToAvailable(int $userId, int $amountCents, int $commissionId, string $bizId): void
    {
        if ($amountCents <= 0) {
            return;
        }

        /** @var DistributionDistributor $account */
        $account = $this->lockDistributor($userId);
        $beforeFrozen = (int) $account->frozen_commission_cents;
        if ($beforeFrozen < $amountCents) {
            throw new BusinessException('冻结佣金余额不足');
        }
        $beforeAvailable = (int) $account->available_commission_cents;

        $account->frozen_commission_cents = $beforeFrozen - $amountCents;
        $account->available_commission_cents = $beforeAvailable + $amountCents;
        $this->assertCapacity((int) $account->available_commission_cents, 0, '可提现佣金超出系统限制');
        $this->assertCapacity((int) $account->total_commission_cents, $amountCents, '累计佣金超出系统限制');
        $account->total_commission_cents = (int) $account->total_commission_cents + $amountCents;
        $account->save();

        $this->createLog($userId, $commissionId, null, DistributionCommissionLog::BIZ_ORDER_SETTLE, $bizId, DistributionCommissionLog::ACCOUNT_FROZEN, DistributionCommissionLog::DIRECTION_EXPENSE, $amountCents, $beforeFrozen, (int) $account->frozen_commission_cents, OperatorType::SYSTEM, null, '订单佣金解除冻结');
        $this->createLog($userId, $commissionId, null, DistributionCommissionLog::BIZ_ORDER_SETTLE, $bizId, DistributionCommissionLog::ACCOUNT_AVAILABLE, DistributionCommissionLog::DIRECTION_INCOME, $amountCents, $beforeAvailable, (int) $account->available_commission_cents, OperatorType::SYSTEM, null, '订单佣金结算为可提现');
    }

    public function recoverFromFrozenOrDebt(int $userId, int $amountCents, int $commissionId, string $bizId): void
    {
        $this->decreaseAccountOrDebt(
            userId: $userId,
            accountType: DistributionCommissionLog::ACCOUNT_FROZEN,
            amountCents: $amountCents,
            bizType: DistributionCommissionLog::BIZ_REFUND_RECOVER,
            bizId: $bizId,
            commissionId: $commissionId,
        );
    }

    public function recoverFromAvailableOrDebt(int $userId, int $amountCents, int $commissionId, string $bizId): void
    {
        $this->decreaseAccountOrDebt(
            userId: $userId,
            accountType: DistributionCommissionLog::ACCOUNT_AVAILABLE,
            amountCents: $amountCents,
            bizType: DistributionCommissionLog::BIZ_REFUND_RECOVER,
            bizId: $bizId,
            commissionId: $commissionId,
        );
    }

    public function adjust(int $userId, string $direction, int $amountCents, string $remark, int $adminId): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        if (!in_array($direction, [DistributionCommissionLog::DIRECTION_INCOME, DistributionCommissionLog::DIRECTION_EXPENSE], true)) {
            throw new BusinessException('调整方向不合法');
        }
        if ($amountCents <= 0) {
            throw new BusinessException('调整金额必须大于 0');
        }
        if (trim($remark) === '') {
            throw new BusinessException('请填写调整原因');
        }

        if ($direction === DistributionCommissionLog::DIRECTION_INCOME) {
            $this->increaseAccount($userId, DistributionCommissionLog::ACCOUNT_AVAILABLE, $amountCents, DistributionCommissionLog::BIZ_ADMIN_ADJUST, $this->adjustNo(), OperatorType::ADMIN, $adminId, $remark);
            return;
        }

        $this->decreaseAccountOrDebt($userId, DistributionCommissionLog::ACCOUNT_AVAILABLE, $amountCents, DistributionCommissionLog::BIZ_ADMIN_ADJUST, $this->adjustNo(), null, OperatorType::ADMIN, $adminId, $remark, false);
    }

    public function addInviteReward(int $userId, int $amountCents, int $relationId, string $remark = '固定邀请奖励'): void
    {
        $this->increaseAccount(
            userId: $userId,
            accountType: DistributionCommissionLog::ACCOUNT_AVAILABLE,
            amountCents: $amountCents,
            bizType: DistributionCommissionLog::BIZ_INVITE_REWARD,
            bizId: 'DR-' . $relationId,
            operatorType: OperatorType::SYSTEM,
            operatorId: null,
            remark: $remark,
        );
    }

    public function holdWithdraw(int $userId, int $amountCents, int $withdrawId, string $withdrawSn): void
    {
        if ($amountCents <= 0) {
            throw new BusinessException('提现金额必须大于 0');
        }

        /** @var DistributionDistributor $account */
        $account = $this->lockDistributor($userId);
        $beforeAvailable = (int) $account->available_commission_cents;
        if ($beforeAvailable < $amountCents) {
            throw new BusinessException('可提现佣金不足');
        }
        $beforePending = (int) $account->pending_withdraw_cents;

        $account->available_commission_cents = $beforeAvailable - $amountCents;
        $account->pending_withdraw_cents = $beforePending + $amountCents;
        $account->save();

        $this->createLog($userId, null, $withdrawId, DistributionCommissionLog::BIZ_WITHDRAW_APPLY, $withdrawSn, DistributionCommissionLog::ACCOUNT_AVAILABLE, DistributionCommissionLog::DIRECTION_EXPENSE, $amountCents, $beforeAvailable, (int) $account->available_commission_cents, OperatorType::BUYER, $userId, '申请提现扣减可提现佣金');
        $this->createLog($userId, null, $withdrawId, DistributionCommissionLog::BIZ_WITHDRAW_APPLY, $withdrawSn, DistributionCommissionLog::ACCOUNT_PENDING, DistributionCommissionLog::DIRECTION_INCOME, $amountCents, $beforePending, (int) $account->pending_withdraw_cents, OperatorType::BUYER, $userId, '提现金额进入审核中');
    }

    public function approveWithdraw(int $userId, int $amountCents, int $withdrawId, string $withdrawSn, int $adminId): void
    {
        /** @var DistributionDistributor $account */
        $account = $this->lockDistributor($userId);
        $beforePending = (int) $account->pending_withdraw_cents;
        if ($beforePending < $amountCents) {
            throw new BusinessException('提现中金额不足');
        }
        $beforeWithdrawn = (int) $account->withdrawn_commission_cents;

        $account->pending_withdraw_cents = $beforePending - $amountCents;
        $account->withdrawn_commission_cents = $beforeWithdrawn + $amountCents;
        $account->save();

        $this->createLog($userId, null, $withdrawId, DistributionCommissionLog::BIZ_WITHDRAW_APPROVE, $withdrawSn, DistributionCommissionLog::ACCOUNT_PENDING, DistributionCommissionLog::DIRECTION_EXPENSE, $amountCents, $beforePending, (int) $account->pending_withdraw_cents, OperatorType::ADMIN, $adminId, '提现审核通过');
        $this->createLog($userId, null, $withdrawId, DistributionCommissionLog::BIZ_WITHDRAW_APPROVE, $withdrawSn, DistributionCommissionLog::ACCOUNT_WITHDRAWN, DistributionCommissionLog::DIRECTION_INCOME, $amountCents, $beforeWithdrawn, (int) $account->withdrawn_commission_cents, OperatorType::ADMIN, $adminId, '记录已提现佣金');
    }

    public function rejectWithdraw(int $userId, int $amountCents, int $withdrawId, string $withdrawSn, int $adminId): void
    {
        /** @var DistributionDistributor $account */
        $account = $this->lockDistributor($userId);
        $beforePending = (int) $account->pending_withdraw_cents;
        if ($beforePending < $amountCents) {
            throw new BusinessException('提现中金额不足');
        }
        $beforeAvailable = (int) $account->available_commission_cents;

        $account->pending_withdraw_cents = $beforePending - $amountCents;
        $account->available_commission_cents = $beforeAvailable + $amountCents;
        $account->save();

        $this->createLog($userId, null, $withdrawId, DistributionCommissionLog::BIZ_WITHDRAW_REJECT, $withdrawSn, DistributionCommissionLog::ACCOUNT_PENDING, DistributionCommissionLog::DIRECTION_EXPENSE, $amountCents, $beforePending, (int) $account->pending_withdraw_cents, OperatorType::ADMIN, $adminId, '提现审核驳回');
        $this->createLog($userId, null, $withdrawId, DistributionCommissionLog::BIZ_WITHDRAW_REJECT, $withdrawSn, DistributionCommissionLog::ACCOUNT_AVAILABLE, DistributionCommissionLog::DIRECTION_INCOME, $amountCents, $beforeAvailable, (int) $account->available_commission_cents, OperatorType::ADMIN, $adminId, '驳回提现退回可提现佣金');
    }

    public function lockDistributor(int $userId): DistributionDistributor
    {
        /** @var DistributionDistributor|null $account */
        $account = $this->model()
            ->where('user_id', $userId)
            ->lock(true)
            ->find();
        if ($account === null) {
            throw new BusinessException('分销员不存在');
        }
        return $account;
    }

    public function centsToAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function amountToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function increaseAccount(
        int $userId,
        string $accountType,
        int $amountCents,
        string $bizType,
        string $bizId,
        int $operatorType,
        ?int $operatorId,
        string $remark,
        ?int $commissionId = null,
        ?int $withdrawId = null
    ): void {
        if ($amountCents <= 0) {
            return;
        }

        $account = $this->lockDistributor($userId);
        $column = $this->columnOf($accountType);
        $before = (int) $account->{$column};
        $this->assertCapacity($before, $amountCents, '佣金账户金额超出系统限制');
        $after = $before + $amountCents;
        $account->{$column} = $after;
        $account->save();

        $this->createLog($userId, $commissionId, $withdrawId, $bizType, $bizId, $accountType, DistributionCommissionLog::DIRECTION_INCOME, $amountCents, $before, $after, $operatorType, $operatorId, $remark);
    }

    private function decreaseAccountOrDebt(
        int $userId,
        string $accountType,
        int $amountCents,
        string $bizType,
        string $bizId,
        ?int $commissionId = null,
        int $operatorType = OperatorType::SYSTEM,
        ?int $operatorId = null,
        string $remark = '佣金扣回',
        bool $allowDebt = true
    ): void {
        if ($amountCents <= 0) {
            return;
        }

        $account = $this->lockDistributor($userId);
        $column = $this->columnOf($accountType);
        $before = (int) $account->{$column};
        if (!$allowDebt && $before < $amountCents) {
            throw new BusinessException('佣金余额不足');
        }

        $deduct = min($before, $amountCents);
        if ($deduct > 0) {
            $after = $before - $deduct;
            $account->{$column} = $after;
            $account->save();
            $this->createLog($userId, $commissionId, null, $bizType, $bizId, $accountType, DistributionCommissionLog::DIRECTION_EXPENSE, $deduct, $before, $after, $operatorType, $operatorId, $remark);
        }

        $debt = $amountCents - $deduct;
        if ($debt <= 0) {
            return;
        }

        $beforeDebt = (int) $account->debt_commission_cents;
        $this->assertCapacity($beforeDebt, $debt, '待扣回佣金超出系统限制');
        $afterDebt = $beforeDebt + $debt;
        $account->debt_commission_cents = $afterDebt;
        $account->save();
        $this->createLog($userId, $commissionId, null, $bizType, $bizId, DistributionCommissionLog::ACCOUNT_DEBT, DistributionCommissionLog::DIRECTION_INCOME, $debt, $beforeDebt, $afterDebt, $operatorType, $operatorId, '佣金余额不足，记录待扣回金额');
    }

    private function createLog(
        int $userId,
        ?int $commissionId,
        ?int $withdrawId,
        string $bizType,
        string $bizId,
        string $accountType,
        string $direction,
        int $changeCents,
        int $beforeCents,
        int $afterCents,
        int $operatorType,
        ?int $operatorId,
        string $remark
    ): void {
        $this->model(DistributionCommissionLog::class)->save([
            'user_id' => $userId,
            'commission_id' => $commissionId,
            'withdraw_id' => $withdrawId,
            'biz_type' => $bizType,
            'biz_id' => mb_substr($bizId, 0, 64),
            'account_type' => $accountType,
            'direction' => $direction,
            'change_cents' => $changeCents,
            'before_cents' => $beforeCents,
            'after_cents' => $afterCents,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'remark' => mb_substr($remark, 0, 255),
        ]);
    }

    private function assertUserExists(int $userId): void
    {
        if ($userId <= 0) {
            throw new BusinessException('用户ID不能为空');
        }
        if ($this->model(User::class)->where('id', $userId)->whereNull('delete_time')->count() <= 0) {
            throw new BusinessException('用户不存在');
        }
    }

    private function assertLevelUsable(int $levelId): void
    {
        if ($levelId <= 0) {
            throw new BusinessException('分销员等级不能为空');
        }
        if ($this->model(DistributionLevel::class)->where('id', $levelId)->where('status', 1)->count() <= 0) {
            throw new BusinessException('分销员等级不存在或已禁用');
        }
    }

    private function generateInviteCode(int $userId): string
    {
        $base = 'D' . strtoupper(base_convert((string) $userId, 10, 36));
        for ($i = 0; $i < 5; $i++) {
            $code = mb_substr($base . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)), 0, 16);
            if ($this->model()->where('invite_code', $code)->count() === 0) {
                return $code;
            }
        }
        throw new BusinessException('邀请码生成失败，请重试');
    }

    private function columnOf(string $accountType): string
    {
        return match ($accountType) {
            DistributionCommissionLog::ACCOUNT_FROZEN => 'frozen_commission_cents',
            DistributionCommissionLog::ACCOUNT_AVAILABLE => 'available_commission_cents',
            DistributionCommissionLog::ACCOUNT_PENDING => 'pending_withdraw_cents',
            DistributionCommissionLog::ACCOUNT_DEBT => 'debt_commission_cents',
            DistributionCommissionLog::ACCOUNT_WITHDRAWN => 'withdrawn_commission_cents',
            default => throw new BusinessException('佣金账户类型不合法'),
        };
    }

    private function assertCapacity(int $currentCents, int $changeCents, string $message): void
    {
        if ($currentCents > self::UINT_MAX_CENTS - $changeCents) {
            throw new BusinessException($message);
        }
    }

    private function adjustNo(): string
    {
        return 'DADJ-' . date('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
