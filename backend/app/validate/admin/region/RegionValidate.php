<?php
declare(strict_types=1);

namespace app\validate\admin\region;

use think\Validate;

class RegionValidate extends Validate
{
    protected $rule = [
        'parent_id' => 'require|integer|min:0',
        'code' => 'require|max:20',
        'name' => 'require|max:100',
        'level' => 'require|in:1,2,3,4',
        'status' => 'in:0,1',
        'sort' => 'integer|min:0',
    ];

    protected $message = [
        'parent_id.require' => '父级ID不能为空',
        'code.require' => '地区编码不能为空',
        'name.require' => '地区名称不能为空',
        'level.require' => '地区层级不能为空',
        'level.in' => '地区层级不正确',
    ];

    protected $scene = [
        'create' => ['parent_id', 'code', 'name', 'level', 'status', 'sort'],
        'update' => ['parent_id', 'code', 'name', 'level', 'status', 'sort'],
        'status' => ['status'],
    ];
}
