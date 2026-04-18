<?php
declare(strict_types=1);

namespace app\validate\admin\setting;

use think\Validate;

class FreightTemplateValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:100',
        'charge_type' => 'require|in:piece,weight',
        'default_first_amount' => 'require|float|>:0',
        'default_first_fee' => 'require|float|>=:0',
        'default_continue_amount' => 'require|float|>:0',
        'default_continue_fee' => 'require|float|>=:0',
        'status' => 'in:0,1',
        'remark' => 'max:255',
    ];

    protected $scene = [
        'create' => ['name', 'charge_type', 'default_first_amount', 'default_first_fee', 'default_continue_amount', 'default_continue_fee', 'status', 'remark'],
        'update' => ['name', 'charge_type', 'default_first_amount', 'default_first_fee', 'default_continue_amount', 'default_continue_fee', 'status', 'remark'],
        'status' => ['status'],
    ];
}
