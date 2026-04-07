<?php
declare(strict_types=1);

namespace app\admin\model\user;

use mall_base\base\BaseModel;

/**
 * 用户标签模型
 */
class UserTag extends BaseModel
{
    protected $name = 'user_tag';

    /**
     * 搜索器-按名称搜索
     */
    public function searchNameAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('name', '%' . $value . '%');
        }
    }

    /**
     * 搜索器-按状态搜索
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }
}
