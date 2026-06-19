<?php
use app\ExceptionHandle;
use app\Request;
use app\service\sms\CacheBackedSmsCache;
use app\service\sms\SmsCache;
use app\service\sms\SmsRateLimiter;
use app\service\sms\SmsService;

// 容器Provider定义文件
return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,

    // ---------------- SMS 子系统 ----------------
    // 缓存层:默认走 think\facade\Cache(项目默认 Redis)
    SmsCache::class => CacheBackedSmsCache::class,

    // 频控:阈值由 SmsRateLimiter 按需读取系统表单 SmsRateLimit 分组
    SmsRateLimiter::class => function (\think\App $app) {
        return new SmsRateLimiter(
            cache: $app->make(SmsCache::class),
        );
    },

    // 业务入口:驱动由 SmsService 按场景绑定动态解析,验证码 TTL 按需读取设置表
    SmsService::class => function (\think\App $app) {
        return new SmsService(
            driver: null,
            rateLimiter: $app->make(SmsRateLimiter::class),
            cache: $app->make(SmsCache::class),
        );
    },
];
