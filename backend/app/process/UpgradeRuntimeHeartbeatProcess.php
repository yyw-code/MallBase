<?php

declare(strict_types=1);

namespace app\process;

use app\service\upgrade\UpgradeRuntimeLifecycle;
use app\service\upgrade\UpgradeRuntimeFailureLatch;
use RuntimeException;
use Swoole\Event;
use Swoole\Timer;
use Throwable;

final class UpgradeRuntimeHeartbeatProcess
{
    private const INTERVAL_MILLISECONDS = 5000;

    private bool $failureReported = false;

    public function __construct(
        private readonly UpgradeRuntimeLifecycle $lifecycle,
        private readonly ?UpgradeRuntimeFailureLatch $failureLatch = null,
    ) {
    }

    public function run(bool $failureBlocksCommercialStartup): void
    {
        $this->publishHeartbeat($failureBlocksCommercialStartup);
        $timerId = Timer::tick(self::INTERVAL_MILLISECONDS, function () use ($failureBlocksCommercialStartup): void {
            $this->publishHeartbeat($failureBlocksCommercialStartup);
        });
        if (!is_int($timerId) || $timerId < 0) {
            throw new RuntimeException('UPGRADE_RUNTIME_HEARTBEAT_TIMER_UNAVAILABLE');
        }

        Event::wait();
    }

    private function publishHeartbeat(bool $failureBlocksCommercialStartup): void
    {
        try {
            $this->lifecycle->heartbeat();
            $this->failureReported = false;
        } catch (Throwable $exception) {
            try {
                $this->failureLatch?->taintActiveOwners();
            } catch (Throwable $latchFailure) {
                throw new RuntimeException('UPGRADE_RUNTIME_FAILURE_LATCH_UNAVAILABLE', 0, $latchFailure);
            }
            if (!$this->failureReported) {
                $this->failureReported = true;
                fwrite(STDERR, "[MallBase Upgrade] 运行心跳不可用，自动升级已禁用；商业服务按启动策略继续。\n");
            }
            if ($failureBlocksCommercialStartup) {
                throw new RuntimeException('UPGRADE_RUNTIME_HEARTBEAT_UNAVAILABLE', 0, $exception);
            }
        }
    }
}
