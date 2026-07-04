<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户会员成长值流水
 */
class UserMemberGrowthLog extends BaseModel
{
    protected $name = 'user_member_growth_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';

    public const DIRECTION_INCOME = 'income';
    public const DIRECTION_EXPENSE = 'expense';

    public const BIZ_ORDER_COMPLETE = 'order_complete';
    public const BIZ_ADMIN_ADJUST = 'admin_adjust';

    public static function bizTypeText(string $bizType): string
    {
        return match ($bizType) {
            self::BIZ_ORDER_COMPLETE => '订单完成成长值',
            self::BIZ_ADMIN_ADJUST => '后台调整',
            default => $bizType === '' ? '未知业务' : $bizType,
        };
    }
}
