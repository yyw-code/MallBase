<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsProviderValidate extends Validate
{
    protected $rule = [
        'name|服务商名称' => 'require|max:60',
        'driver|驱动'    => 'require|in:aliyun,tencent,mock',
        'access_key_id|AccessKeyId' => 'max:128',
        'access_key_secret|AccessKeySecret' => 'max:512',
        'region|区域' => 'max:40',
        'is_default|是否默认' => 'in:0,1',
        'status|状态' => 'in:0,1',
        'remark|备注' => 'max:255',
        'sort|排序' => 'integer|egt:0',
    ];

    protected $scene = [
        'create' => ['name', 'driver', 'access_key_id', 'access_key_secret', 'region', 'is_default', 'status', 'remark', 'sort'],
        'update' => ['name', 'driver', 'access_key_id', 'access_key_secret', 'region', 'is_default', 'status', 'remark', 'sort'],
    ];
}
