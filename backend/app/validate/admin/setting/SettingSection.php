<?php

declare (strict_types=1);

namespace app\validate\admin\setting;

use think\Validate;

/**
 * 设置页内分组验证器
 */
class SettingSection extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'group_id|设置分组' => 'require|number',
        'name|页内分组名称' => 'require|max:64',
        'code|页内分组编码' => 'require|alphaDash|max:64',
        'sort|排序' => 'number|between:0,9999',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['group_id', 'name', 'code', 'sort'],
        'update' => ['name', 'code', 'sort'],
    ];
}
