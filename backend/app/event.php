<?php
// 事件定义文件
return [
    'bind' => [
    ],

    'listen' => [
        'AppInit' => [],
        'HttpRun' => [],
        'HttpEnd' => [
            app\listener\install\PlatformReportListener::class,
        ],
        'LogLevel' => [],
        'LogWrite' => [],

        // ==================== 支付告警事件 ====================
        // 由 NotifyService 在验签失败 / 金额不一致 / 重放攻击命中时派发
        // 监听器仅做日志落地 + 预留运维通道转发，不阻塞回调主链路
        'payment.verify_failed' => [
            app\listener\payment\PaymentAlertListener::class,
        ],
        'payment.amount_mismatch' => [
            app\listener\payment\PaymentAlertListener::class,
        ],
        'payment.replay_attack' => [
            app\listener\payment\PaymentAlertListener::class,
        ],

        // ==================== Swoole 事件 ====================

        // Swoole 初始化事件
        // 触发时机：在 Swoole 服务初始化完成后，主进程启动时触发
        // 触发位置：主进程
        // 触发次数：仅一次
        // 适用场景：输出启动信息、初始化全局资源
        'swoole.init' => [
            app\listener\swoole\SwooleStartupListener::class,
        ],

        // Swoole Worker 启动事件
        // 触发时机：每个 Worker 进程启动时触发
        // 触发位置：Worker 进程
        // 触发次数：Worker 数量 × 重启次数
        // 适用场景：初始化 Worker 级别的资源、启动定时任务
        'swoole.workerStart' => [
            app\listener\swoole\WorkerBootListener::class,
        ],

        // Swoole 消息事件
        // 触发时机：Worker 进程接收到 IPC 消息时
        // 触发位置：Worker 进程
        // 适用场景：记录进程间通信消息、调试热更新机制
        'swoole.message' => [
            app\listener\swoole\MessageListener::class, // 消息监听器
        ],

        // Swoole Worker 停止前事件
        // 触发时机：Worker 进程停止前触发（think-swoole 自定义事件）
        // 触发位置：Worker 进程
        // 适用场景：清理 Worker 级别的资源、保存状态
        'swoole.beforeWorkerStop' => [
            app\listener\swoole\BeforeWorkerStopListener::class, // Worker 停止前监听器
        ],
    ],

    'subscribe' => [
    ],
];
