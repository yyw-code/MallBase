<?php
// 事件定义文件
return [
    'bind' => [
    ],

    'listen' => [
        'AppInit' => [],
        'HttpRun' => [],
        'HttpEnd' => [],
        'LogLevel' => [],
        'LogWrite' => [],

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

        // Swoole Worker 停止事件
        // 触发时机：Worker 进程停止前触发
        // 触发位置：Worker 进程
        // 适用场景：清理 Worker 级别的资源、保存状态
        'swoole.workerStop' => [
            // app\listener\SwooleWorkerStopListener::class, // Worker 停止监听器
        ],

        // Swoole Worker 退出事件
        // 触发时机：Worker 进程退出时触发
        // 触发位置：Worker 进程
        // 适用场景：记录退出日志、清理资源
        'swoole.workerExit' => [
            // app\listener\swoole\WorkerExitListener::class,, // Worker 退出监听器
        ],

        // Swoole Worker 错误事件
        // 触发时机：Worker 进程发生错误时触发
        // 触发位置：Worker 进程
        // 适用场景：记录错误日志、错误报警
        'swoole.workerError' => [
            // \app\listener\SwooleWorkerErrorListener::class, // Worker 错误监听器
        ],

        // Swoole 关闭事件
        // 触发时机：Swoole 服务关闭时触发
        // 触发位置：主进程
        // 适用场景：清理全局资源、记录关闭日志
        'swoole.shutDown' => [
            // \app\listener\SwooleShutdownListener::class, // Swoole 关闭监听器
        ],
    ],

    'subscribe' => [
    ],
];
