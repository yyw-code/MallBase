<?php

declare(strict_types=1);

namespace app\service\sms;

/**
 * 短信运行配置读取.
 *
 * 配置项存储在系统表单 SmsRateLimit 分组,这里按需读取以适配 Swoole 常驻进程。
 */
final class SmsConfig
{
    public const CODE_TTL = 'sms_code_ttl';

    public const RATE_MOBILE_DAILY = 'sms_rate_mobile_daily';

    public const RATE_IP_MINUTE = 'sms_rate_ip_minute';

    public const DEFAULT_CODE_TTL = 300;

    public const DEFAULT_RATE_MOBILE_DAILY = 5;

    public const DEFAULT_RATE_IP_MINUTE = 3;

    public static function codeTtl(): int
    {
        return self::intSetting(self::CODE_TTL, self::DEFAULT_CODE_TTL, 30, 3600);
    }

    public static function mobileDailyLimit(): int
    {
        return self::intSetting(self::RATE_MOBILE_DAILY, self::DEFAULT_RATE_MOBILE_DAILY, 1, 100);
    }

    public static function ipMinuteLimit(): int
    {
        return self::intSetting(self::RATE_IP_MINUTE, self::DEFAULT_RATE_IP_MINUTE, 1, 100);
    }

    private static function intSetting(string $code, int $default, int $min, int $max): int
    {
        $value = (int) getSystemSetting($code, $default);

        return max($min, min($max, $value));
    }
}
