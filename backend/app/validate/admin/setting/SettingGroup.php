<?php

declare (strict_types=1);

namespace app\validate\admin\setting;

use think\Validate;

/**
 * 设置分组验证器
 */
class SettingGroup extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'parent_id|父级分组' => 'number|egt:0',
        'menu_parent_permission_id|父菜单权限ID' => 'number|egt:0',
        'name|分组名称' => 'require|max:100',
        'code|分组编码' => 'require|alphaNum|max:50',
        'icon|图标' => 'max:100',
        'description|分组描述' => 'max:255',
        'sort|排序' => 'number|between:0,9999',
        'display_type|展示方式' => 'in:category,page,tab',
        'status|状态' => 'in:0,1',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['parent_id', 'menu_parent_permission_id', 'name', 'code', 'icon', 'description', 'sort', 'display_type', 'status'],
        'update' => ['parent_id', 'menu_parent_permission_id', 'name', 'code', 'icon', 'description', 'sort', 'display_type', 'status'],
    ];
}