<?php
declare(strict_types=1);

namespace app\validate\admin\marketing;

use think\Validate;

/**
 * 会员等级验证器
 */
class MemberLevelValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:50',
        'growth_min' => 'integer|egt:0',
        'discount_percent' => 'float|egt:0|elt:100',
        'sort' => 'integer',
        'status' => 'in:0,1',
        'remark' => 'max:255',
    ];

    protected $message = [
        'name.require' => '等级名称不能为空',
        'name.max' => '等级名称最多50个字符',
        'growth_min.integer' => '成长值门槛必须是整数',
        'growth_min.egt' => '成长值门槛不能小于0',
        'discount_percent.float' => '等级折扣必须是数字',
        'discount_percent.egt' => '等级折扣不能小于0',
        'discount_percent.elt' => '等级折扣不能大于100',
        'sort.integer' => '排序必须是整数',
        'status.in' => '状态必须是0或1',
        'remark.max' => '备注最多255个字符',
    ];

    protected $scene = [
        'create' => ['name', 'growth_min', 'discount_percent', 'sort', 'status', 'remark'],
        'update' => ['name', 'growth_min', 'discount_percent', 'sort', 'status', 'remark'],
    ];
}
