<?php

namespace mall_base\drivers\sms;

/**
 * 阿里云短信驱动
 * 
 * 功能说明：
 * - 实现阿里云短信发送功能
 * - 继承 BaseSmsDriver，实现具体平台的短信发送逻辑
 * 
 * 使用示例：
 * ```php
 * $config = [
 *     'access_key_id' => 'your_access_key_id',
 *     'access_key_secret' => 'your_access_key_secret',
 *     'sign_name' => 'your_sign_name',
 *     'template_code' => 'your_template_code',
 * ];
 * 
 * $sms = new AliyunSmsDriver($config);
 * $sms->send('13800138000', '123456');
 * ```
 */
class AliyunSmsDriver extends BaseSmsDriver
{
    /**
     * 阿里云签名
     * @var string
     */
    protected string $signName;

    /**
     * 模板代码
     * @var string
     */
    protected string $templateCode;

    /**
     * 初始化
     */
    protected function init(): void
    {
        $this->signName = $this->getConfig('sign_name', '');
        $this->templateCode = $this->getConfig('template_code', '');
    }

    /**
     * 发送短信验证码
     * 
     * @param string $phone 手机号
     * @param string $code 验证码
     * @return bool
     */
    public function send(string $phone, string $code): bool
    {
        // 验证手机号
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        try {
            // 这里调用阿里云短信 API
            // $result = $this->request($phone, ['code' => $code]);
            
            // 示例代码，实际使用时需要替换为真实的 API 调用
            $this->log("发送短信验证码: {$phone}, code: {$code}");
            
            // 模拟成功
            return true;
            
        } catch (\Exception $e) {
            $this->setError('发送失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 发送短信通知
     * 
     * @param string $phone 手机号
     * @param array $params 短信参数
     * @return bool
     */
    public function sendNotice(string $phone, array $params): bool
    {
        // 验证手机号
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        try {
            // 这里调用阿里云短信 API
            // $result = $this->request($phone, $params);
            
            // 示例代码，实际使用时需要替换为真实的 API 调用
            $this->log("发送短信通知: {$phone}, params: " . json_encode($params));
            
            // 模拟成功
            return true;
            
        } catch (\Exception $e) {
            $this->setError('发送失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 调用阿里云短信 API
     * 
     * @param string $phone 手机号
     * @param array $params 模板参数
     * @return array
     */
    protected function request(string $phone, array $params): array
    {
        // 实际使用时需要实现阿里云 API 调用逻辑
        // 可以使用阿里云 SDK 或直接调用 API
        
        return [
            'code' => 'OK',
            'message' => 'OK',
        ];
    }
}
