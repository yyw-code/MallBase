<?php
declare(strict_types=1);

namespace app\admin\validate\goods;

use think\Validate;

/**
 * 商品规格验证器
 */
class GoodsSpecValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:50',
        'description' => 'max:255',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
        'spec_id' => 'require|integer|gt:0',
        'value' => 'require|max:100',
        'values' => 'require|array',
    ];

    protected $message = [
        'name.require' => '规格名称不能为空',
        'name.max' => '规格名称最多50个字符',
        'description.max' => '规格描述最多255个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
        'spec_id.require' => '规格ID不能为空',
        'spec_id.integer' => '规格ID必须是整数',
        'spec_id.gt' => '规格ID必须大于0',
        'value.require' => '规格值不能为空',
        'value.max' => '规格值最多100个字符',
        'values.require' => '规格值列表不能为空',
        'values.array' => '规格值列表必须是数组',
    ];

    protected $scene = [
        'create' => ['name', 'description', 'sort', 'status'],
        'update' => ['name', 'description', 'sort', 'status'],
        'specValue' => ['spec_id', 'value'],
        'batchSpecValues' => ['spec_id', 'values'],
    ];
}
