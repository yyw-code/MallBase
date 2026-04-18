<?php
declare(strict_types=1);

namespace app\model\user;

use mall_base\base\BaseModel;

/**
 * 用户标签关联模型
 */
class UserTagRelation extends BaseModel
{
    protected $name = 'user_tag_relation';

    /**
     * 关联标签表
     */
    public function tag()
    {
        return $this->belongsTo(UserTag::class, 'tag_id', 'id');
    }
}
