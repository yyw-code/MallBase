<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户积分流水
 */
class UserPointsLog extends BaseModel
{
    protected $name = 'user_points_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const DIRECTION_INCOME = 'income';
    public const DIRECTION_EXPENSE = 'expense';

    public const ACCOUNT_BALANCE = 'balance';
    public const ACCOUNT_FROZEN = 'frozen';
    public const ACCOUNT_DEBT = 'debt';

    public const BIZ_ORDER_COMPLETE = 'order_complete';
    public const BIZ_ORDER_REWARD_RELEASE = 'order_reward_release';
    public const BIZ_ORDER_REWARD_RELEASE_FROZEN = 'order_reward_release_frozen';
    public const BIZ_REFUND = 'refund';
    public const BIZ_REFUND_FROZEN = 'refund_frozen';
    public const BIZ_REFUND_DEBT = 'refund_debt';
    public const BIZ_ORDER_DEDUCTION = 'order_deduction';
    public const BIZ_ORDER_DEDUCTION_RETURN = 'order_deduction_return';
    public const BIZ_REFUND_DEDUCTION_RETURN = 'refund_deduction_return';
    public const BIZ_POINTS_EXCHANGE = 'points_exchange';
    public const BIZ_POINTS_EXCHANGE_RETURN = 'points_exchange_return';
    public const BIZ_ADMIN_ADJUST = 'admin_adjust';

    public static function bizTypeText(string $bizType): string
    {
        return match ($bizType) {
            self::BIZ_ORDER_COMPLETE => '订单奖励冻结',
            self::BIZ_ORDER_REWARD_RELEASE => '积分释放',
            self::BIZ_ORDER_REWARD_RELEASE_FROZEN => '冻结释放',
            self::BIZ_REFUND => '退款回收',
            self::BIZ_REFUND_FROZEN => '退款回收冻结',
            self::BIZ_REFUND_DEBT => '退款欠账',
            self::BIZ_ORDER_DEDUCTION => '订单积分抵扣',
            self::BIZ_ORDER_DEDUCTION_RETURN => '订单取消返还',
            self::BIZ_REFUND_DEDUCTION_RETURN => '退款返还抵扣积分',
            self::BIZ_POINTS_EXCHANGE => '积分商品兑换',
            self::BIZ_POINTS_EXCHANGE_RETURN => '兑换关闭返还',
            self::BIZ_ADMIN_ADJUST => '后台调整',
            default => $bizType === '' ? '未知业务' : $bizType,
        };
    }

    public static function accountTypeText(string $accountType): string
    {
        return match ($accountType) {
            self::ACCOUNT_BALANCE => '可用积分',
            self::ACCOUNT_FROZEN => '冻结积分',
            self::ACCOUNT_DEBT => '欠账积分',
            default => $accountType === '' ? '未知账户' : $accountType,
        };
    }
}
