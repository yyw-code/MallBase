<?php

declare (strict_types=1);

namespace app\validate\admin\auth;

use think\Validate;

/**
 * 权限验证器
 */
class PermissionValidate extends Validate
{
    /**
     * 验证规则
     *
     * @var array
     */
    protected $rule = [
        'parent_id|父级ID' => 'number|egt:0',
        'name|权限名称' => 'require|max:50',
        'code|权限编码' => 'require|alphaNum|max:50|min:2',
        'type|权限类型' => 'in:1,2,3',
        'path|路径' => 'max:255',
        'icon|图标' => 'max:100',
        'component|组件' => 'max:255',
        'sort|排序' => 'number|between:0,9999',
        'status|状态' => 'in:0,1',
        'is_show|显示状态' => 'in:0,1',
        'remark|备注' => 'max:500',
    ];

    /**
     * 验证场景
     *
     * @var array
     */
    protected $scene = [
        'create' => ['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'sort', 'status', 'is_show', 'remark'],
        'update' => ['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'sort', 'status', 'is_show', 'remark'],
    ];
}