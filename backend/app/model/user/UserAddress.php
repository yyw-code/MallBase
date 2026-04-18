<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户收货地址模型
 */
class UserAddress extends BaseModel
{
    protected $name = 'user_address';
    protected $deleteTime = 'delete_time';
}
