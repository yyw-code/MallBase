<?php
declare(strict_types=1);

namespace app\admin\validate\goods;

use think\Validate;

/**
 * 商品分类验证器
 */
class GoodsCategoryValidate extends Validate
{
    protected $rule = [
        'pid' => 'require|integer|egt:0',
        'name' => 'require|max:50',
        'icon' => 'max:255',
        'image' => 'max:255',
        'description' => 'max:255',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'pid.require' => '父级分类不能为空',
        'pid.integer' => '父级分类必须是整数',
        'pid.egt' => '父级分类必须大于等于0',
        'name.require' => '分类名称不能为空',
        'name.max' => '分类名称最多50个字符',
        'icon.max' => '分类图标最多255个字符',
        'image.max' => '分类图片最多255个字符',
        'description.max' => '分类描述最多255个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['pid', 'name', 'icon', 'image', 'description', 'sort', 'status'],
        'update' => ['pid', 'name', 'icon', 'image', 'description', 'sort', 'status'],
    ];
}
