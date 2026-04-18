<?php
declare(strict_types=1);

namespace app\validate\client\order;

use think\Validate;

/**
 * 购物车请求校验
 *
 * scene 与 CartController 的方法一一对应：
 *  - add             加入购物车
 *  - update          修改单行数量
 *  - toggleSelected  批量切换勾选
 *  - remove          批量删除
 *
 * cart_ids 这里只做“必须是非空数组”的粗校验，
 * 去重和正整数过滤由 CartService::normalizeCartIds 兜底，
 * 避免请求层和服务层双重定义、维护两套规则。
 */
class CartValidate extends Validate
{
    protected $rule = [
        'sku_id'    => 'require|integer|min:1',
        'quantity'  => 'require|integer|between:1,999',
        'cart_ids'  => 'require|array',
        'selected'  => 'require|in:0,1',
    ];

    protected $message = [
        'sku_id.require'     => '请选择商品规格',
        'sku_id.min'         => '商品规格不合法',
        'quantity.require'   => '请填写数量',
        'quantity.between'   => '数量必须在 1 到 999 之间',
        'cart_ids.require'   => '请选择购物车记录',
        'cart_ids.array'     => '购物车记录参数格式错误',
        'selected.require'   => '请传入勾选状态',
        'selected.in'        => '勾选状态只能是 0 或 1',
    ];

    protected $scene = [
        'add'            => ['sku_id', 'quantity'],
        'update'         => ['quantity'],
        'toggleSelected' => ['cart_ids', 'selected'],
        'remove'         => ['cart_ids'],
    ];
}
