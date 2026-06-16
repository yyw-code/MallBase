<?php

declare(strict_types=1);

namespace app\model\sms;

use mall_base\base\BaseModel;

/**
 * 短信服务商模型
 *
 * 关键约束:
 *  - access_key_secret 列存储 AES 密文,读写必须经过 SmsProviderService 加解密
 *  - is_default 列全表唯一(切换默认时由 Service 层先清零再置位)
 */
class SmsProvider extends BaseModel
{
    protected $name = 'sms_provider';

    protected $autoWriteTimestamp = true;

    /** 驱动:阿里云短信(企业版) */
    public const DRIVER_ALIYUN = 'aliyun';

    /** 驱动:Mock(开发/测试) */
    public const DRIVER_MOCK = 'mock';

    public function templates()
    {
        return $this->hasMany(SmsTemplate::class, 'provider_id', 'id');
    }

    public function signs()
    {
        return $this->hasMany(SmsSign::class, 'provider_id', 'id');
    }
}
