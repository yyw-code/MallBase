<?php

declare (strict_types=1);

namespace app\model\auth;

use mall_base\base\BaseModel;

/**
 * 角色权限关联模型
 */
class RolePermission extends BaseModel
{
    protected $name = 'role_permission';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'create_time';

    /**
     * 获取角色
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    /**
     * 获取权限
     */
    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'id');
    }
}