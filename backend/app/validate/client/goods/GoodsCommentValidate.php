<?php
declare(strict_types=1);

namespace app\validate\client\goods;

use think\Validate;

/**
 * 买家商品评价请求校验
 */
class GoodsCommentValidate extends Validate
{
    protected $rule = [
        'order_item_id' => 'require|integer|gt:0',
        'rating' => 'require|integer|between:1,5',
        'content' => 'max:500',
        'images' => 'array',
        'is_anonymous' => 'in:0,1',
    ];

    protected $message = [
        'order_item_id.require' => '请选择要评价的商品',
        'order_item_id.gt' => '订单商品参数不合法',
        'rating.require' => '请选择评分',
        'rating.between' => '评分必须在 1 到 5 之间',
        'content.max' => '评价内容最多 500 个字符',
        'images.array' => '评价图片参数格式错误',
        'is_anonymous.in' => '匿名状态不合法',
    ];

    protected $scene = [
        'create' => ['order_item_id', 'rating', 'content', 'images', 'is_anonymous'],
    ];
}
