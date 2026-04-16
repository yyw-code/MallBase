<?php
declare(strict_types=1);

namespace app\client\validate\order;

use think\Validate;

/**
 * 买家订单请求校验
 *
 * scene 与 OrderController 方法一一对应：
 *  - createFromCart 购物车结算下单
 *  - createFromSku  立即购买
 *  - pay            Mock 支付
 *  - cancel         买家取消
 *
 * items / cart_ids 这里只做粗校验（非空数组），
 * 元素层面去重与正整数过滤由 OrderService 兜底，避免双重定义。
 */
class OrderValidate extends Validate
{
    protected $rule = [
        'source'          => 'require|in:cart,sku',
        'cart_ids'        => 'requireIf:source,cart|array',
        'items'           => 'requireIf:source,sku|array',
        'address_id'      => 'require|integer|min:1',
        'buyer_remark'    => 'max:255',
        'idempotency_key' => 'max:64',
        'pay_method'      => 'require|integer|in:1,2,9',
        'reason'          => 'max:255',
    ];

    protected $message = [
        'source.require'     => '请指定下单来源',
        'source.in'          => '下单来源不合法',
        'cart_ids.requireIf' => '请选择要结算的购物车商品',
        'cart_ids.array'     => '购物车参数格式错误',
        'items.requireIf'    => '请选择要购买的商品',
        'items.array'        => '商品参数格式错误',
        'address_id.require' => '请选择收货地址',
        'address_id.min'     => '收货地址不合法',
        'buyer_remark.max'   => '买家备注最长 255 字',
        'pay_method.require' => '请选择支付方式',
        'pay_method.in'      => '支付方式不合法',
        'reason.max'         => '取消原因最长 255 字',
    ];

    protected $scene = [
        'create' => ['source', 'cart_ids', 'items', 'address_id', 'buyer_remark', 'idempotency_key'],
        'pay'    => ['pay_method'],
        'cancel' => ['reason'],
    ];
}
