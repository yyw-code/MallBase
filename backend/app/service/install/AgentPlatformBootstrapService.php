<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use Throwable;

/**
 * 完成可恢复的首次平台激活与持有 Token 的确认心跳。
 */
final class AgentPlatformBootstrapService
{
    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly AgentInstanceStateStore $instances,
        private readonly AgentHeartbeatClient $heartbeat,
        private readonly AgentHeartbeatPayloadFactory $payloads,
        private readonly InstallLockService $legacy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function ensureConnected(string $componentType = 'backend_php'): AgentHeartbeatResult
    {
        $clock = $this->clock;
        $now = $clock();
        if (!is_int($now) || $now < 0 || $now > 4_102_444_800) {
            return AgentHeartbeatResult::failure('INSTANCE_STATE_UNAVAILABLE');
        }

        try {
            $instance = $this->instances->load()
                ?? $this->instances->initializeFromLegacy($this->legacy, $now);
        } catch (Throwable) {
            return AgentHeartbeatResult::failure('INSTANCE_STATE_UNAVAILABLE');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $state = $instance['activation_state'] ?? null;
            if (($instance['disabled'] ?? null) === true) {
                return AgentHeartbeatResult::failure('PLATFORM_REPORTING_DISABLED');
            }
            if ($state === 'confirmed') {
                $instanceId = is_string($instance['instance_id'] ?? null) ? $instance['instance_id'] : '';

                return new AgentHeartbeatResult(true, $instanceId);
            }
            if ($state === 'recovery_required') {
                return AgentHeartbeatResult::failure('PLATFORM_TOKEN_RECOVERY_REQUIRED');
            }
            if ($state === 'activating') {
                $expiresAt = $instance['activation_secret_expires_at'] ?? null;
                if (!is_int($expiresAt) || $expiresAt <= $now) {
                    try {
                        $instance = $this->instances->markExpiredActivationRecoveryRequired($now);
                    } catch (Throwable) {
                        $instance = $this->reloadOrNull();
                    }

                    return AgentHeartbeatResult::failure(
                        is_array($instance) && ($instance['activation_state'] ?? null) === 'recovery_required'
                            ? 'PLATFORM_TOKEN_RECOVERY_REQUIRED'
                            : 'INSTANCE_STATE_UNAVAILABLE',
                    );
                }
                $result = $this->runHeartbeat($instance, $componentType, $now);
                $instanceId = $instance['instance_id'] ?? null;
                if (!$result->ok || $result->skipped !== '' || !is_string($instanceId)
                    || $result->instanceId !== $instanceId || $result->token === ''
                    || $result->nextReportAfterSeconds < 1) {
                    return $result->ok
                        ? AgentHeartbeatResult::failure('ACTIVATION_RESPONSE_INVALID')
                        : $result;
                }
                try {
                    $instance = $this->instances->storeActivationResponse(
                        (string) ($instance['activation_generation'] ?? ''),
                        (int) ($instance['revision'] ?? 0),
                        $result->instanceId,
                        $result->token,
                        $now,
                    );
                } catch (Throwable) {
                    $visible = $this->reloadOrNull();
                    if (!is_array($visible) || ($visible['activation_state'] ?? null) !== 'confirming'
                        || ($visible['token'] ?? null) !== $result->token) {
                        return AgentHeartbeatResult::failure('INSTANCE_STATE_UNAVAILABLE');
                    }
                    $instance = $visible;
                }
                continue;
            }
            if ($state === 'confirming') {
                $result = $this->runHeartbeat($instance, $componentType, $now);
                $instanceId = $instance['instance_id'] ?? null;
                if (!$result->ok || $result->skipped !== '' || !is_string($instanceId)
                    || $result->instanceId !== $instanceId) {
                    return $result->ok
                        ? AgentHeartbeatResult::failure('ACTIVATION_CONFIRMATION_INVALID')
                        : $result;
                }
                try {
                    $instance = $this->instances->confirmActivation(
                        (string) ($instance['activation_generation'] ?? ''),
                        (int) ($instance['revision'] ?? 0),
                        $now,
                    );
                } catch (Throwable) {
                    $visible = $this->reloadOrNull();
                    if (!is_array($visible) || ($visible['activation_state'] ?? null) !== 'confirmed') {
                        return AgentHeartbeatResult::failure('INSTANCE_STATE_UNAVAILABLE');
                    }
                    $instance = $visible;
                }
                continue;
            }

            return AgentHeartbeatResult::failure('INSTANCE_STATE_INVALID');
        }

        return AgentHeartbeatResult::failure('INSTANCE_STATE_UNAVAILABLE');
    }

    /** @param array<string,mixed> $instance */
    private function runHeartbeat(array $instance, string $componentType, int $now): AgentHeartbeatResult
    {
        try {
            return $this->heartbeat->run($this->payloads->create($instance, $componentType, $now));
        } catch (Throwable) {
            return AgentHeartbeatResult::failure('AGENT_EXECUTION_FAILED');
        }
    }

    /** @return array<string,mixed>|null */
    private function reloadOrNull(): ?array
    {
        try {
            return $this->instances->load();
        } catch (Throwable) {
            return null;
        }
    }
}
