<?php
declare(strict_types=1);

namespace app\admin\validate\goods;

use think\Validate;

/**
 * 商品标签验证器
 */
class GoodsTagValidate extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'name' => 'require|max:50',
        'color' => 'max:20',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    /**
     * 错误消息
     */
    protected $message = [
        'name.require' => '标签名称不能为空',
        'name.max' => '标签名称最多50个字符',
        'color.max' => '显示颜色最多20个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['name', 'color', 'sort', 'status'],
        'update' => ['name', 'color', 'sort', 'status'],
    ];
}
