<?php
use app\ExceptionHandle;
use app\Request;
use app\service\sms\CacheBackedSmsCache;
use app\service\sms\SmsCache;
use app\service\sms\SmsRateLimiter;
use app\service\sms\SmsService;
use mall_base\drivers\DriverManager;

// 容器Provider定义文件
return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,

    // ---------------- SMS 子系统 ----------------
    // 缓存层:默认走 think\facade\Cache(项目默认 Redis)
    SmsCache::class => CacheBackedSmsCache::class,

    // 频控:从数据库读取阈值
    SmsRateLimiter::class => function (\think\App $app) {
        return new SmsRateLimiter(
            cache: $app->make(SmsCache::class),
            mobileDailyLimit: (int) getSystemSetting('sms_rate_mobile_daily', 5),
            ipMinuteLimit: (int) getSystemSetting('sms_rate_ip_minute', 3),
        );
    },

    // 业务入口:驱动通过 DriverManager 获取
    SmsService::class => function (\think\App $app) {
        $raw = getSystemSettingGroup('SmsAliyun');
        $aliyunConfig = [
            'access_key_id'     => $raw['sms_aliyun_access_key_id'] ?? '',
            'access_key_secret' => $raw['sms_aliyun_access_key_secret'] ?? '',
            'sign_name'         => $raw['sms_aliyun_sign_name'] ?? '',
            'templates' => [
                'login'                => $raw['sms_aliyun_tpl_login'] ?? '',
                'register'             => $raw['sms_aliyun_tpl_register'] ?? '',
                'reset_password'       => $raw['sms_aliyun_tpl_reset_password'] ?? '',
                'bind_mobile'          => $raw['sms_aliyun_tpl_bind_mobile'] ?? '',
                'wechat_official_bind' => $raw['sms_aliyun_tpl_wechat_official_bind'] ?? '',
            ],
        ];
        $driver = DriverManager::driver('sms', null, $aliyunConfig);
        return new SmsService(
            driver: $driver,
            rateLimiter: $app->make(SmsRateLimiter::class),
            cache: $app->make(SmsCache::class),
            codeTtl: (int) getSystemSetting('sms_code_ttl', 300),
        );
    },
];
