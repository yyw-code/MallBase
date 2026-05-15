<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsSignValidate extends Validate
{
    protected $rule = [
        'provider_id|服务商' => 'require|integer|gt:0',
        'sign_name|签名名称' => 'require|max:100',
        'sign_source|签名来源' => 'integer|in:0,1,2,3,4,5',
        'sign_type|签名类型' => 'integer|in:0,1',
        'remark|申请说明' => 'max:500',
        'qualification_id|资质ID' => 'integer|egt:0',
        'sign_files|资质文件' => 'array',
    ];

    protected $scene = [
        'create' => ['provider_id', 'sign_name', 'sign_source', 'sign_type', 'remark', 'qualification_id', 'sign_files'],
        'update' => ['provider_id', 'sign_name', 'sign_source', 'sign_type', 'remark', 'qualification_id'],
    ];
}
