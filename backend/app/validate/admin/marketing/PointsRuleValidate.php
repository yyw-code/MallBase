<?php
declare(strict_types=1);

namespace app\validate\admin\marketing;

use think\Validate;

/**
 * 积分规则验证器
 */
class PointsRuleValidate extends Validate
{
    protected $rule = [
        'scene' => 'require|in:order_complete,register,review',
        'name' => 'require|max:50',
        'description' => 'max:255',
        'points_per_yuan' => 'integer|egt:0',
        'fixed_points' => 'integer|egt:0',
        'max_points' => 'integer|egt:0',
        'sort' => 'integer',
        'status' => 'in:0,1',
        'remark' => 'max:255',
    ];

    protected $message = [
        'scene.require' => '规则场景不能为空',
        'scene.in' => '规则场景不合法',
        'name.require' => '规则名称不能为空',
        'name.max' => '规则名称最多50个字符',
        'description.max' => '规则说明最多255个字符',
        'points_per_yuan.integer' => '每元奖励积分必须是整数',
        'points_per_yuan.egt' => '每元奖励积分必须大于等于0',
        'fixed_points.integer' => '固定奖励积分必须是整数',
        'fixed_points.egt' => '固定奖励积分必须大于等于0',
        'max_points.integer' => '单次上限必须是整数',
        'max_points.egt' => '单次上限必须大于等于0',
        'sort.integer' => '排序必须是整数',
        'status.in' => '状态必须是0或1',
        'remark.max' => '备注最多255个字符',
    ];

    protected $scene = [
        'create' => ['scene', 'name', 'description', 'points_per_yuan', 'fixed_points', 'max_points', 'sort', 'status', 'remark'],
        'update' => ['scene', 'name', 'description', 'points_per_yuan', 'fixed_points', 'max_points', 'sort', 'status', 'remark'],
    ];
}
