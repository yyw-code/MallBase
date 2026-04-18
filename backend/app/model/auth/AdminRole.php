<?php

declare (strict_types=1);

namespace app\model\auth;

use mall_base\base\BaseModel;

/**
 * 管理员角色关联模型
 */
class AdminRole extends BaseModel
{
    protected $name = 'admin_role';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'create_time';

    /**
     * 获取管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 获取角色
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }
}