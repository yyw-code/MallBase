<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\service\sms\SmsSecret;
use mall_base\drivers\DriverManager;
use mall_base\drivers\sms\BaseSmsDriver;
use mall_base\drivers\sms\contracts\SmsTemplateManagerInterface;
use mall_base\exception\BusinessException;

/**
 * 服务商驱动工厂
 *
 * 负责按 SmsProvider 配置实例化具体驱动,并校验其是否实现远端模板管理契约。
 */
final class SmsDriverFactory
{
    /**
     * 实例化指定服务商的驱动(并断言其实现 SmsTemplateManagerInterface)
     */
    public static function manager(SmsProvider $provider): SmsTemplateManagerInterface
    {
        $driver = self::driver($provider);
        if (!$driver instanceof SmsTemplateManagerInterface) {
            throw new BusinessException("驱动 [{$provider->driver}] 不支持远端模板管理");
        }
        return $driver;
    }

    public static function driver(SmsProvider $provider): BaseSmsDriver
    {
        return DriverManager::create('sms', (string) $provider->driver, [
            'access_key_id' => (string) $provider->access_key_id,
            'access_key_secret' => SmsSecret::decrypt((string) $provider->access_key_secret),
            'region' => (string) $provider->region,
        ]);
    }

    /**
     * 服务商是否支持远端签名/模板管理
     *
     * 通过驱动是否实现 SmsTemplateManagerInterface 判断,避免业务层硬编码 driver 字符串。
     */
    public static function supportsRemoteSignManagement(SmsProvider $provider): bool
    {
        return self::driver($provider) instanceof SmsTemplateManagerInterface;
    }
}
