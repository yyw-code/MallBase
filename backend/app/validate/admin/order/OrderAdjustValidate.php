<?php

declare(strict_types=1);

namespace app\validate\admin\order;

use think\Validate;

/**
 * 后台订单改价校验器
 *
 * 约束：
 *  - 运费必须 ≥ 0
 *  - 优惠允许负数（表示加价）
 *  - 应付金额由 Service 层 bcmath 重算并要求 > 0，本层不再判断
 */
class OrderAdjustValidate extends Validate
{
    protected $rule = [
        'freight_amount|运费'   => 'require|float|egt:0',
        'discount_amount|优惠'  => 'require|float',
        'reason|备注'           => 'max:255',
    ];

    protected $scene = [
        'adjust' => ['freight_amount', 'discount_amount', 'reason'],
    ];
}
