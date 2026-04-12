<?php

// JWT 配置
return [
    // 密钥（必须在环境变量中配置）
    'secret' => env('JWT_SECRET', ''),

    // Token 过期时间（秒），默认 2 小时
    'expire' => env('JWT_EXPIRE', 7200),

    // 刷新 Token 过期时间（秒），默认 30 天
    'refresh_expire' => env('JWT_REFRESH_EXPIRE', 2592000),

    // 算法
    'algorithm' => env('JWT_ALGORITHM', 'HS256'),

    // 颁发者
    'issuer' => env('JWT_ISSUER', 'mall-admin'),
];
