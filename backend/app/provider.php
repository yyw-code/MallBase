<?php
use app\ExceptionHandle;
use app\Request;
use mall_base\sms\AliyunSmsAdapter;
use mall_base\sms\CacheBackedSmsCache;
use mall_base\sms\MockSmsAdapter;
use mall_base\sms\SmsAdapter;
use mall_base\sms\SmsCache;
use mall_base\sms\SmsRateLimiter;
use mall_base\sms\SmsService;

// 容器Provider定义文件
return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,

    // ---------------- SMS 子系统 ----------------
    // 缓存层:默认走 think\facade\Cache(项目默认 Redis)
    SmsCache::class => CacheBackedSmsCache::class,

    // 渠道:按 config('sms.driver') 选型,mock 用于本地与测试
    SmsAdapter::class => function () {
        $driver = (string) config('sms.driver', 'mock');
        return match ($driver) {
            'aliyun' => new AliyunSmsAdapter(),
            default  => new MockSmsAdapter(),
        };
    },

    // 频控:从 config 读取阈值
    SmsRateLimiter::class => function (\think\App $app) {
        $cache = $app->make(SmsCache::class);
        return new SmsRateLimiter(
            cache: $cache,
            mobileDailyLimit: (int) config('sms.rate_limit.mobile_daily', 5),
            ipMinuteLimit: (int) config('sms.rate_limit.ip_minute', 3),
        );
    },

    // 业务入口
    SmsService::class => function (\think\App $app) {
        return new SmsService(
            adapter: $app->make(SmsAdapter::class),
            rateLimiter: $app->make(SmsRateLimiter::class),
            cache: $app->make(SmsCache::class),
            codeTtl: (int) config('sms.code_ttl', 300),
        );
    },
];
