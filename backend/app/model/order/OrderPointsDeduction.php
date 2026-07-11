<?php
declare(strict_types=1);

namespace app\model\order;

use mall_base\base\BaseModel;

/**
 * 订单积分抵扣记录
 */
class OrderPointsDeduction extends BaseModel
{
    protected $name = 'order_points_deduction';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const STATUS_USED = 'used';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_RETURNED = 'returned';
}
