<?php
declare(strict_types=1);

namespace app\model\marketing;

use mall_base\base\BaseModel;

/**
 * 积分兑换单操作日志
 */
class PointsExchangeOrderLog extends BaseModel
{
    protected $name = 'points_exchange_order_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public const ACTION_CREATE = 'create';
    public const ACTION_BUYER_CANCEL = 'buyer_cancel';
    public const ACTION_ADMIN_CLOSE = 'admin_close';
    public const ACTION_SYSTEM_CLOSE = 'system_close';
    public const ACTION_SHIP = 'ship';
    public const ACTION_COMPLETE = 'complete';

    public static function actionText(string $action): string
    {
        return match ($action) {
            self::ACTION_CREATE => '创建兑换单',
            self::ACTION_BUYER_CANCEL => '用户取消',
            self::ACTION_ADMIN_CLOSE => '后台关闭',
            self::ACTION_SYSTEM_CLOSE => '系统关闭',
            self::ACTION_SHIP => '后台发货',
            self::ACTION_COMPLETE => '后台完成',
            default => $action === '' ? '未知操作' : $action,
        };
    }
}
