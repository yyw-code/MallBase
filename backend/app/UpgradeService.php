<?php

declare(strict_types=1);

namespace app;

use app\cron\CronManager;
use app\middleware\admin\UpgradeAdminGateMiddleware;
use app\middleware\UpgradeTrafficGateMiddleware;
use app\queue\UpgradeAwareWorker;
use app\service\install\AgentHeartbeatClient;
use app\service\install\AgentHeartbeatPayloadFactory;
use app\service\install\AgentHeartbeatRunner;
use app\service\install\AgentInstanceConfigStore;
use app\service\install\AgentInstanceStateStore;
use app\service\install\AgentPlatformBootstrapService;
use app\service\install\InstallLockService;
use app\service\upgrade\SimpleDatabaseSnapshotService;
use app\service\upgrade\SimpleSqlMigrationService;
use app\service\upgrade\SimpleUpgradeGate;
use app\service\upgrade\SimpleUpgradeCliService;
use app\service\upgrade\SimpleUpgradeRuntimeService;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use PDO;
use think\App;
use think\Cache;
use think\Event;
use think\exception\Handle;
use think\Queue;
use think\Service;
use think\queue\Worker;

/**
 * 升级相关的最小容器绑定。
 */
final class UpgradeService extends Service
{
    public function register(): void
    {
        $this->bindAgentInfrastructure();
        $this->bindSimpleUpgradeInfrastructure();
    }

    private function bindAgentInfrastructure(): void
    {
        $this->app->bind([
            UpgradeSharedFileStore::class => function (): UpgradeSharedFileStore {
                return new UpgradeSharedFileStore(
                    root: (string) config('agent.upgrade_root', ''),
                    agentUid: (int) config('agent.agent_uid', -1),
                    expectedGid: (int) config('agent.expected_gid', -1),
                    phpEuid: (int) config('agent.php_euid', -1),
                    maxJsonBytes: (int) config('agent.max_json_bytes', 65536),
                    lockTimeoutMilliseconds: (int) config('agent.instance_lock_timeout_milliseconds', 2000),
                );
            },
            InstallLockService::class => static fn(): InstallLockService => new InstallLockService(),
            AgentInstanceConfigStore::class => function (App $app): AgentInstanceConfigStore {
                return new AgentInstanceConfigStore(
                    files: $app->make(UpgradeSharedFileStore::class),
                    activationProofLifetime: (int) config('agent.activation_proof_lifetime', 900),
                    componentSeenThrottle: (int) config('agent.component_seen_throttle', 3600),
                    legacyLockTimeoutMilliseconds: (int) config('agent.instance_lock_timeout_milliseconds', 2000),
                );
            },
            AgentInstanceStateStore::class => AgentInstanceConfigStore::class,
            AgentHeartbeatClient::class => function (): AgentHeartbeatClient {
                return new AgentHeartbeatRunner(
                    timeoutMilliseconds: (int) config('agent.heartbeat_timeout_milliseconds', 5000),
                );
            },
            AgentHeartbeatPayloadFactory::class => static fn(): AgentHeartbeatPayloadFactory =>
                AgentHeartbeatPayloadFactory::fromProjectRoot(),
            AgentPlatformBootstrapService::class => function (App $app): AgentPlatformBootstrapService {
                return new AgentPlatformBootstrapService(
                    instances: $app->make(AgentInstanceStateStore::class),
                    heartbeat: $app->make(AgentHeartbeatClient::class),
                    payloads: $app->make(AgentHeartbeatPayloadFactory::class),
                    legacy: $app->make(InstallLockService::class),
                );
            },
            UpgradeStrictJsonDecoder::class => static fn(): UpgradeStrictJsonDecoder =>
                new UpgradeStrictJsonDecoder(8192),
        ]);
    }

    private function bindSimpleUpgradeInfrastructure(): void
    {
        if (!(bool) config('upgrade.simple_gate_enabled', false)) {
            return;
        }

        $this->app->bind([
            SimpleUpgradeGate::class => static function (): SimpleUpgradeGate {
                $root = rtrim((string) config('agent.upgrade_root', ''), DIRECTORY_SEPARATOR);

                return new SimpleUpgradeGate($root . DIRECTORY_SEPARATOR . 'run');
            },
            UpgradeTrafficGateMiddleware::class => static function (App $app): UpgradeTrafficGateMiddleware {
                return new UpgradeTrafficGateMiddleware($app->make(SimpleUpgradeGate::class));
            },
            UpgradeAdminGateMiddleware::class => static function (App $app): UpgradeAdminGateMiddleware {
                return new UpgradeAdminGateMiddleware($app->make(SimpleUpgradeGate::class));
            },
            CronManager::class => static function (App $app): CronManager {
                return new CronManager($app->make(SimpleUpgradeGate::class));
            },
            SimpleDatabaseSnapshotService::class => function (): SimpleDatabaseSnapshotService {
                $database = $this->databaseConfiguration();
                $database['charset'] = (string) config('database.connections.mysql.charset', 'utf8mb4');

                return new SimpleDatabaseSnapshotService(
                    upgradeRoot: (string) config('agent.upgrade_root', ''),
                    dumpExecutable: (string) config('upgrade.dump_executable', '/usr/bin/mariadb-dump'),
                    restoreExecutable: (string) config('upgrade.restore_executable', '/usr/bin/mariadb'),
                    database: $database,
                );
            },
            SimpleSqlMigrationService::class => function (): SimpleSqlMigrationService {
                return new SimpleSqlMigrationService(
                    (string) config('agent.upgrade_root', ''),
                    fn(): PDO => $this->databasePdo(),
                );
            },
            SimpleUpgradeRuntimeService::class => function (App $app): SimpleUpgradeRuntimeService {
                return new SimpleUpgradeRuntimeService(
                    gate: $app->make(SimpleUpgradeGate::class),
                    database: $app->make(SimpleDatabaseSnapshotService::class),
                    migrations: $app->make(SimpleSqlMigrationService::class),
                );
            },
            SimpleUpgradeCliService::class => static function (App $app): SimpleUpgradeCliService {
                return new SimpleUpgradeCliService(
                    runtime: $app->make(SimpleUpgradeRuntimeService::class),
                    decoder: $app->make(UpgradeStrictJsonDecoder::class),
                );
            },
            Worker::class => function (App $app): Worker {
                return new UpgradeAwareWorker(
                    queue: $app->make(Queue::class),
                    event: $app->make(Event::class),
                    handle: $app->make(Handle::class),
                    cache: $app->make(Cache::class),
                    simpleGate: $app->make(SimpleUpgradeGate::class),
                );
            },
        ]);
    }

    /** @return array{host:string,port:int,user:string,password:string,database:string} */
    private function databaseConfiguration(): array
    {
        $configuration = (array) config('database.connections.mysql', []);

        return [
            'host' => (string) ($configuration['hostname'] ?? ''),
            'port' => (int) ($configuration['hostport'] ?? 3306),
            'user' => (string) ($configuration['username'] ?? ''),
            'password' => (string) ($configuration['password'] ?? ''),
            'database' => (string) ($configuration['database'] ?? ''),
        ];
    }

    private function databasePdo(): PDO
    {
        $configuration = $this->databaseConfiguration();
        $charset = (string) config('database.connections.mysql.charset', 'utf8mb4');
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/D', $charset) !== 1) {
            throw new \RuntimeException('UPGRADE_DATABASE_CONFIG_INVALID');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $configuration['host'],
            $configuration['port'],
            $configuration['database'],
            $charset,
        );

        return new PDO($dsn, $configuration['user'], $configuration['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
