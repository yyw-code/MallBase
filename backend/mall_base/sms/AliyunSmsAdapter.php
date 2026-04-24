<?php

declare(strict_types=1);

namespace mall_base\sms;

/**
 * 阿里云短信适配器(骨架)
 *
 * 当前阶段仅留接入入口,具体调用阿里云 dysmsapi 的代码留给后续接入。
 * 当 config('sms.driver') = 'aliyun' 时,容器绑定到本类。
 *
 * 接入要点(占位 TODO,接入时再实现):
 *  - 安装 aliyun/dysmsapi sdk(composer require alibabacloud/dysmsapi-20170525)
 *  - 从 config('sms.aliyun') 读取 access_key_id / access_key_secret / sign_name /
 *    template_id_map[scene]
 *  - 调用 SendSms 接口,传入 PhoneNumbers / SignName / TemplateCode / TemplateParam
 *  - 阿里云返回 Code !== 'OK' 时抛 SmsException,带原始错误码与消息
 *  - 推荐配合频控:同手机号 60s 1 次、24h 5 次、同 IP 1min 3 次(由 SmsRateLimiter 处理)
 */
final class AliyunSmsAdapter implements SmsAdapter
{
    public function send(string $mobile, string $scene, string $code, array $extra = []): void
    {
        throw new SmsException('阿里云短信渠道尚未接入,请先在 config/sms.php 切换 driver=mock 或完成 SDK 接入');
    }
}
