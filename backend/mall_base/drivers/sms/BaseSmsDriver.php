<?php

namespace mall_base\drivers\sms;

use mall_base\base\BaseDriver;

/**
 * 短信驱动基类
 * 
 * 功能说明：
 * - 定义短信服务的统一接口
 * - 子类实现具体的短信平台逻辑
 * 
 * 使用示例：
 * ```php
 * // 注册短信驱动
 * \mall_base\DriverManager::register('sms', [
 *     'aliyun' => \mall_base\drivers\sms\AliyunSmsDriver::class,
 * ]);
 * 
 * // 设置默认驱动
 * \mall_base\DriverManager::setDefault('sms', 'aliyun');
 * 
 * // 使用驱动
 * $sms = \mall_base\DriverManager::driver('sms');
 * $sms->send('13800138000', '123456');
 * ```
 */
abstract class BaseSmsDriver extends BaseDriver
{
    /**
     * 发送场景验证码
     *
     * @param string $phone 手机号
     * @param string $scene 场景(login / register / reset_password 等)
     * @param string $code  6 位验证码
     * @param array  $extra 渠道扩展参数
     * @return bool
     */
    abstract public function sendCode(string $phone, string $scene, string $code, array $extra = []): bool;

    /**
     * 发送短信验证码(默认场景)
     *
     * @param string $phone 手机号
     * @param string $code 验证码
     * @return bool
     */
    abstract public function send(string $phone, string $code): bool;

    /**
     * 发送短信通知
     *
     * @param string $phone 手机号
     * @param array $params 短信参数
     * @return bool
     */
    abstract public function sendNotice(string $phone, array $params): bool;

    /**
     * 验证手机号格式
     *
     * @param string $phone 手机号
     * @return bool
     */
    protected function validatePhone(string $phone): bool
    {
        return preg_match('/^1[3-9]\d{9}$/', $phone) === 1;
    }
}
