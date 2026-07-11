<?php
declare(strict_types=1);

namespace app\model\distribution;

use mall_base\base\BaseModel;

class DistributionCommissionLog extends BaseModel
{
    public const DIRECTION_INCOME = 'income';
    public const DIRECTION_EXPENSE = 'expense';

    public const ACCOUNT_FROZEN = 'frozen';
    public const ACCOUNT_AVAILABLE = 'available';
    public const ACCOUNT_PENDING = 'pending';
    public const ACCOUNT_DEBT = 'debt';
    public const ACCOUNT_WITHDRAWN = 'withdrawn';

    public const BIZ_ORDER_FROZEN = 'order_frozen';
    public const BIZ_ORDER_SETTLE = 'order_settle';
    public const BIZ_REFUND_RECOVER = 'refund_recover';
    public const BIZ_ADMIN_ADJUST = 'admin_adjust';
    public const BIZ_WITHDRAW_APPLY = 'withdraw_apply';
    public const BIZ_WITHDRAW_APPROVE = 'withdraw_approve';
    public const BIZ_WITHDRAW_REJECT = 'withdraw_reject';
    public const BIZ_INVITE_REWARD = 'invite_reward';

    protected $name = 'distribution_commission_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    public static function bizTypeText(string $bizType): string
    {
        return match ($bizType) {
            self::BIZ_ORDER_FROZEN => '订单佣金冻结',
            self::BIZ_ORDER_SETTLE => '订单佣金结算',
            self::BIZ_REFUND_RECOVER => '售后佣金扣回',
            self::BIZ_ADMIN_ADJUST => '后台调整',
            self::BIZ_WITHDRAW_APPLY => '申请提现',
            self::BIZ_WITHDRAW_APPROVE => '提现通过',
            self::BIZ_WITHDRAW_REJECT => '提现驳回',
            self::BIZ_INVITE_REWARD => '固定邀请奖励',
            default => '佣金变动',
        };
    }
}
