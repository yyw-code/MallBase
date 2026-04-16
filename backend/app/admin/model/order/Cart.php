<?php
declare(strict_types=1);

namespace app\admin\model\order;

use mall_base\base\BaseModel;

/**
 * 购物车模型
 *
 * UNIQUE(user_id, sku_id, delete_time) 保证同 SKU 同用户有效行唯一，
 * CartService 的 add 走 UPSERT 语义（数量累加）
 */
class Cart extends BaseModel
{
    protected $name = 'cart';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';
}
