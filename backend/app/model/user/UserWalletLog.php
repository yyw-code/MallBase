<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户余额流水
 */
class UserWalletLog extends BaseModel
{
    protected $name = 'user_wallet_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const DIRECTION_INCOME = 'income';
    public const DIRECTION_EXPENSE = 'expense';

    public const BIZ_ORDER_PAY = 'order_pay';
    public const BIZ_REFUND = 'refund';
    public const BIZ_RECHARGE = 'recharge';
    public const BIZ_ADMIN_ADJUST = 'admin_adjust';

    public static function bizTypeText(string $bizType): string
    {
        return match ($bizType) {
            self::BIZ_ORDER_PAY => '订单支付',
            self::BIZ_REFUND => '售后退款',
            self::BIZ_RECHARGE => '余额充值',
            self::BIZ_ADMIN_ADJUST => '后台调整',
            default => $bizType === '' ? '未知业务' : $bizType,
        };
    }
}
