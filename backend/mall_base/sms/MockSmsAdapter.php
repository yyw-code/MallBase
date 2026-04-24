<?php

declare(strict_types=1);

namespace mall_base\sms;

use mall_base\log\Logger;

/**
 * Mock 短信适配器
 *
 * 用途:
 *  - 本地开发与联调:不真发短信,验证码会被 SmsService 写入 Redis,
 *    前端联调时直接查 Redis 或看日志即可
 *  - 单元/集成测试:作为默认 driver,避免依赖外部服务
 *
 * 关键行为:
 *  - send() 不抛异常,只输出日志
 *  - 验证码的"存活"由 SmsService 独立管理(Redis TTL),本类不参与
 *  - 单元测试场景下 Logger 可能未启动(无 App 容器),静默降级到 error_log
 */
final class MockSmsAdapter implements SmsAdapter
{
    public function send(string $mobile, string $scene, string $code, array $extra = []): void
    {
        $payload = [
            'mobile'  => $this->maskMobile($mobile),
            'scene'   => $scene,
            'code'    => $code,
            'extra'   => $extra,
            'channel' => 'mock',
        ];

        try {
            Logger::instance()->info('[SMS-Mock] 模拟发送验证码', $payload);
        } catch (\Throwable) {
            // 单元测试或裸跑场景,Logger 不可用时不影响主流程
            error_log('[SMS-Mock] ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 11) {
            return $mobile;
        }
        return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
    }
}
