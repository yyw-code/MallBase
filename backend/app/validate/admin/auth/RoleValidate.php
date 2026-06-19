<?php

declare (strict_types=1);

namespace app\validate\admin\auth;

use think\Validate;

/**
 * 角色验证器
 */
class RoleValidate extends Validate
{
    /**
     * 验证规则
     *
     * @var array
     */
    protected $rule = [
        'name|角色名称' => 'require|max:50',
        'code|角色编码' => 'require|alphaNum|max:30|min:2',
        'status|状态' => 'in:0,1',
        'sort|排序' => 'number|between:0,9999',
        'remark|备注' => 'max:500',
        'menu_permission_ids|菜单权限ID' => 'array',
        'button_permission_ids|按钮权限ID' => 'array',
    ];

    /**
     * 验证场景
     *
     * @var array
     */
    protected $scene = [
        'create' => ['name', 'code', 'status', 'sort', 'remark', 'menu_permission_ids', 'button_permission_ids'],
        'update' => ['name', 'code', 'status', 'sort', 'remark', 'menu_permission_ids', 'button_permission_ids'],
    ];
}
