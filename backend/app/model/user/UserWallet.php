<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户余额钱包
 */
class UserWallet extends BaseModel
{
    protected $name = 'user_wallet';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
