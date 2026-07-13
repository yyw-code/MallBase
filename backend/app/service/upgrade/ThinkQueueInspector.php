<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use JsonException;
use Redis;
use Throwable;
use think\facade\Db;

final class ThinkQueueInspector implements QueueInspector
{
    private const MAXIMUM_ENTRIES = 1_000_000;

    /** @var list<string>|null */
    private readonly ?array $connections;

    /** @var list<string>|null */
    private readonly ?array $queueNames;

    /** @var Closure(string):array<string,mixed> */
    private readonly Closure $configProvider;

    /** @var Closure(string,array<string,mixed>,list<string>):array<string,list<array{queue:string,payload:string}>> */
    private readonly Closure $redisReader;

    /** @var Closure(string,array<string,mixed>,list<string>):list<array<string,mixed>> */
    private readonly Closure $databaseReader;

    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly ?UpgradeActivityTracker $activity = null,
        ?array $connections = null,
        ?array $queueNames = null,
        ?Closure $configProvider = null,
        ?Closure $redisReader = null,
        ?Closure $databaseReader = null,
        ?Closure $clock = null,
    ) {
        $this->connections = $connections === null ? null : $this->normalizeNames($connections);
        $this->queueNames = $queueNames === null ? null : $this->normalizeNames($queueNames);
        $this->configProvider = $configProvider ?? static function (string $connection): array {
            $value = config('queue.connections.' . $connection, []);

            return is_array($value) ? $value : [];
        };
        $this->redisReader = $redisReader ?? $this->readRedis(...);
        $this->databaseReader = $databaseReader ?? $this->readDatabase(...);
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function inventory(): UpgradeQueueInventory
    {
        $ready = [];
        $reserved = [];
        $delayed = [];
        $unsupported = [];
        $totalEntries = 0;
        $connections = $this->effectiveConnections();
        $configProvider = $this->configProvider;
        $configs = [];
        foreach ($connections as $connection) {
            try {
                $config = $configProvider($connection);
            } catch (Throwable) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
            }
            if (!is_array($config) || array_is_list($config)) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            $configs[$connection] = $config;
        }
        $queues = $this->effectiveQueueNames($configs);

        foreach ($connections as $connection) {
            $config = $configs[$connection];
            $type = strtolower(trim((string) ($config['type'] ?? '')));
            switch ($type) {
                case 'sync':
                    break;
                case 'redis':
                    $reader = $this->redisReader;
                    try {
                        $rows = $reader($connection, $config, $queues);
                    } catch (UpgradeStateConflict $exception) {
                        throw $exception;
                    } catch (Throwable) {
                        throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
                    }
                    $this->appendBounded(
                        $ready,
                        $this->parseRedisRows($connection, $rows, 'ready'),
                        $totalEntries,
                    );
                    $this->appendBounded(
                        $reserved,
                        $this->parseRedisRows($connection, $rows, 'reserved'),
                        $totalEntries,
                    );
                    $this->appendBounded(
                        $delayed,
                        $this->parseRedisRows($connection, $rows, 'delayed'),
                        $totalEntries,
                    );
                    break;
                case 'database':
                    $reader = $this->databaseReader;
                    try {
                        $rows = $reader($connection, $config, $queues);
                    } catch (UpgradeStateConflict $exception) {
                        throw $exception;
                    } catch (Throwable) {
                        throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
                    }
                    [$connectionReady, $connectionReserved, $connectionDelayed] = $this->parseDatabaseRows(
                        $connection,
                        $rows,
                    );
                    $this->appendBounded($ready, $connectionReady, $totalEntries);
                    $this->appendBounded($reserved, $connectionReserved, $totalEntries);
                    $this->appendBounded($delayed, $connectionDelayed, $totalEntries);
                    break;
                default:
                    $unsupported[] = [
                        'connection' => $connection,
                        'reason' => 'UNSUPPORTED_QUEUE_DRIVER',
                    ];
            }
        }

        return new UpgradeQueueInventory(
            $this->normalizeEntries($ready),
            $this->normalizeEntries($reserved),
            $this->normalizeEntries($delayed),
            $this->normalizeUnsupported($unsupported),
        );
    }

    /** @return list<string> */
    private function effectiveConnections(): array
    {
        if ($this->connections !== null) {
            return $this->connections;
        }
        try {
            $configured = config('upgrade.queue_connections', []);
            if (is_array($configured) && $configured !== []) {
                return $this->normalizeNames($configured);
            }
            $default = (string) config('queue.default', 'sync');

            return $this->normalizeNames([$default]);
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
        }
    }

    /** @param array<string,array<string,mixed>> $configs @return list<string> */
    private function effectiveQueueNames(array $configs): array
    {
        if ($this->queueNames !== null) {
            $names = $this->queueNames;
        } else {
            try {
                $configured = config('upgrade.queue_names', ['default']);
                $names = is_array($configured) && $configured !== []
                    ? $this->normalizeNames($configured)
                    : [];
            } catch (Throwable) {
                $names = [];
            }
        }
        foreach ($configs as $config) {
            if (array_key_exists('queue', $config)) {
                $names[] = $config['queue'];
            }
        }
        if ($this->activity !== null) {
            try {
                foreach ($this->activity->liveWorkers() as $worker) {
                    if (($worker['expired'] ?? false) === true) {
                        continue;
                    }
                    $workerQueues = $worker['queues'] ?? null;
                    if (!is_array($workerQueues) || !array_is_list($workerQueues)) {
                        throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
                    }
                    $names = [...$names, ...$workerQueues];
                }
            } catch (UpgradeStateConflict $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
            }
        }

        return $this->normalizeNames($names === [] ? ['default'] : $names);
    }

    /** @param array<string,list<array{queue:string,payload:string}>> $rows
     *  @return list<array{connection:string,queue:string,job_id:string}>
     */
    private function parseRedisRows(string $connection, array $rows, string $phase): array
    {
        if (array_keys($rows) !== ['ready', 'reserved', 'delayed'] || !is_array($rows[$phase])
            || !array_is_list($rows[$phase]) || count($rows[$phase]) > self::MAXIMUM_ENTRIES) {
            throw new UpgradeStateConflict(count($rows[$phase] ?? []) > self::MAXIMUM_ENTRIES
                ? 'UPGRADE_QUEUE_INVENTORY_TOO_LARGE'
                : 'UPGRADE_QUEUE_INVENTORY_INVALID');
        }
        $result = [];
        foreach ($rows[$phase] as $row) {
            if (!is_array($row) || array_keys($row) !== ['queue', 'payload']
                || !$this->validName($row['queue']) || !is_string($row['payload'])) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            try {
                $payload = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            if (!is_array($payload) || array_is_list($payload)
                || !isset($payload['id']) || !(is_string($payload['id']) || is_int($payload['id']))) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            $jobId = (string) $payload['id'];
            if (!$this->validName($jobId)) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            $result[] = ['connection' => $connection, 'queue' => $row['queue'], 'job_id' => $jobId];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $rows
     *  @return array{list<array{connection:string,queue:string,job_id:string}>,list<array{connection:string,queue:string,job_id:string}>,list<array{connection:string,queue:string,job_id:string}>}
     */
    private function parseDatabaseRows(string $connection, array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > self::MAXIMUM_ENTRIES) {
            throw new UpgradeStateConflict(count($rows) > self::MAXIMUM_ENTRIES
                ? 'UPGRADE_QUEUE_INVENTORY_TOO_LARGE'
                : 'UPGRADE_QUEUE_INVENTORY_INVALID');
        }
        $ready = [];
        $reserved = [];
        $delayed = [];
        $clock = $this->clock;
        $now = $clock();
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['id', 'queue', 'reserve_time', 'available_time']
                || !(is_int($row['id']) || is_string($row['id'])) || !$this->validName((string) $row['id'])
                || !$this->validName($row['queue']) || (!is_null($row['reserve_time']) && !is_int($row['reserve_time']))
                || !is_int($row['available_time'])) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            $entry = [
                'connection' => $connection,
                'queue' => $row['queue'],
                'job_id' => (string) $row['id'],
            ];
            if ($row['reserve_time'] !== null) {
                $reserved[] = $entry;
            } elseif ($row['available_time'] <= $now) {
                $ready[] = $entry;
            } else {
                $delayed[] = $entry;
            }
        }

        return [$ready, $reserved, $delayed];
    }

    /** @return array{ready:list<array{queue:string,payload:string}>,reserved:list<array{queue:string,payload:string}>,delayed:list<array{queue:string,payload:string}>} */
    private function readRedis(string $connection, array $config, array $queues): array
    {
        unset($connection);
        if (!extension_loaded('redis')) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
        }
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 6379);
        $configuredTimeout = (float) ($config['timeout'] ?? 1);
        $timeout = $configuredTimeout > 0 ? min($configuredTimeout, 60.0) : 1.0;
        $select = (int) ($config['select'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535 || $select < 0) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
        }
        $redis = new Redis();
        try {
            if (defined('Redis::OPT_MAX_RETRIES')) {
                $redis->setOption(Redis::OPT_MAX_RETRIES, 0);
            }
            if (!$redis->connect($host, $port, $timeout, null, 0, $timeout)) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
            }
            $password = $config['password'] ?? '';
            if ($password !== '' && (!$redis->auth($password))) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
            }
            if ($select !== 0 && !$redis->select($select)) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
            }
            $result = ['ready' => [], 'reserved' => [], 'delayed' => []];
            $script = <<<'LUA'
local maximum = tonumber(ARGV[1])
local ready = redis.call('LRANGE', KEYS[1], 0, maximum)
local reserved = redis.call('ZRANGE', KEYS[2], 0, maximum)
local delayed = redis.call('ZRANGE', KEYS[3], 0, maximum)
if #ready > maximum or #reserved > maximum or #delayed > maximum then
    return redis.error_reply('QUEUE_INVENTORY_TOO_LARGE')
end
return {ready, reserved, delayed}
LUA;
            foreach ($queues as $queue) {
                $key = '{queues:' . $queue . '}';
                $rows = $redis->eval(
                    $script,
                    [$key, $key . ':reserved', $key . ':delayed', self::MAXIMUM_ENTRIES],
                    3,
                );
                if (!is_array($rows) || count($rows) !== 3) {
                    throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
                }
                foreach (['ready', 'reserved', 'delayed'] as $index => $phase) {
                    if (!is_array($rows[$index]) || !array_is_list($rows[$index])) {
                        throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
                    }
                    foreach ($rows[$index] as $payload) {
                        if (!is_string($payload)) {
                            throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
                        }
                        $result[$phase][] = ['queue' => $queue, 'payload' => $payload];
                        if (count($result['ready']) + count($result['reserved']) + count($result['delayed'])
                            > self::MAXIMUM_ENTRIES) {
                            throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_TOO_LARGE');
                        }
                    }
                }
            }

            return $result;
        } catch (UpgradeStateConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'QUEUE_INVENTORY_TOO_LARGE')) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_TOO_LARGE');
            }
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
        } finally {
            try {
                $redis->close();
            } catch (Throwable) {
            }
        }
    }

    /** @param list<mixed> $target @param list<mixed> $entries */
    private function appendBounded(array &$target, array $entries, int &$totalEntries): void
    {
        $incoming = count($entries);
        if ($incoming > self::MAXIMUM_ENTRIES - $totalEntries) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_TOO_LARGE');
        }
        foreach ($entries as $entry) {
            $target[] = $entry;
        }
        $totalEntries += $incoming;
    }

    /** @return list<array{id:int|string,queue:string,reserve_time:int|null,available_time:int}> */
    private function readDatabase(string $connection, array $config, array $queues): array
    {
        unset($connection);
        $table = trim((string) ($config['table'] ?? ''));
        if ($table === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
        }
        try {
            $rows = Db::connect($config['connection'] ?? null)
                ->name($table)
                ->whereIn('queue', $queues)
                ->field(['id', 'queue', 'reserve_time', 'available_time'])
                ->limit(self::MAXIMUM_ENTRIES + 1)
                ->select()
                ->toArray();
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INSPECTION_UNAVAILABLE');
        }

        return $rows;
    }

    /** @param array<int,mixed> $names @return list<string> */
    private function normalizeNames(array $names): array
    {
        if (!array_is_list($names) || $names === [] || count($names) > 100) {
            throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
        }
        $result = [];
        foreach ($names as $name) {
            if (!$this->validName($name)) {
                throw new UpgradeStateConflict('UPGRADE_QUEUE_INVENTORY_INVALID');
            }
            $result[$name] = true;
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param list<array{connection:string,queue:string,job_id:string}> $entries
     *  @return list<array{connection:string,queue:string,job_id:string}>
     */
    private function normalizeEntries(array $entries): array
    {
        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$entry['connection'] . "\0" . $entry['queue'] . "\0" . $entry['job_id']] = $entry;
        }
        ksort($indexed, SORT_STRING);

        return array_values($indexed);
    }

    /** @param list<array{connection:string,reason:string}> $entries
     *  @return list<array{connection:string,reason:string}>
     */
    private function normalizeUnsupported(array $entries): array
    {
        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$entry['connection']] = $entry;
        }
        ksort($indexed, SORT_STRING);

        return array_values($indexed);
    }

    private function validName(mixed $value): bool
    {
        return is_string($value) && preg_match('~^[0-9A-Za-z_.:/-]{1,255}$~D', $value) === 1;
    }
}
