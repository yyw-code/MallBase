<?php

declare(strict_types=1);

namespace app\service\upgrade;

/**
 * 容器运行身份的进程生命周期边界。
 *
 * 实现必须只使用升级 gate、运行注册表、Redis 活性证明和预创建的 lifetime lock；
 * 这些方法不得读取或写入业务数据库。
 */
interface UpgradeRuntimeLifecycle
{
    /**
     * 在工作进程开始接收工作前，登记 HTTP/可选 Cron 角色并持有进程生命周期 SH lock。
     */
    public function registerWorker(string $serverName, int $workerId, bool $cronEnabled): void;

    /**
     * 发布本容器 HTTP/Cron 角色的即时心跳；不得创建或修复 activity ledger。
     */
    public function heartbeat(): void;

    /**
     * 在进程工作结束后仅释放本进程持有的 SH lock。
     * 退休 tombstone 只能由完成双心跳失活核验的协调器持久化。
     */
    public function stopWorker(): void;
}
