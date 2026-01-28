<?php

declare (strict_types=1);

namespace app\listener\swoole;

use think\swoole\Manager;
use think\swoole\message\ReloadMessage;
use think\swoole\message\PushMessage;

/**
 * Swoole 消息监听器
 * 触发时机：Worker 进程接收到 IPC 消息时
 * 适用场景：记录进程间通信消息、调试热更新机制
 */
class MessageListener
{
    public function handle(Manager $manager, $message): void
    {
        $workerId = $manager->getWorkerId();

        // 只在第一个 worker 输出
        if ($workerId !== 0) {
            return;
        }

        $time = date('Y-m-d H:i:s');

        if ($message instanceof ReloadMessage) {
            echo "[{$time}] [Swoole] 收到消息: ReloadMessage\n";
        } elseif ($message instanceof PushMessage) {
            echo "[{$time}] [Swoole] 收到消息: PushMessage (fd={$message->fd}, data=" . json_encode($message->data, JSON_UNESCAPED_UNICODE) . ")\n";
        } else {
            echo "[{$time}] [Swoole] 收到消息: " . get_class($message) . "\n";
        }
    }
}