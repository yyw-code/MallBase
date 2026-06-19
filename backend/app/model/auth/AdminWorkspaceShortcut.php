<?php

declare(strict_types=1);

namespace app\model\auth;

use mall_base\base\BaseModel;

/**
 * 管理员工作台快捷入口模型
 */
class AdminWorkspaceShortcut extends BaseModel
{
    protected $name = 'admin_workspace_shortcut';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
