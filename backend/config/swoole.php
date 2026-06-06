<?php

$installLockPath = runtime_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
$isInstalled = is_file($installLockPath);
$swooleQueueEnabled = filter_var(env('SWOOLE_QUEUE_ENABLE', false), FILTER_VALIDATE_BOOLEAN);

return [
    'http'       => [
        'enable'     => true,
        'host'       => env('SWOOLE_HTTP_HOST', '0.0.0.0'),
        'port'       => (int) env('SWOOLE_HTTP_PORT', 8080),
        'worker_num' => (int) env('SWOOLE_WORKER_NUM', 0) > 0 ? (int) env('SWOOLE_WORKER_NUM', 0) : swoole_cpu_num(),
        'options'    => [
            // 安全默认：定期重启 worker，避免内存泄漏累积
            'max_request'       => (int) env('SWOOLE_MAX_REQUEST', 2000),
            // 平滑重启，避免强杀 worker 导致请求丢失
            'reload_async'      => env('SWOOLE_RELOAD_ASYNC', true),
            // reload/stop 最大等待时间（秒）
            'max_wait_time'     => (int) env('SWOOLE_MAX_WAIT_TIME', 60),
            // 心跳检查，及时清理失效连接
            'heartbeat_idle_time' => (int) env('SWOOLE_HEARTBEAT_IDLE_TIME', 120),
            'heartbeat_check_interval' => (int) env('SWOOLE_HEARTBEAT_CHECK_INTERVAL', 60),
        ],
    ],
    'websocket'  => [
        'enable'        => false,
        'route' => true,
        'handler'       => \think\swoole\websocket\Handler::class,
        'ping_interval' => 25000,
        'ping_timeout'  => 60000,
        'room'          => [
            'type'  => 'table',
            'table' => [
                'room_rows'   => 8192,
                'room_size'   => 2048,
                'client_rows' => 4096,
                'client_size' => 2048,
            ],
            'redis' => [
                'host'          => env('REDIS_HOST', '127.0.0.1'),
                'port'          => (int) env('REDIS_PORT', 6379),
                'max_active'    => (int) env('SWOOLE_REDIS_POOL_MAX_ACTIVE', 3),
                'max_wait_time' => (int) env('SWOOLE_POOL_MAX_WAIT_TIME', 5),
            ],
        ],
        'listen'        => [],
        'subscribe'     => [],
    ],
    'rpc'        => [
        'server' => [
            'enable'     => false,
            'host'       => '0.0.0.0',
            'port'       => 9000,
            'worker_num' => swoole_cpu_num(),
            'services'   => [],
        ],
        'client' => [],
    ],
    //队列
    'queue'      => [
        'enable'  => $isInstalled && $swooleQueueEnabled,
        'workers' => [
            'default' => [
                'delay'      => 0,
                'sleep'      => 3,
                'tries'      => 3,
                'timeout'    => 60,
                'worker_num' => 1,
            ],
        ],
    ],
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        'include' => [
            app_path(),
            config_path(),
            root_path('route'),
            root_path('mall_base'),
        ],
        'exclude' => [],
    ],
    //连接池
    'pool'       => [
        'db'    => [
            'enable'        => true,
            'max_active'    => (int) env('SWOOLE_DB_POOL_MAX_ACTIVE', 3),
            'max_wait_time' => (int) env('SWOOLE_POOL_MAX_WAIT_TIME', 5),
        ],
        'cache' => [
            'enable'        => true,
            'max_active'    => (int) env('SWOOLE_CACHE_POOL_MAX_ACTIVE', 3),
            'max_wait_time' => (int) env('SWOOLE_POOL_MAX_WAIT_TIME', 5),
        ],
        //自定义连接池
    ],
    'ipc'        => [
        'type'  => 'unix_socket',
        'redis' => [
            'host'          => env('REDIS_HOST', '127.0.0.1'),
            'port'          => (int) env('REDIS_PORT', 6379),
            'max_active'    => (int) env('SWOOLE_REDIS_POOL_MAX_ACTIVE', 3),
            'max_wait_time' => (int) env('SWOOLE_POOL_MAX_WAIT_TIME', 5),
        ],
    ],
    //锁
    'lock'       => [
        'enable' => false,
        'type'   => 'table',
        'redis'  => [
            'host'          => env('REDIS_HOST', '127.0.0.1'),
            'port'          => (int) env('REDIS_PORT', 6379),
            'max_active'    => (int) env('SWOOLE_REDIS_POOL_MAX_ACTIVE', 3),
            'max_wait_time' => (int) env('SWOOLE_POOL_MAX_WAIT_TIME', 5),
        ],
    ],
    'tables'     => [],
    //每个worker里需要预加载以共用的实例
    'concretes'  => [],
    //重置器
    'resetters'  => [],
    //每次请求前需要清空的实例
    'instances'  => [],
    //每次请求前需要重新执行的服务
    'services'   => [],
];
