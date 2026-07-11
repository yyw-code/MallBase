<?php
declare(strict_types=1);

namespace app\model\marketing;

use mall_base\base\BaseModel;

/**
 * 积分兑换商品
 */
class PointsGoods extends BaseModel
{
    protected $name = 'points_goods';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
