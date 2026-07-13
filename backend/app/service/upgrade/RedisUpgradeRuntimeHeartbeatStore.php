<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use Throwable;

final readonly class RedisUpgradeRuntimeHeartbeatStore implements UpgradeRuntimeHeartbeatStore
{
    private ?RedisServerIncarnation $incarnation;

    private const HEARTBEAT_SCRIPT = <<<'LUA'
local gate_raw = redis.call('GET', KEYS[1])
local ledger_raw = redis.call('GET', KEYS[2])
if not gate_raw or not ledger_raw then return {-2, ''} end
local gate_ok, gate = pcall(cjson.decode, gate_raw)
local ledger_ok, ledger = pcall(cjson.decode, ledger_raw)
local payload_ok, payload = pcall(cjson.decode, ARGV[4])
if not gate_ok or not ledger_ok or not payload_ok or type(gate) ~= 'table' or
   type(ledger) ~= 'table' or type(payload) ~= 'table' or
   gate.revision ~= tonumber(ARGV[1]) or gate.activity_generation ~= tonumber(ARGV[2]) or
   gate.redis_incarnation ~= ARGV[3] or ledger.generation ~= gate.activity_generation or
   ledger.server_run_id ~= gate.redis_incarnation or payload.activity_generation ~= gate.activity_generation or
   payload.redis_incarnation ~= gate.redis_incarnation or payload.observed_gate_revision ~= gate.revision or
   payload.app_version ~= gate.required_runtime_version or payload.deployment_id ~= gate.required_deployment_id or
   payload.storage_layout_version ~= gate.required_storage_layout_version or
   payload.storage_layout_generation ~= gate.required_storage_layout_generation or
   payload.observed_deployment_epoch ~= gate.deployment_epoch or type(payload.owner_key) ~= 'string' then
    return {-2, ''}
end
local current_raw = redis.call('HGET', KEYS[3], payload.owner_key)
local sequence = 1
if current_raw then
    local current_ok, current = pcall(cjson.decode, current_raw)
    if not current_ok or type(current) ~= 'table' or current.owner_key ~= payload.owner_key or
       current.runtime_instance_id ~= payload.runtime_instance_id or current.boot_id ~= payload.boot_id or
       current.role ~= payload.role or type(current.heartbeat_seq) ~= 'number' then return {-2, ''} end
    sequence = current.heartbeat_seq + 1
end
payload.heartbeat_seq = sequence
local encoded = cjson.encode(payload)
redis.call('HSET', KEYS[3], payload.owner_key, encoded)
return {1, encoded}
LUA;

    private const FIND_SCRIPT = <<<'LUA'
local current = redis.call('HGET', KEYS[1], ARGV[1])
if not current then return {0, ''} end
return {1, current}
LUA;

    public function __construct(
        private object $redis,
        private string $namespace,
        ?RedisServerIncarnation $incarnation = null,
    ) {
        if (preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $this->namespace) !== 1
            || (!$this->redis instanceof UpgradeRedisConnectionFactory
                && !method_exists($this->redis, 'eval'))) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_CONFIG_INVALID');
        }
        $this->incarnation = $this->redis instanceof UpgradeRedisConnectionFactory
            ? null
            : ($incarnation ?? new RedisServerIncarnation($this->redis));
    }

    public function heartbeat(UpgradeGateSnapshot $gate, array $runtimeRecord, int $now, int $ttl): array
    {
        if ($now < 0 || $ttl < 1 || $ttl > 60) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_INVALID');
        }
        $runtime = $this->runtimeFromRecord($runtimeRecord);
        $payload = [
            ...$runtimeRecord,
            'owner_key' => $runtime->key(),
            'observed_gate_revision' => $gate->revision,
            'last_seen_at' => $now,
            'expires_at' => $now + $ttl,
        ];
        $result = $this->guardedEval(self::HEARTBEAT_SCRIPT, [
            $this->gateKey(),
            $this->ledgerKey(),
            $this->runtimeKey(),
            $gate->revision,
            $gate->activityGeneration,
            $gate->redisIncarnation,
            $this->encode($payload),
        ], 3, $gate->redisIncarnation);
        if (!is_array($result) || ($result[0] ?? null) !== 1 || !is_string($result[1] ?? null)) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_UNAVAILABLE');
        }

        return $this->decode($result[1]);
    }

    public function find(string $ownerKey, string $expectedServerRunId): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}:[0-9a-f-]{36}:(http|queue|cron)$/D', $ownerKey) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_INVALID');
        }
        $result = $this->guardedEval(
            self::FIND_SCRIPT,
            [$this->runtimeKey(), $ownerKey],
            1,
            $expectedServerRunId,
        );
        if (!is_array($result) || !is_int($result[0] ?? null)) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_UNAVAILABLE');
        }

        return $result[0] === 0 ? null : $this->decode((string) ($result[1] ?? ''));
    }

    /** @param list<mixed> $arguments */
    private function guardedEval(string $script, array $arguments, int $keyCount, string $expectedRunId): mixed
    {
        $owned = $this->redis instanceof UpgradeRedisConnectionFactory;
        $redis = $this->connection();
        try {
            $incarnation = $this->incarnation ?? new RedisServerIncarnation($redis);
            $before = $incarnation->connectionIdentity();
            if (!hash_equals($expectedRunId, $before->runId)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_CHANGED');
            }
            $result = $redis->eval($script, $arguments, $keyCount);
            $after = $incarnation->connectionIdentity();
            if (!$before->equals($after)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_CHANGED');
            }

            return $result;
        } finally {
            if ($owned && method_exists($redis, 'close')) {
                try {
                    $redis->close();
                } catch (Throwable) {
                }
            }
        }
    }

    private function connection(): object
    {
        return $this->redis instanceof UpgradeRedisConnectionFactory
            ? $this->redis->create()
            : $this->redis;
    }

    /** @return array<string,mixed> */
    private function decode(string $raw): array
    {
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_INVALID');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_INVALID');
        }

        return $value;
    }

    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_HEARTBEAT_INVALID');
        }
    }

    /** @param array<string,mixed> $record */
    private function runtimeFromRecord(array $record): UpgradeRuntimeInstance
    {
        return UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $record['runtime_instance_id'] ?? null,
            'boot_id' => $record['boot_id'] ?? null,
            'role' => $record['role'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'deployment_id' => $record['deployment_id'] ?? null,
            'storage_layout_version' => $record['storage_layout_version'] ?? null,
            'storage_layout_generation' => $record['storage_layout_generation'] ?? null,
            'observed_deployment_epoch' => $record['observed_deployment_epoch'] ?? null,
        ]);
    }

    private function gateKey(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:gate';
    }

    private function ledgerKey(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:activity-ledger';
    }

    private function runtimeKey(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:runtime-heartbeats';
    }
}
