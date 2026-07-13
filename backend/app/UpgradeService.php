<?php

declare(strict_types=1);

namespace app;

use app\queue\UpgradeAwareWorker;
use app\service\upgrade\AgentVerifiedMountedStorageIdentityReader;
use app\service\upgrade\ConfiguredUpgradeRuntimeContext;
use app\service\upgrade\ConfiguredUpgradeRuntimeDeploymentInventory;
use app\service\upgrade\CoordinatedUpgradeRuntimeLifecycle;
use app\service\upgrade\FileUpgradeCheckpointRepository;
use app\service\upgrade\FileUpgradeDrainCheckpointRepository;
use app\service\upgrade\FileUpgradeRuntimeRegistry;
use app\service\upgrade\FileUpgradeRuntimeRetirementEvidenceStore;
use app\service\upgrade\ImmutableUpgradeRuntimeLockPool;
use app\service\upgrade\PhpRedisUpgradeConnectionFactory;
use app\service\upgrade\QueueInspector;
use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\RedisUpgradeActivityLedgerBackend;
use app\service\upgrade\RedisUpgradeActivityTracker;
use app\service\upgrade\RedisUpgradeGateRepository;
use app\service\upgrade\RedisUpgradeRuntimeHeartbeatStore;
use app\service\upgrade\RegistryUpgradeRuntimeOwnerLiveness;
use app\service\upgrade\ThinkQueueInspector;
use app\service\upgrade\UpgradeActivityLedgerBackend;
use app\service\upgrade\UpgradeActivityLedgerInitializer;
use app\service\upgrade\UpgradeActivityBootstrapper;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeDrainCheckpointRepository;
use app\service\upgrade\UpgradeDrainCoordinator;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeMountedStorageIdentityReader;
use app\service\upgrade\UpgradeRedisConnectionFactory;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeDeploymentInventory;
use app\service\upgrade\UpgradeRuntimeHeartbeatManager;
use app\service\upgrade\UpgradeRuntimeHeartbeatStore;
use app\service\upgrade\UpgradeRuntimeIdentityLoader;
use app\service\upgrade\UpgradeRuntimeLifecycle;
use app\service\upgrade\UpgradeRuntimeFailureLatch;
use app\service\upgrade\UpgradeRuntimeLockPool;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeRuntimeRecordLookup;
use app\service\upgrade\UpgradeRuntimeRecoveryCoordinator;
use app\service\upgrade\UpgradeRuntimeRegistrationCoordinator;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeRuntimeRetirementEvidenceStore;
use app\service\upgrade\UpgradeRuntimeRetirementGuard;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\VerifiedUpgradeRuntimeRetirementGuard;
use think\App;
use think\Cache;
use think\Event;
use think\Queue;
use think\Service;
use think\exception\Handle;
use think\queue\Worker;

/**
 * 升级运行设施只在配置加载完成且显式启用后进入容器。
 */
final class UpgradeService extends Service
{
    public function register(): void
    {
        if (!(bool) config('upgrade.enabled', false)) {
            return;
        }

        $this->app->bind([
            UpgradeRedisConnectionFactory::class => function (): UpgradeRedisConnectionFactory {
                $configuration = (array) config('cache.stores.redis', []);
                $configuredTimeout = (float) ($configuration['timeout'] ?? 0);
                $timeout = $configuredTimeout > 0 ? min($configuredTimeout, 60.0) : 2.0;

                return new PhpRedisUpgradeConnectionFactory(
                    host: (string) ($configuration['host'] ?? ''),
                    port: (int) ($configuration['port'] ?? 6379),
                    password: (string) ($configuration['password'] ?? ''),
                    database: (int) ($configuration['select'] ?? 0),
                    connectTimeout: $timeout,
                    readTimeout: $timeout,
                );
            },
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
            UpgradeMountedStorageIdentityReader::class => function (): UpgradeMountedStorageIdentityReader {
                return new AgentVerifiedMountedStorageIdentityReader(
                    timeoutMilliseconds: (int) config('agent.heartbeat_timeout_milliseconds', 5000),
                );
            },
            UpgradeRuntimeIdentityLoader::class => function (App $app): UpgradeRuntimeIdentityLoader {
                return new UpgradeRuntimeIdentityLoader(
                    mountedStorageIdentityReader: $app->make(UpgradeMountedStorageIdentityReader::class),
                    maxJsonBytes: (int) config('agent.max_json_bytes', 65536),
                );
            },
            FileUpgradeCheckpointRepository::class => function (App $app): FileUpgradeCheckpointRepository {
                return new FileUpgradeCheckpointRepository(
                    files: $app->make(UpgradeSharedFileStore::class),
                    identityProvider: static fn() => $app->make(UpgradeRuntimeIdentityLoader::class)->load(),
                );
            },
            UpgradeGateRepository::class => function (App $app): UpgradeGateRepository {
                return new RedisUpgradeGateRepository(
                    redis: $app->make(UpgradeRedisConnectionFactory::class),
                    checkpoints: $app->make(FileUpgradeCheckpointRepository::class),
                    namespace: (string) config('agent.upgrade_namespace_id', ''),
                );
            },
            UpgradeActivityLedgerBackend::class => function (App $app): UpgradeActivityLedgerBackend {
                return new RedisUpgradeActivityLedgerBackend(
                    redis: $app->make(UpgradeRedisConnectionFactory::class),
                    namespace: (string) config('agent.upgrade_namespace_id', ''),
                );
            },
            RedisServerIncarnation::class => function (App $app): RedisServerIncarnation {
                return new RedisServerIncarnation($app->make(UpgradeRedisConnectionFactory::class));
            },
            RedisUpgradeActivityTracker::class => function (App $app): RedisUpgradeActivityTracker {
                return new RedisUpgradeActivityTracker(
                    ledger: $app->make(UpgradeActivityLedgerBackend::class),
                    gate: $app->make(UpgradeGateRepository::class),
                    incarnation: $app->make(RedisServerIncarnation::class),
                );
            },
            UpgradeActivityTracker::class => RedisUpgradeActivityTracker::class,
            UpgradeActivityLedgerInitializer::class => RedisUpgradeActivityTracker::class,
            UpgradeActivityBootstrapper::class => function (App $app): UpgradeActivityBootstrapper {
                return new UpgradeActivityBootstrapper(
                    initializer: $app->make(UpgradeActivityLedgerInitializer::class),
                    gate: $app->make(UpgradeGateRepository::class),
                );
            },
            ThinkQueueInspector::class => function (App $app): ThinkQueueInspector {
                return new ThinkQueueInspector(
                    activity: $app->make(UpgradeActivityTracker::class),
                    connections: (array) config('upgrade.queue_connections', []),
                    queueNames: (array) config('upgrade.queue_names', []),
                );
            },
            QueueInspector::class => ThinkQueueInspector::class,
            FileUpgradeDrainCheckpointRepository::class => function (App $app): FileUpgradeDrainCheckpointRepository {
                return new FileUpgradeDrainCheckpointRepository($app->make(UpgradeSharedFileStore::class));
            },
            UpgradeDrainCheckpointRepository::class => FileUpgradeDrainCheckpointRepository::class,
            UpgradeDrainCoordinator::class => function (App $app): UpgradeDrainCoordinator {
                return new UpgradeDrainCoordinator(
                    gate: $app->make(UpgradeGateRepository::class),
                    activity: $app->make(UpgradeActivityTracker::class),
                    queues: $app->make(QueueInspector::class),
                    checkpoints: $app->make(UpgradeDrainCheckpointRepository::class),
                    ackTimeoutSeconds: (int) config('upgrade.pause_ack_timeout', 20),
                );
            },
            FileUpgradeRuntimeRegistry::class => function (App $app): FileUpgradeRuntimeRegistry {
                return new FileUpgradeRuntimeRegistry($app->make(UpgradeSharedFileStore::class));
            },
            UpgradeRuntimeRegistry::class => FileUpgradeRuntimeRegistry::class,
            UpgradeRuntimeRecordLookup::class => FileUpgradeRuntimeRegistry::class,
            UpgradeRuntimeHeartbeatStore::class => function (App $app): UpgradeRuntimeHeartbeatStore {
                return new RedisUpgradeRuntimeHeartbeatStore(
                    redis: $app->make(UpgradeRedisConnectionFactory::class),
                    namespace: (string) config('agent.upgrade_namespace_id', ''),
                );
            },
            UpgradeRuntimeContext::class => function (App $app): UpgradeRuntimeContext {
                return new ConfiguredUpgradeRuntimeContext(
                    identityLoader: $app->make(UpgradeRuntimeIdentityLoader::class),
                    gate: $app->make(UpgradeGateRepository::class),
                );
            },
            ImmutableUpgradeRuntimeLockPool::class => function (): ImmutableUpgradeRuntimeLockPool {
                $root = rtrim((string) config('agent.upgrade_root', ''), DIRECTORY_SEPARATOR);

                return ImmutableUpgradeRuntimeLockPool::fromManifest(
                    $root . '/lifetime-locks/manifest.json',
                    allocationTimeoutMilliseconds: (int) config('agent.instance_lock_timeout_milliseconds', 2000),
                );
            },
            UpgradeRuntimeLockPool::class => ImmutableUpgradeRuntimeLockPool::class,
            UpgradeRuntimeDeploymentInventory::class => function (): UpgradeRuntimeDeploymentInventory {
                return new ConfiguredUpgradeRuntimeDeploymentInventory(
                    (array) config('upgrade.required_runtime_roles', ['http']),
                );
            },
            FileUpgradeRuntimeRetirementEvidenceStore::class => function (App $app): FileUpgradeRuntimeRetirementEvidenceStore {
                return new FileUpgradeRuntimeRetirementEvidenceStore(
                    $app->make(UpgradeSharedFileStore::class),
                );
            },
            UpgradeRuntimeRetirementEvidenceStore::class => FileUpgradeRuntimeRetirementEvidenceStore::class,
            UpgradeRuntimeOwnerLiveness::class => function (App $app): UpgradeRuntimeOwnerLiveness {
                return new RegistryUpgradeRuntimeOwnerLiveness(
                    $app->make(UpgradeRuntimeRecordLookup::class),
                );
            },
            UpgradeRuntimeRetirementGuard::class => function (App $app): UpgradeRuntimeRetirementGuard {
                return new VerifiedUpgradeRuntimeRetirementGuard(
                    runtimes: $app->make(UpgradeRuntimeRegistry::class),
                    heartbeats: $app->make(UpgradeRuntimeHeartbeatStore::class),
                    evidence: $app->make(UpgradeRuntimeRetirementEvidenceStore::class),
                    locks: $app->make(UpgradeRuntimeLockPool::class),
                    incarnation: $app->make(RedisServerIncarnation::class),
                    windowSeconds: (int) config('upgrade.runtime_retirement_window', 15),
                    records: $app->make(UpgradeRuntimeRecordLookup::class),
                );
            },
            UpgradeRuntimeRecoveryCoordinator::class => function (App $app): UpgradeRuntimeRecoveryCoordinator {
                return new UpgradeRuntimeRecoveryCoordinator(
                    gate: $app->make(UpgradeGateRepository::class),
                    runtimes: $app->make(UpgradeRuntimeRegistry::class),
                    ledger: $app->make(UpgradeActivityLedgerBackend::class),
                    incarnation: $app->make(RedisServerIncarnation::class),
                    deployment: $app->make(UpgradeRuntimeDeploymentInventory::class),
                    retirement: $app->make(UpgradeRuntimeRetirementGuard::class),
                    evidence: $app->make(UpgradeRuntimeRetirementEvidenceStore::class),
                    records: $app->make(UpgradeRuntimeRecordLookup::class),
                    activity: $app->make(UpgradeActivityTracker::class),
                    queues: $app->make(QueueInspector::class),
                    owners: $app->make(UpgradeRuntimeOwnerLiveness::class),
                );
            },
            UpgradeRuntimeRegistrationCoordinator::class => function (App $app): UpgradeRuntimeRegistrationCoordinator {
                return new UpgradeRuntimeRegistrationCoordinator(
                    runtimes: $app->make(UpgradeRuntimeRegistry::class),
                    gate: $app->make(UpgradeGateRepository::class),
                );
            },
            UpgradeRuntimeHeartbeatManager::class => function (App $app): UpgradeRuntimeHeartbeatManager {
                return new UpgradeRuntimeHeartbeatManager(
                    registry: $app->make(UpgradeRuntimeRegistry::class),
                    gate: $app->make(UpgradeGateRepository::class),
                    heartbeats: $app->make(UpgradeRuntimeHeartbeatStore::class),
                    ttl: (int) config('upgrade.runtime_owner_heartbeat_ttl', 15),
                );
            },
            CoordinatedUpgradeRuntimeLifecycle::class => function (App $app): CoordinatedUpgradeRuntimeLifecycle {
                return new CoordinatedUpgradeRuntimeLifecycle(
                    runtime: $app->make(UpgradeRuntimeContext::class),
                    registry: $app->make(UpgradeRuntimeRegistry::class),
                    lockPool: $app->make(ImmutableUpgradeRuntimeLockPool::class),
                    registration: $app->make(UpgradeRuntimeRegistrationCoordinator::class),
                    heartbeats: $app->make(UpgradeRuntimeHeartbeatManager::class),
                    queueNames: (array) config('upgrade.queue_names', []),
                );
            },
            UpgradeRuntimeLifecycle::class => CoordinatedUpgradeRuntimeLifecycle::class,
            UpgradeRuntimeFailureLatch::class => function (App $app): UpgradeRuntimeFailureLatch {
                return new UpgradeRuntimeFailureLatch(
                    gate: $app->make(UpgradeGateRepository::class),
                    runtime: $app->make(UpgradeRuntimeContext::class),
                    runtimes: $app->make(UpgradeRuntimeRegistry::class),
                );
            },
            Worker::class => function (App $app): Worker {
                return new UpgradeAwareWorker(
                    queue: $app->make(Queue::class),
                    event: $app->make(Event::class),
                    handle: $app->make(Handle::class),
                    cache: $app->make(Cache::class),
                    upgradeGate: $app->make(UpgradeGateRepository::class),
                    upgradeActivity: $app->make(UpgradeActivityTracker::class),
                    upgradeRuntime: $app->make(UpgradeRuntimeContext::class),
                    upgradeLifecycle: $app->make(UpgradeRuntimeLifecycle::class),
                    upgradeEnabled: true,
                );
            },
        ]);
    }

    public function boot(): void
    {
        if (!(bool) config('upgrade.enabled', false)) {
            return;
        }
        $ready = $this->app->make(UpgradeActivityBootstrapper::class)->initialize();
        if (!$ready) {
            defined('MALLBASE_AUTOMATIC_UPGRADE_DISABLED')
                || define('MALLBASE_AUTOMATIC_UPGRADE_DISABLED', true);
            fwrite(STDERR, "[MallBase Upgrade] 活动账本初始化失败，已持久化安全闩；商业服务继续运行。\n");
        }
    }
}
