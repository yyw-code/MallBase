<?php
declare(strict_types=1);

namespace app\model\order;

use mall_base\base\BaseModel;

/**
 * 订单项积分赠送快照
 */
class OrderPointsRewardItem extends BaseModel
{
    protected $name = 'order_points_reward_item';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
