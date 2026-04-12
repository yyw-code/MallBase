<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => env('CACHE_DRIVER', 'redis'),

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        'redis' => [
            // 驱动方式
            'type'       => 'redis',
            // 服务器地址
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            // 服务器端口
            'port'       => (int) env('REDIS_PORT', 6379),
            // 服务器密码
            'password'   => env('REDIS_PASSWORD', ''),
            // 数据库索引
            'select'     => (int) env('REDIS_CACHE_DB', 0),
            // 超时时间（秒）
            'timeout'    => (int) env('REDIS_TIMEOUT', 0),
            // 是否长连接
            'persistent' => env('REDIS_PERSISTENT', false),
            // 缓存前缀
            'prefix'     => env('CACHE_PREFIX', ''),
            // 缓存有效期 0表示永久缓存
            'expire'     => (int) env('CACHE_EXPIRE', 0),
            // 缓存标签前缀
            'tag_prefix' => env('CACHE_TAG_PREFIX', 'tag:'),
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // 更多的缓存连接
    ],
];
