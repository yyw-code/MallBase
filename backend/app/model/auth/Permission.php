<?php

declare (strict_types=1);

namespace app\model\auth;

use mall_base\base\BaseModel;

/**
 * 权限模型
 */
class Permission extends BaseModel
{
    /**
     * 权限类型：菜单
     */
    const TYPE_MENU = 1;

    /**
     * 权限类型：按钮
     */
    const TYPE_BUTTON = 2;

    /**
     * 权限类型：接口
     */
    const TYPE_API = 3;

    /**
     * 来源：手动添加
     */
    const SOURCE_MANUAL = 1;

    /**
     * 来源：路由同步
     */
    const SOURCE_ROUTE = 2;

    /**
     * 来源：设置模块同步
     */
    const SOURCE_SETTING = 3;

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