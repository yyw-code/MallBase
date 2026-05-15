<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsTemplateValidate extends Validate
{
    protected $rule = [
        'provider_id|服务商' => 'require|integer|gt:0',
        'template_name|模板名称' => 'require|max:100',
        'template_type|模板类型' => 'integer|in:0,1,2,3',
        'template_content|模板内容' => 'require|max:2000',
        'remark|申请说明' => 'max:500',
    ];

    protected $scene = [
        'create' => ['provider_id', 'template_name', 'template_type', 'template_content', 'remark'],
        'update' => ['provider_id', 'template_name', 'template_type', 'template_content', 'remark'],
    ];
}
