<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use JsonException;
use Throwable;

/**
 * 通过固定二进制执行一次有界 Agent 心跳。
 *
 * 自定义 executor 只用于测试，不会启动传入的命令；生产路径始终先经过
 * AgentBinaryTrustValidator，再以数组命令调用 proc_open，禁止 shell 解析。
 */
final class AgentHeartbeatRunner implements AgentHeartbeatClient
{
    private const MAX_INPUT_BYTES = 64 * 1024;
    private const MAX_STDOUT_BYTES = 1024 * 1024;
    private const MAX_STDERR_BYTES = 64 * 1024;
    private const MAX_TIMESTAMP = 4_102_444_800;

    /** @var Closure(array<int,string>,string,int):array<string,mixed>|null */
    private readonly ?Closure $executor;

    public function __construct(
        ?Closure $executor = null,
        private readonly ?string $binaryPath = null,
        private readonly int $timeoutMilliseconds = 5000,
        private readonly ?AgentBinaryTrustValidator $trustValidator = null,
    ) {
        $this->executor = $executor;
    }

    /** @param array<string, mixed> $payload */
    public function run(array $payload): AgentHeartbeatResult
    {
        if ($this->timeoutMilliseconds < 1 || $this->timeoutMilliseconds > 60_000) {
            return AgentHeartbeatResult::failure('AGENT_CONFIGURATION_INVALID');
        }

        try {
            $stdin = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return AgentHeartbeatResult::failure('AGENT_INPUT_INVALID');
        }
        if (!is_string($stdin) || $stdin === '' || strlen($stdin) > self::MAX_INPUT_BYTES || $stdin[0] !== '{') {
            return AgentHeartbeatResult::failure('AGENT_INPUT_INVALID');
        }

        $binary = $this->binaryPath ?? $this->defaultBinaryPath();
        if ($binary === null) {
            return AgentHeartbeatResult::failure('AGENT_BINARY_UNAVAILABLE');
        }

        try {
            $process = (new AgentProcessRunner($this->executor, $this->trustValidator))->run(
                $binary,
                'heartbeat',
                $stdin,
                $this->timeoutMilliseconds,
                self::MAX_STDOUT_BYTES,
                self::MAX_STDERR_BYTES,
            );
        } catch (Throwable) {
            return AgentHeartbeatResult::failure('AGENT_EXECUTION_FAILED');
        }

        return $this->decodeProcessResult($process);
    }

    /** @param array<string, mixed> $process */
    private function decodeProcessResult(array $process): AgentHeartbeatResult
    {
        $exitCode = $process['exit_code'] ?? null;
        $stdout = $process['stdout'] ?? null;
        $stderr = $process['stderr'] ?? null;
        $timedOut = $process['timed_out'] ?? false;
        if (!is_int($exitCode) || !is_string($stdout) || !is_string($stderr) || !is_bool($timedOut)) {
            return AgentHeartbeatResult::failure('AGENT_PROCESS_INVALID');
        }
        if (strlen($stdout) > self::MAX_STDOUT_BYTES || strlen($stderr) > self::MAX_STDERR_BYTES) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_TOO_LARGE');
        }
        if ($timedOut) {
            return AgentHeartbeatResult::failure('AGENT_TIMEOUT');
        }
        if ($exitCode !== 0) {
            $known = str_contains($stderr, 'PLATFORM_TOKEN_RECOVERY_REQUIRED')
                ? 'PLATFORM_TOKEN_RECOVERY_REQUIRED'
                : 'AGENT_PROCESS_FAILED';

            return AgentHeartbeatResult::failure($known);
        }

        return $this->decodeOutput($stdout);
    }

    private function decodeOutput(string $stdout): AgentHeartbeatResult
    {
        $raw = trim($stdout);
        if ($raw === '' || strlen($raw) > self::MAX_STDOUT_BYTES || $raw[0] !== '{') {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            $canonical = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }
        if (!is_array($decoded) || !is_string($canonical) || $canonical !== $raw || array_is_list($decoded)) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }
        $allowed = ['ok', 'skipped', 'instance_id', 'token', 'next_report_after_seconds'];
        foreach (array_keys($decoded) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
            }
        }
        if (($decoded['ok'] ?? null) !== true) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }

        $skipped = $decoded['skipped'] ?? '';
        $instanceId = $decoded['instance_id'] ?? '';
        $token = $decoded['token'] ?? '';
        $next = $decoded['next_report_after_seconds'] ?? 0;
        if (!is_string($skipped) || !is_string($instanceId) || !is_string($token) || !is_int($next)
            || ($skipped !== '' && $skipped !== 'heartbeat_active')
            || ($instanceId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $instanceId) !== 1)
            || ($token !== '' && !$this->validToken($token))
            || $next < 0 || $next > self::MAX_TIMESTAMP) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }
        if ($skipped !== '' && (count($decoded) !== 2 || $instanceId !== '' || $token !== '' || $next !== 0)) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }
        if ($token !== '' && ($instanceId === '' || $next < 1)) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }
        if (array_key_exists('next_report_after_seconds', $decoded) && $next < 1) {
            return AgentHeartbeatResult::failure('AGENT_OUTPUT_INVALID');
        }

        return new AgentHeartbeatResult(true, $instanceId, $token, $next, $skipped);
    }

    private function validToken(string $token): bool
    {
        return $token !== '' && strlen($token) <= 4096 && trim($token) === $token
            && preg_match('/[\x00-\x20\x7f]/', $token) !== 1;
    }

    private function defaultBinaryPath(): ?string
    {
        $root = (string) config('agent.upgrade_root', '');
        if ($root === '') {
            return null;
        }

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'active' . DIRECTORY_SEPARATOR . 'mallbase-agent';
    }

}
