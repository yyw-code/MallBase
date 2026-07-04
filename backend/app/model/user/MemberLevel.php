<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 会员等级
 */
class MemberLevel extends BaseModel
{
    protected $name = 'member_level';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
