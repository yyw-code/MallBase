<?php

declare(strict_types=1);

namespace app\service\install;

use app\service\upgrade\UpgradeSharedFileStore;
use Closure;
use Throwable;

/**
 * 只读解释 Agent 发布的短租约状态，不把状态文件当作进程管理器。
 */
final class AgentRuntimeStatusReader implements AgentRuntimeLeaseReader
{
    /** @var Closure():?object */
    private readonly Closure $reader;

    public function __construct(?UpgradeSharedFileStore $files = null, ?Closure $reader = null)
    {
        $this->reader = $reader ?? static fn(): ?object => $files?->readJson('agent_status');
    }

    public function isServeLeaseAlive(int $now): bool
    {
        if ($now < 0 || $now > 4_102_444_800) {
            return false;
        }
        try {
            $reader = $this->reader;
            $status = $reader();
            if (!$status instanceof \stdClass || !$this->valid($status)) {
                return false;
            }

            return $status->mode === 'serve'
                && $status->state !== 'offline'
                && $status->last_seen_at <= $now
                && $status->lease_until >= $now;
        } catch (Throwable) {
            return false;
        }
    }

    private function valid(object $status): bool
    {
        $fields = array_keys(get_object_vars($status));
        $required = [
            'schema_version', 'agent_version', 'mode', 'pid', 'arch', 'state',
            'last_seen_at', 'lease_until', 'safe_to_stop', 'production_ready',
            'upgrade_ready', 'revision',
        ];
        $allowed = array_merge($required, ['platform_state', 'platform_code', 'current_job_id']);
        foreach ($fields as $field) {
            if (!in_array($field, $allowed, true)) {
                return false;
            }
        }
        foreach ($required as $field) {
            if (!property_exists($status, $field)) {
                return false;
            }
        }

        return $status->schema_version === 1
            && is_string($status->agent_version) && $status->agent_version !== '' && strlen($status->agent_version) <= 64
            && is_string($status->mode) && $status->mode !== '' && strlen($status->mode) <= 32
            && is_int($status->pid) && $status->pid > 0
            && is_string($status->arch) && in_array($status->arch, ['amd64', 'arm64'], true)
            && is_string($status->state) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $status->state) === 1
            && is_int($status->last_seen_at) && $status->last_seen_at >= 0
            && is_int($status->lease_until) && $status->lease_until >= $status->last_seen_at
            && is_bool($status->safe_to_stop)
            && is_bool($status->production_ready)
            && is_bool($status->upgrade_ready)
            && is_int($status->revision) && $status->revision > 0
            && $this->optionalString($status, 'platform_state', 64)
            && $this->optionalString($status, 'platform_code', 128)
            && $this->optionalString($status, 'current_job_id', 128);
    }

    private function optionalString(object $status, string $field, int $maximum): bool
    {
        if (!property_exists($status, $field)) {
            return true;
        }
        $value = $status->{$field};

        return is_string($value) && strlen($value) <= $maximum
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
