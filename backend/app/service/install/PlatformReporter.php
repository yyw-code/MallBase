<?php

declare(strict_types=1);

namespace app\service\install;

use app\service\upgrade\UpgradeSharedFileStore;
use Closure;
use mall_base\log\Logger;
use Throwable;

/**
 * 平台实例低频上报服务。
 *
 * PHP 不再直接访问平台；所有网络请求由固定 Agent 子进程或正在运行的
 * serve 进程完成，共享 instance.json 是唯一凭据与调度真相源。
 */
final class PlatformReporter
{
    private ?UpgradeSharedFileStore $files = null;
    private ?AgentInstanceStateStore $instanceStore = null;
    private ?AgentHeartbeatClient $heartbeatClient = null;
    private ?AgentRuntimeLeaseReader $leaseReader = null;
    private ?AgentHeartbeatPayloadFactory $payloadFactory = null;
    private ?AgentPlatformBootstrapService $bootstrapService = null;

    /** @var Closure():int */
    private readonly Closure $clock;

    /** @param array<string,int>|null $settings */
    public function __construct(
        private readonly ?InstallLockService $lockService = null,
        ?AgentInstanceStateStore $instances = null,
        ?AgentHeartbeatClient $heartbeat = null,
        ?AgentRuntimeLeaseReader $leases = null,
        ?AgentHeartbeatPayloadFactory $payloads = null,
        ?AgentPlatformBootstrapService $bootstrap = null,
        ?Closure $clock = null,
        private readonly ?array $settings = null,
    ) {
        $this->instanceStore = $instances;
        $this->heartbeatClient = $heartbeat;
        $this->leaseReader = $leases;
        $this->payloadFactory = $payloads;
        $this->bootstrapService = $bootstrap;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function tick(string $componentType = 'backend_php'): void
    {
        try {
            $legacy = $this->legacyLock();
            if (!$legacy->isInstalled()) {
                return;
            }
            $clock = $this->clock;
            $now = $clock();
            if (!is_int($now) || $now < 0 || $now > 4_102_444_800) {
                return;
            }

            $instances = $this->instances();
            $instance = $instances->load() ?? $instances->initializeFromLegacy($legacy, $now);
            if (($instance['disabled'] ?? null) === true) {
                return;
            }
            if (($instance['activation_state'] ?? null) !== 'confirmed') {
                $this->bootstrap()->ensureConnected($componentType);

                return;
            }
            if ($this->leases()->isServeLeaseAlive($now)) {
                return;
            }

            $reservation = $instances->reserveReportWindow(
                $componentType,
                $now,
                $this->setting('reservation_interval', 60),
            );
            if ($reservation === null) {
                return;
            }
            $result = $this->heartbeat()->run(
                $this->payloads()->create($reservation['instance'], $componentType, $now),
            );
            $success = $result->ok && ($result->skipped === 'serve_active'
                || $result->instanceId === (string) ($reservation['instance']['instance_id'] ?? ''));
            $error = $success ? '' : $this->stableError($result->error);
            $instances->recordReportResult(
                $reservation['reservation_id'],
                $reservation['reservation_revision'],
                $success,
                $now,
                $success
                    ? $this->setting('report_interval', 86400)
                    : $this->setting('retry_interval', 300),
                $error,
            );
        } catch (Throwable) {
            Logger::instance()->debug('平台实例统计跳过', [
                'code' => 'PLATFORM_REPORT_SKIPPED',
            ]);
        }
    }

    private function stableError(string $error): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $error) === 1
            ? $error
            : 'AGENT_HEARTBEAT_FAILED';
    }

    private function setting(string $name, int $default): int
    {
        $value = $this->settings[$name] ?? config('agent.' . $name, $default);

        return is_int($value) && $value > 0 && $value <= 4_102_444_800 ? $value : $default;
    }

    private function legacyLock(): InstallLockService
    {
        return $this->lockService ?? app()->make(InstallLockService::class);
    }

    private function instances(): AgentInstanceStateStore
    {
        if ($this->instanceStore === null) {
            $this->instanceStore = new AgentInstanceConfigStore(
                $this->sharedFiles(),
                (string) config('agent.platform_origin', ''),
                (string) config('agent.upgrade_namespace_id', ''),
                (int) config('agent.activation_proof_lifetime', -1),
                (int) config('agent.component_seen_throttle', -1),
                (int) config('agent.instance_lock_timeout_milliseconds', -1),
            );
        }

        return $this->instanceStore;
    }

    private function heartbeat(): AgentHeartbeatClient
    {
        return $this->heartbeatClient ??= new AgentHeartbeatRunner(
            null,
            null,
            (int) config('agent.heartbeat_timeout_milliseconds', 5000),
        );
    }

    private function leases(): AgentRuntimeLeaseReader
    {
        return $this->leaseReader ??= new AgentRuntimeStatusReader($this->sharedFiles());
    }

    private function payloads(): AgentHeartbeatPayloadFactory
    {
        return $this->payloadFactory ??= AgentHeartbeatPayloadFactory::fromProjectRoot();
    }

    private function bootstrap(): AgentPlatformBootstrapService
    {
        return $this->bootstrapService ??= new AgentPlatformBootstrapService(
            $this->instances(),
            $this->heartbeat(),
            $this->payloads(),
            $this->legacyLock(),
            $this->clock,
        );
    }

    private function sharedFiles(): UpgradeSharedFileStore
    {
        return $this->files ??= new UpgradeSharedFileStore(
            (string) config('agent.upgrade_root', ''),
            (int) config('agent.agent_uid', -1),
            (int) config('agent.expected_gid', -1),
            (int) config('agent.php_euid', -1),
            (int) config('agent.max_json_bytes', 65536),
            (int) config('agent.instance_lock_timeout_milliseconds', 2000),
        );
    }
}
