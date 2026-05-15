<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsConfigValidate extends Validate
{
    protected $rule = [
        'code_ttl|验证码有效期'        => 'require|integer|between:30,3600',
        'rate_mobile_daily|手机号日上限' => 'require|integer|between:1,100',
        'rate_ip_minute|IP分钟上限'    => 'require|integer|between:1,100',
    ];

    protected $scene = [
        'save' => ['code_ttl', 'rate_mobile_daily', 'rate_ip_minute'],
    ];
}
