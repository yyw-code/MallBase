<?php
declare(strict_types=1);

namespace app\model\order;

use mall_base\base\BaseModel;

/**
 * 订单积分赠送快照
 */
class OrderPointsReward extends BaseModel
{
    protected $name = 'order_points_reward';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const STATUS_FROZEN = 'frozen';
    public const STATUS_RELEASED = 'released';
    public const STATUS_RECOVERED = 'recovered';
}
