<?php
declare(strict_types=1);

namespace app\validate\client\points;

use think\Validate;

/**
 * 前台积分商城验证器
 */
class PointsMallValidate extends Validate
{
    protected $rule = [
        'points_goods_id' => 'require|integer|gt:0',
        'address_id' => 'require|integer|gt:0',
        'quantity' => 'require|integer|between:1,99',
        'buyer_remark' => 'max:255',
        'idempotency_key' => 'max:64',
    ];

    protected $message = [
        'points_goods_id.require' => '请选择积分商品',
        'points_goods_id.integer' => '积分商品ID必须是整数',
        'points_goods_id.gt' => '积分商品ID不合法',
        'address_id.require' => '请选择收货地址',
        'address_id.integer' => '地址ID必须是整数',
        'address_id.gt' => '地址ID不合法',
        'quantity.require' => '兑换数量不能为空',
        'quantity.integer' => '兑换数量必须是整数',
        'quantity.between' => '兑换数量必须在1到99之间',
        'buyer_remark.max' => '备注最多255个字符',
        'idempotency_key.max' => '幂等键最多64个字符',
    ];

    protected $scene = [
        'exchange' => ['points_goods_id', 'address_id', 'quantity', 'buyer_remark', 'idempotency_key'],
    ];
}
