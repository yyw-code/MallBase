<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use Throwable;

final class RedisUpgradeActivityLedgerBackend implements UpgradeActivityLedgerBackend
{
    private readonly ?RedisServerIncarnation $incarnation;

    private const INITIALIZE_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current then
    local ok, value = pcall(cjson.decode, current)
    if not ok or type(value) ~= 'table' or value.schema_version ~= 1 or
       value.generation ~= tonumber(ARGV[1]) or value.server_run_id ~= ARGV[2] or
       value.expected_count ~= 0 or value.digest ~= redis.sha1hex('') or next(value.entries) ~= nil then
        return 0
    end
    return 1
end
redis.call('SET', KEYS[1], ARGV[3])
return 1
LUA;

    private const MUTATE_SCRIPT = <<<'LUA'
local function integrity(entries)
    local keys = {}
    for key, _ in pairs(entries) do table.insert(keys, key) end
    table.sort(keys)
    local material = ''
    for _, key in ipairs(keys) do
        local value = entries[key]
        material = material .. string.len(key) .. ':' .. key .. string.len(value) .. ':' .. value
    end
    return #keys, redis.sha1hex(material)
end
local raw = redis.call('GET', KEYS[1])
if not raw then return {-2, ''} end
local ok, ledger = pcall(cjson.decode, raw)
if not ok or type(ledger) ~= 'table' or ledger.schema_version ~= 1 or
   ledger.generation ~= tonumber(ARGV[2]) or ledger.server_run_id ~= ARGV[3] or
   type(ledger.entries) ~= 'table' then return {-2, ''} end
local count, digest = integrity(ledger.entries)
if count ~= ledger.expected_count or digest ~= ledger.digest then return {-2, ''} end
local gate_raw = redis.call('GET', KEYS[2])
if not gate_raw then return {-2, ''} end
local gate_ok, gate = pcall(cjson.decode, gate_raw)
if not gate_ok or type(gate) ~= 'table' or gate.activity_generation ~= ledger.generation or
   gate.redis_incarnation ~= ledger.server_run_id or gate.uncertain ~= false then return {-2, ''} end
local operation = ARGV[1]
if operation == 'snapshot' then return {1, raw} end
local entry_id = ARGV[4]
local token = ARGV[5]
if operation == 'begin' then
    local payload_ok, payload = pcall(cjson.decode, ARGV[6])
    local states_ok, states = pcall(cjson.decode, ARGV[7])
    if not gate_ok or not payload_ok or not states_ok or type(gate) ~= 'table' or type(payload) ~= 'table' or
       type(states) ~= 'table' or gate.revision ~= tonumber(ARGV[8]) or gate.activity_generation ~= ledger.generation or
       gate.redis_incarnation ~= ledger.server_run_id or gate.uncertain ~= false or states[gate.state] ~= true or
       type(payload.owner) ~= 'table' or payload.owner.app_version ~= gate.required_runtime_version or
       payload.owner.deployment_id ~= gate.required_deployment_id or
       payload.owner.storage_layout_version ~= gate.required_storage_layout_version or
       payload.owner.storage_layout_generation ~= gate.required_storage_layout_generation or
       payload.owner.observed_deployment_epoch ~= gate.deployment_epoch then return {0, ''} end
    if ledger.entries[entry_id] ~= nil then return {0, ''} end
    ledger.entries[entry_id] = token .. '\n' .. ARGV[6]
elseif operation == 'bind' then
    local current = ledger.entries[entry_id]
    if type(current) ~= 'string' or string.sub(current, 1, string.len(token) + 1) ~= token .. '\n' then return {0, ''} end
    ledger.entries[entry_id] = ARGV[9] .. '\n' .. ARGV[6]
elseif operation == 'release' then
    local current = ledger.entries[entry_id]
    if current == nil then return {1, raw} end
    if type(current) ~= 'string' or string.sub(current, 1, string.len(token) + 1) ~= token .. '\n' then return {0, ''} end
    ledger.entries[entry_id] = nil
else
    return {-2, ''}
end
ledger.revision = ledger.revision + 1
ledger.expected_count, ledger.digest = integrity(ledger.entries)
local next_raw = cjson.encode(ledger)
redis.call('SET', KEYS[1], next_raw)
return {1, next_raw}
LUA;

    private const WORKER_SCRIPT = <<<'LUA'
local function integrity(entries)
    local keys = {}
    for key, _ in pairs(entries) do table.insert(keys, key) end
    table.sort(keys)
    local material = ''
    for _, key in ipairs(keys) do
        local value = entries[key]
        material = material .. string.len(key) .. ':' .. key .. string.len(value) .. ':' .. value
    end
    return #keys, redis.sha1hex(material)
end
local ledger_raw = redis.call('GET', KEYS[1])
local gate_raw = redis.call('GET', KEYS[2])
if not ledger_raw or not gate_raw then return {-2, ''} end
local ledger_ok, ledger = pcall(cjson.decode, ledger_raw)
local gate_ok, gate = pcall(cjson.decode, gate_raw)
if not ledger_ok or not gate_ok or type(ledger) ~= 'table' or type(gate) ~= 'table' or
   ledger.schema_version ~= 1 or ledger.generation ~= tonumber(ARGV[2]) or
   ledger.server_run_id ~= ARGV[3] or type(ledger.entries) ~= 'table' or
   gate.revision ~= tonumber(ARGV[4]) or gate.activity_generation ~= ledger.generation or
   gate.redis_incarnation ~= ledger.server_run_id or gate.uncertain ~= false then return {-2, ''} end
local count, digest = integrity(ledger.entries)
if count ~= ledger.expected_count or digest ~= ledger.digest then return {-2, ''} end
local operation = ARGV[1]
if operation == 'list' then
    return {1, cjson.encode(redis.call('HGETALL', KEYS[3]))}
end
local worker_id = ARGV[5]
local current_raw = redis.call('HGET', KEYS[3], worker_id)
if operation == 'heartbeat' then
    local payload_ok, payload = pcall(cjson.decode, ARGV[6])
    if not payload_ok or type(payload) ~= 'table' or payload.worker_id ~= worker_id or
       payload.activity_generation ~= ledger.generation or payload.redis_incarnation ~= ledger.server_run_id or
       payload.gate_revision ~= gate.revision or payload.app_version ~= gate.required_runtime_version or
       payload.deployment_id ~= gate.required_deployment_id or
       payload.storage_layout_version ~= gate.required_storage_layout_version or
       payload.storage_layout_generation ~= gate.required_storage_layout_generation or
       payload.observed_deployment_epoch ~= gate.deployment_epoch or type(payload.owner_key) ~= 'string' or
       type(payload.last_seen_at) ~= 'number' or type(payload.expires_at) ~= 'number' then return {0, ''} end
    if current_raw then
        local current_ok, current = pcall(cjson.decode, current_raw)
        if not current_ok or type(current) ~= 'table' or current.owner_key ~= payload.owner_key or
           current.runtime_instance_id ~= payload.runtime_instance_id or current.boot_id ~= payload.boot_id or
           current.role ~= payload.role or current.activity_generation ~= payload.activity_generation or
           current.redis_incarnation ~= payload.redis_incarnation or
           (type(current.last_seen_at) == 'number' and current.last_seen_at > payload.last_seen_at) then return {-2, ''} end
        payload.paused_revision = current.paused_revision
        payload.paused_ack = current.paused_ack
    end
    redis.call('HSET', KEYS[3], worker_id, cjson.encode(payload))
    return {1, ''}
elseif operation == 'ack' then
    if not current_raw or gate.state ~= 'paused' or tonumber(ARGV[7]) ~= gate.revision then return {0, ''} end
    local current_ok, current = pcall(cjson.decode, current_raw)
    local owner_ok, owner = pcall(cjson.decode, ARGV[6])
    if not current_ok or type(current) ~= 'table' or current.activity_generation ~= ledger.generation or
       not owner_ok or type(owner) ~= 'table' or current.redis_incarnation ~= ledger.server_run_id or
       current.gate_revision > gate.revision or current.owner_key ~= owner.owner_key or
       current.runtime_instance_id ~= owner.runtime_instance_id or current.boot_id ~= owner.boot_id or
       current.role ~= owner.role then return {-2, ''} end
    current.gate_revision = gate.revision
    current.paused_revision = gate.revision
    current.expires_at = tonumber(ARGV[8])
    current.paused_ack = {
        gate_revision = gate.revision,
        activity_generation = ledger.generation,
        redis_incarnation = ledger.server_run_id,
        owner_key = current.owner_key,
        acked_at = tonumber(ARGV[9])
    }
    redis.call('HSET', KEYS[3], worker_id, cjson.encode(current))
    return {1, ''}
end
return {-2, ''}
LUA;

    public function __construct(
        private readonly object $redis,
        private readonly string $namespace,
        ?RedisServerIncarnation $incarnation = null,
    ) {
        if (preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $this->namespace) !== 1
            || (!$this->redis instanceof UpgradeRedisConnectionFactory
                && (!method_exists($this->redis, 'eval') || !method_exists($this->redis, 'get')))) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_CONFIG_INVALID');
        }
        $this->incarnation = $this->redis instanceof UpgradeRedisConnectionFactory
            ? null
            : ($incarnation ?? new RedisServerIncarnation($this->redis));
    }

    public function initialize(int $generation, string $serverRunId): void
    {
        if ($generation < 1 || !$this->validRunId($serverRunId)) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_CONFIG_INVALID');
        }
        $document = [
            'schema_version' => 1,
            'generation' => $generation,
            'server_run_id' => $serverRunId,
            'revision' => 0,
            'expected_count' => 0,
            'digest' => sha1(''),
            'entries' => (object) [],
        ];
        $encoded = $this->encode($document);
        $result = $this->guardedEval(
            self::INITIALIZE_SCRIPT,
            [$this->ledgerKey(), $generation, $serverRunId, $encoded],
            1,
            $serverRunId,
        );
        if ($result !== 1) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
    }

    public function begin(UpgradeGateSnapshot $gate, string $entryId, array $payload, array $allowedStates): ?string
    {
        $token = bin2hex(random_bytes(16));
        $states = [];
        foreach ($allowedStates as $state) {
            if (!$state instanceof UpgradeState) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_INPUT_INVALID');
            }
            $states[$state->value] = true;
        }
        $result = $this->mutate(
            'begin',
            $gate->activityGeneration,
            $gate->redisIncarnation,
            $entryId,
            $token,
            $this->encode($payload),
            $this->encode($states),
            $gate->revision,
            '',
        );

        return $result === 1 ? $token : null;
    }

    public function bind(int $generation, string $serverRunId, string $entryId, string $token, array $payload): ?string
    {
        $nextToken = bin2hex(random_bytes(16));
        $result = $this->mutate(
            'bind', $generation, $serverRunId, $entryId, $token, $this->encode($payload), '{}', 0, $nextToken,
        );

        return $result === 1 ? $nextToken : null;
    }

    public function release(int $generation, string $serverRunId, string $entryId, string $token): void
    {
        if ($this->mutate('release', $generation, $serverRunId, $entryId, $token, '{}', '{}', 0, '') !== 1) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RELEASE_CONFLICT');
        }
    }

    public function snapshot(int $generation, string $serverRunId): array
    {
        $raw = $this->mutateRaw('snapshot', $generation, $serverRunId, '', '', '{}', '{}', 0, '');
        $document = $this->decodeObject($raw);
        $entries = $document['entries'] ?? null;
        if (!is_array($entries)) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
        }
        $result = [];
        foreach ($entries as $entryId => $entry) {
            if (!is_string($entryId) || !is_string($entry)) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
            }
            $separator = strpos($entry, "\n");
            if ($separator !== 32 || preg_match('/^[0-9a-f]{32}$/D', substr($entry, 0, 32)) !== 1) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
            }
            $payload = json_decode(substr($entry, 33), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
            }
            $result[] = $payload;
        }

        return $result;
    }

    public function heartbeatWorker(UpgradeGateSnapshot $gate, string $workerId, array $worker): void
    {
        if (!$this->validName($workerId)) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
        }
        $result = $this->workerOperation($gate, 'heartbeat', $workerId, $this->encode($worker));
        if (($result[0] ?? null) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
        }
    }

    public function ackPaused(
        UpgradeGateSnapshot $gate,
        string $workerId,
        UpgradeRuntimeInstance $owner,
        int $revision,
        int $expiresAt,
    ): void
    {
        if (!$this->validName($workerId) || $revision < 0 || $expiresAt < 0) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_ACK_INVALID');
        }
        $result = $this->workerOperation($gate, 'ack', $workerId, $this->encode([
            ...$owner->toArray(),
            'owner_key' => $owner->key(),
        ]), $revision, $expiresAt, time());
        if (($result[0] ?? null) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_ACK_INVALID');
        }
    }

    public function liveWorkers(UpgradeGateSnapshot $gate, int $now): array
    {
        if ($now < 0) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
        }
        $result = $this->workerOperation($gate, 'list', '', '{}');
        if (($result[0] ?? null) !== 1 || !is_string($result[1] ?? null)) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
        }
        $flatRows = json_decode($result[1], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($flatRows) || count($flatRows) % 2 !== 0) {
            throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
        }
        $rows = [];
        for ($index = 0, $count = count($flatRows); $index < $count; $index += 2) {
            if (!is_string($flatRows[$index]) || !is_string($flatRows[$index + 1])) {
                throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
            }
            $rows[$flatRows[$index]] = $flatRows[$index + 1];
        }
        $result = [];
        foreach ($rows as $workerId => $raw) {
            if (!is_string($workerId) || !is_string($raw)) {
                throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
            }
            $worker = $this->decodeObject($raw);
            if (!is_int($worker['expires_at'] ?? null)) {
                throw new UpgradeStateConflict('UPGRADE_WORKER_HEARTBEAT_INVALID');
            }
            $worker['expired'] = $worker['expires_at'] < $now;
            $result[] = $worker;
        }

        return $result;
    }

    /** @return array{0:int,1:mixed} */
    private function workerOperation(
        UpgradeGateSnapshot $gate,
        string $operation,
        string $workerId,
        string $payload,
        int $ackRevision = 0,
        int $expiresAt = 0,
        int $ackedAt = 0,
    ): array {
        $result = $this->guardedEval(self::WORKER_SCRIPT, [
            $this->ledgerKey(),
            $this->gateKey(),
            $this->workerKey(),
            $operation,
            $gate->activityGeneration,
            $gate->redisIncarnation,
            $gate->revision,
            $workerId,
            $payload,
            $ackRevision,
            $expiresAt,
            $ackedAt,
        ], 3, $gate->redisIncarnation);
        if (!is_array($result) || !is_int($result[0] ?? null) || $result[0] < 0) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }

        return [$result[0], $result[1] ?? null];
    }

    public function reconcileQueue(
        int $generation,
        string $serverRunId,
        UpgradeQueueInventory $inventory,
        UpgradeRuntimeOwnerLiveness $owners,
    ): void
    {
        $raw = $this->mutateRaw('snapshot', $generation, $serverRunId, '', '', '{}', '{}', 0, '');
        $document = $this->decodeObject($raw);
        foreach (($document['entries'] ?? []) as $entryId => $entry) {
            if (!is_string($entryId) || !is_string($entry) || ($separator = strpos($entry, "\n")) !== 32) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
            }
            $token = substr($entry, 0, 32);
            $payload = json_decode(substr($entry, 33), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || ($payload['kind'] ?? null) !== 'queue' || ($payload['phase'] ?? null) !== 'bound') {
                continue;
            }
            $owner = UpgradeRuntimeInstance::fromArray($payload['owner'] ?? []);
            if ($owners->canRetire($owner)
                && !$inventory->contains((string) ($payload['connection'] ?? ''), (string) ($payload['queue'] ?? ''), (string) ($payload['job_id'] ?? ''))) {
                $this->mutate('release', $generation, $serverRunId, $entryId, $token, '{}', '{}', 0, '');
            }
        }
    }

    public function reconcileOrphans(int $generation, string $serverRunId, UpgradeRuntimeOwnerLiveness $owners): void
    {
        $raw = $this->mutateRaw('snapshot', $generation, $serverRunId, '', '', '{}', '{}', 0, '');
        $document = $this->decodeObject($raw);
        foreach (($document['entries'] ?? []) as $entryId => $entry) {
            if (!is_string($entryId) || !is_string($entry) || ($separator = strpos($entry, "\n")) !== 32) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
            }
            $token = substr($entry, 0, 32);
            $payload = json_decode(substr($entry, 33), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || (($payload['kind'] ?? null) === 'queue' && ($payload['phase'] ?? null) === 'bound')) {
                continue;
            }
            $owner = UpgradeRuntimeInstance::fromArray($payload['owner'] ?? []);
            if ($owners->canRetire($owner)) {
                $this->mutate('release', $generation, $serverRunId, $entryId, $token, '{}', '{}', 0, '');
            }
        }
    }

    private function mutate(
        string $operation,
        int $generation,
        string $runId,
        string $entryId,
        string $token,
        string $payload,
        string $states,
        int $gateRevision,
        string $nextToken,
    ): int {
        $result = $this->guardedEval(self::MUTATE_SCRIPT, [
            $this->ledgerKey(), $this->gateKey(), $operation, $generation, $runId,
            $entryId, $token, $payload, $states, $gateRevision, $nextToken,
        ], 2, $runId);
        if (!is_array($result) || !is_int($result[0] ?? null)) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
        }
        if ($result[0] < 0) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }

        return $result[0];
    }

    private function mutateRaw(
        string $operation,
        int $generation,
        string $runId,
        string $entryId,
        string $token,
        string $payload,
        string $states,
        int $gateRevision,
        string $nextToken,
    ): string {
        $result = $this->guardedEval(self::MUTATE_SCRIPT, [
            $this->ledgerKey(), $this->gateKey(), $operation, $generation, $runId,
            $entryId, $token, $payload, $states, $gateRevision, $nextToken,
        ], 2, $runId);
        if (!is_array($result) || ($result[0] ?? null) !== 1 || !is_string($result[1] ?? null)) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }

        return $result[1];
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
            $this->closeOwned($redis, $owned);
        }
    }

    private function connection(): object
    {
        return $this->redis instanceof UpgradeRedisConnectionFactory
            ? $this->redis->create()
            : $this->redis;
    }

    private function closeOwned(object $redis, bool $owned): void
    {
        if (!$owned || !method_exists($redis, 'close')) {
            return;
        }
        try {
            $redis->close();
        } catch (Throwable) {
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $raw): array
    {
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEDGER_INVALID');
        }

        return $value;
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_INPUT_INVALID');
        }
    }

    private function ledgerKey(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:activity-ledger';
    }

    private function gateKey(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:gate';
    }

    private function workerKey(): string
    {
        return 'mallbase:' . $this->namespace . ':upgrade:workers';
    }

    private function validRunId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/D', $value) === 1;
    }

    private function validName(string $value): bool
    {
        return preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $value) === 1;
    }
}
