<?php
declare(strict_types=1);

namespace app\validate\admin\marketing;

use think\Validate;

/**
 * 充值套餐验证器
 */
class RechargePackageValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:50',
        'pay_amount' => 'require|regex:/^\d+(\.\d{1,2})?$/',
        'gift_amount' => 'regex:/^\d+(\.\d{1,2})?$/',
        'background_image' => 'max:255',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
        'remark' => 'max:255',
    ];

    protected $message = [
        'name.require' => '套餐名称不能为空',
        'name.max' => '套餐名称最多50个字符',
        'pay_amount.require' => '支付金额不能为空',
        'pay_amount.regex' => '支付金额格式不合法',
        'gift_amount.regex' => '赠送金额格式不合法',
        'background_image.max' => '背景图最多255个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
        'remark.max' => '备注最多255个字符',
    ];

    protected $scene = [
        'create' => ['name', 'pay_amount', 'gift_amount', 'background_image', 'sort', 'status', 'remark'],
        'update' => ['name', 'pay_amount', 'gift_amount', 'background_image', 'sort', 'status', 'remark'],
    ];
}
