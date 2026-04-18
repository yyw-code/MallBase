<?php
declare(strict_types=1);

namespace app\validate\admin\goods;

use think\Validate;

/**
 * 商品规格模板验证器
 */
class GoodsSpecTemplateValidate extends Validate
{
    protected $rule = [
        'name'   => 'require|max:100',
        'detail' => 'require|array',
        'sort'   => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'name.require'   => '模板名称不能为空',
        'name.max'       => '模板名称最多100个字符',
        'detail.require' => '规格详情不能为空',
        'detail.array'   => '规格详情必须是数组',
        'sort.integer'   => '排序必须是整数',
        'sort.egt'       => '排序必须大于等于0',
        'status.in'      => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['name', 'detail', 'sort', 'status'],
        'update' => ['name', 'detail', 'sort', 'status'],
    ];
}
