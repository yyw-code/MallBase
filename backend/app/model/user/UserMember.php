<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户会员账户
 */
class UserMember extends BaseModel
{
    protected $name = 'user_member';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
