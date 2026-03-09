<?php

declare (strict_types=1);

namespace app\admin\model\auth;

use mall_base\base\BaseModel;

/**
 * 权限模型
 */
class Permission extends BaseModel
{
    protected $name = 'permission';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 获取子权限
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id')->order('sort', 'asc');
    }

    /**
     * 获取父权限
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }
}