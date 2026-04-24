<?php

/**
 * 短信验证码配置
 *
 * 通过 SMS_DRIVER 切换渠道,默认 mock。
 * 切换到 aliyun 时需先在 AliyunSmsAdapter 内完成 SDK 接入(本期为骨架)。
 */
return [
    /**
     * 当前使用的短信渠道
     *
     * 可选值:mock / aliyun
     * 容器在 app/provider.php 中根据该值绑定 SmsAdapter 实现
     */
    'driver' => env('SMS_DRIVER', 'mock'),

    /**
     * 验证码 TTL(秒)
     */
    'code_ttl' => (int) env('SMS_CODE_TTL', 300),

    /**
     * 频控阈值(SmsRateLimiter)
     *
     * - mobile_daily:同手机号 24 小时内最多发送次数
     * - ip_minute:同 IP 每分钟最多发送次数
     * (同手机号 60 秒间隔为硬编码,不可关闭)
     */
    'rate_limit' => [
        'mobile_daily' => (int) env('SMS_RATE_MOBILE_DAILY', 5),
        'ip_minute'    => (int) env('SMS_RATE_IP_MINUTE', 3),
    ],

    /**
     * 阿里云渠道参数(driver=aliyun 时启用)
     *
     * - access_key_id / access_key_secret:阿里云 RAM 子账号凭证
     * - sign_name:已审核通过的短信签名
     * - templates:不同场景对应的模板 ID,key 与 SmsScene 的常量值一致
     */
    'aliyun' => [
        'access_key_id'     => env('ALIYUN_SMS_ACCESS_KEY_ID', ''),
        'access_key_secret' => env('ALIYUN_SMS_ACCESS_KEY_SECRET', ''),
        'sign_name'         => env('ALIYUN_SMS_SIGN_NAME', ''),
        'templates' => [
            'login'                => env('ALIYUN_SMS_TEMPLATE_LOGIN', ''),
            'register'             => env('ALIYUN_SMS_TEMPLATE_REGISTER', ''),
            'reset_password'       => env('ALIYUN_SMS_TEMPLATE_RESET_PASSWORD', ''),
            'bind_mobile'          => env('ALIYUN_SMS_TEMPLATE_BIND_MOBILE', ''),
            'wechat_official_bind' => env('ALIYUN_SMS_TEMPLATE_WECHAT_OFFICIAL_BIND', ''),
        ],
    ],
];
