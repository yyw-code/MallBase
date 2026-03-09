<?php

declare (strict_types=1);

namespace app\admin\model\auth;

use mall_base\base\BaseModel;

/**
 * 角色模型
 */
class Role extends BaseModel
{
    protected $name = 'role';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
