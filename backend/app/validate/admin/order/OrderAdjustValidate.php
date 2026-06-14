<?php

declare(strict_types=1);

namespace app\validate\admin\order;

use think\Validate;

/**
 * 后台订单改价校验器
 *
 * 约束：
 *  - 运费必须 ≥ 0
 *  - 改价方式必须为逐商品优惠或整单实付比例
 *  - 商品优惠明细与最终应付金额由 Service 层按分计算并要求 > 0
 */
class OrderAdjustValidate extends Validate
{
    protected $rule = [
        'freight_amount|运费'       => 'require|regex:/^\d+(\.\d{1,2})?$/',
        'adjust_mode|改价方式'      => 'require|in:item_discount,pay_percent',
        'items|商品优惠明细'        => 'array',
        'pay_percent|整单实付比例'  => 'float|egt:0|elt:100',
        'reason|备注'               => 'max:255',
    ];

    protected $scene = [
        'adjust' => ['freight_amount', 'adjust_mode', 'items', 'pay_percent', 'reason'],
    ];
}
