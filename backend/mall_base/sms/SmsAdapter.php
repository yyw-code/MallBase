<?php

declare(strict_types=1);

namespace mall_base\sms;

/**
 * 短信渠道适配器接口
 *
 * 设计要点:
 *  - 一个 adapter 实现一个渠道(mock / aliyun / 腾讯云 / 华为云 ...)
 *  - 渠道选择由 config('sms.driver') 决定,容器绑定时自动选型
 *  - 业务方只面向接口编程,不感知具体渠道
 *  - 渠道内部负责构造模板参数、签名、调用第三方 API,失败时抛 SmsException
 */
interface SmsAdapter
{
    /**
     * 发送验证码
     *
     * @param string $mobile  手机号(11 位国内号)
     * @param string $scene   场景,见 {@see SmsScene}
     * @param string $code    6 位数字验证码
     * @param array  $extra   渠道扩展参数(部分模板可能需要 username 等占位符)
     *
     * @throws SmsException 渠道返回失败/网络异常/参数缺失
     */
    public function send(string $mobile, string $scene, string $code, array $extra = []): void;
}
