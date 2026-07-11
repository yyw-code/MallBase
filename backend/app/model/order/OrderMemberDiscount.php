<?php
declare(strict_types=1);

namespace app\model\order;

use mall_base\base\BaseModel;

/**
 * 订单会员优惠快照
 */
class OrderMemberDiscount extends BaseModel
{
    protected $name = 'order_member_discount';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
