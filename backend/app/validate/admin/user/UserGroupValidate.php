<?php
declare(strict_types=1);

namespace app\validate\admin\user;

use think\Validate;

/**
 * 用户分组验证器
 */
class UserGroupValidate extends Validate
{
    /**
     * 创建验证规则
     */
    protected $rule = [
        'name' => 'require|max:50',
        'code' => 'require|max:50|alphaNum',
        'description' => 'max:255',
        'color' => 'max:20',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    /**
     * 错误消息
     */
    protected $message = [
        'name.require' => '分组名称不能为空',
        'name.max' => '分组名称最多50个字符',
        'code.require' => '分组编码不能为空',
        'code.max' => '分组编码最多50个字符',
        'code.alphaNum' => '分组编码只能是字母和数字',
        'description.max' => '分组描述最多255个字符',
        'color.max' => '显示颜色最多20个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['name', 'code', 'description', 'color', 'sort', 'status'],
        'update' => ['name', 'code', 'description', 'color', 'sort', 'status'],
    ];
}
