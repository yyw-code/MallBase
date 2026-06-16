<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsSignValidate extends Validate
{
    protected $rule = [
        'provider_id|服务商' => 'require|integer|gt:0',
        'sign_name|签名名称' => 'require|max:100',
        'remark|备注' => 'max:500',
    ];

    protected $scene = [
        'import' => ['provider_id', 'sign_name', 'remark'],
    ];
}
