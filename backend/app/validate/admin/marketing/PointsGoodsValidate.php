<?php
declare(strict_types=1);

namespace app\validate\admin\marketing;

use think\Validate;

/**
 * 积分商品验证器
 */
class PointsGoodsValidate extends Validate
{
    protected $rule = [
        'goods_id' => 'require|integer|gt:0',
        'sku_id' => 'require|integer|gt:0',
        'points_price' => 'require|integer|gt:0',
        'exchange_stock' => 'integer|egt:0',
        'limit_per_user' => 'integer|egt:0',
        'sort' => 'integer',
        'status' => 'in:0,1',
        'remark' => 'max:255',
    ];

    protected $message = [
        'goods_id.require' => '请选择商品',
        'goods_id.integer' => '商品ID必须是整数',
        'goods_id.gt' => '商品ID不合法',
        'sku_id.require' => '请选择商品规格',
        'sku_id.integer' => '商品规格ID必须是整数',
        'sku_id.gt' => '商品规格ID不合法',
        'points_price.require' => '兑换积分不能为空',
        'points_price.integer' => '兑换积分必须是整数',
        'points_price.gt' => '兑换积分必须大于0',
        'exchange_stock.integer' => '兑换库存必须是整数',
        'exchange_stock.egt' => '兑换库存不能小于0',
        'limit_per_user.integer' => '每人限兑数量必须是整数',
        'limit_per_user.egt' => '每人限兑数量不能小于0',
        'sort.integer' => '排序必须是整数',
        'status.in' => '状态必须是0或1',
        'remark.max' => '备注最多255个字符',
    ];

    protected $scene = [
        'create' => ['goods_id', 'sku_id', 'points_price', 'exchange_stock', 'limit_per_user', 'sort', 'status', 'remark'],
        'update' => ['goods_id', 'sku_id', 'points_price', 'exchange_stock', 'limit_per_user', 'sort', 'status', 'remark'],
    ];
}
