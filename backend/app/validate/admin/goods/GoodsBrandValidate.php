<?php
declare(strict_types=1);

namespace app\validate\admin\goods;

use think\Validate;

/**
 * 商品品牌验证器
 */
class GoodsBrandValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:100',
        'logo' => 'max:255',
        'description' => 'max:500',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'name.require' => '品牌名称不能为空',
        'name.max' => '品牌名称最多100个字符',
        'logo.max' => '品牌LOGO最多255个字符',
        'description.max' => '品牌描述最多500个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['name', 'logo', 'description', 'sort', 'status'],
        'update' => ['name', 'logo', 'description', 'sort', 'status'],
    ];
}
