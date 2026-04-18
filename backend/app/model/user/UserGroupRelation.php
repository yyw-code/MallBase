<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户分组关联模型
 */
class UserGroupRelation extends BaseModel
{
    protected $name = 'user_group_relation';

    /**
     * 关联分组表
     */
    public function group()
    {
        return $this->belongsTo(UserGroup::class, 'group_id', 'id');
    }
}
