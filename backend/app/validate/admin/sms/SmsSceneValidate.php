<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsSceneValidate extends Validate
{
    protected $rule = [
        'scene_code|场景编码' => 'require|max:40',
        'provider_id|服务商' => 'require|integer|gt:0',
        'template_id|模板' => 'integer',
        'sign_id|签名' => 'integer',
        'status|状态' => 'integer|in:0,1',
        'draft_template_name|模板草稿名称' => 'max:100',
        'draft_template_content|模板草稿内容' => 'max:2000',
        'draft_template_type|模板草稿类型' => 'integer|in:0,1,2,3',
        'draft_template_remark|模板草稿说明' => 'max:500',
        'submit_to_platform|提交平台' => 'in:0,1,true,false',
        'template_code|模板编码' => 'max:80',
    ];

    protected $scene = [
        'bind' => [
            'scene_code', 'provider_id', 'template_id', 'sign_id', 'status',
            'draft_template_name', 'draft_template_content', 'draft_template_type', 'draft_template_remark',
        ],
        'saveDraft' => [
            'scene_code', 'draft_template_name', 'draft_template_content', 'draft_template_type', 'draft_template_remark',
        ],
        'createTemplateAndBind' => [
            'scene_code', 'provider_id', 'sign_id', 'status',
            'draft_template_name', 'draft_template_content', 'draft_template_type', 'draft_template_remark',
            'submit_to_platform', 'template_code',
        ],
    ];
}
