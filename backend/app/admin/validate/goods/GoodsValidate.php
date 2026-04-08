<?php
declare(strict_types=1);

namespace app\admin\validate\goods;

use think\Validate;

/**
 * 商品验证器
 */
class GoodsValidate extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'name' => 'require|max:200',
        'subtitle' => 'max:200',
        'category_id' => 'require|integer',
        'brand_id' => 'integer',
        'price' => 'float|egt:0',
        'description' => 'max:2000',
        'status' => 'in:0,1',
        'is_on_sale' => 'in:0,1',
    ];

    /**
     * 错误消息
     */
    protected $message = [
        'name.require' => '商品名称不能为空',
        'name.max' => '商品名称最多200个字符',
        'subtitle.max' => '副标题最多200个字符',
        'category_id.require' => '商品分类不能为空',
        'category_id.integer' => '商品分类必须是整数',
        'brand_id.integer' => '品牌必须是整数',
        'price.float' => '价格必须是数字',
        'price.egt' => '价格必须大于等于0',
        'description.max' => '商品描述最多2000个字符',
        'status.in' => '状态必须是0或1',
        'is_on_sale.in' => '是否上架必须是0或1',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['name', 'subtitle', 'category_id', 'brand_id', 'price', 'description', 'status', 'is_on_sale'],
        'update' => ['name', 'subtitle', 'category_id', 'brand_id', 'price', 'description', 'status', 'is_on_sale'],
    ];
}
