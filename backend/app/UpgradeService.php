<?php

declare(strict_types=1);

namespace app;

use app\queue\UpgradeAwareWorker;
use app\service\admin\upgrade\UpgradeSessionService;
use app\service\install\AgentHeartbeatClient;
use app\service\install\AgentHeartbeatPayloadFactory;
use app\service\install\AgentHeartbeatRunner;
use app\service\install\AgentInstanceConfigStore;
use app\service\install\AgentInstanceStateStore;
use app\service\install\AgentPlatformBootstrapService;
use app\service\install\InstallLockService;
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
use app\service\upgrade\UpgradeDrainControl;
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
use app\service\upgrade\UpgradeJobControlService;
use app\service\upgrade\DatabaseBackupService;
use app\service\upgrade\FileMigrationRegistry;
use app\service\upgrade\LocalUploadRootPolicy;
use app\service\upgrade\PersistentStateVerificationService;
use app\service\upgrade\SchemaMigrationService;
use app\service\upgrade\UpgradeAgentNonceStore;
use app\service\upgrade\UpgradeAgentRuntimePolicy;
use app\service\upgrade\UpgradeMigrationAdvisoryLock;
use app\service\upgrade\UpgradeMigrationPlanRegistry;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradePaymentReconciliationService;
use app\service\upgrade\UpgradePaymentReconciliationStore;
use app\service\upgrade\UpgradePlatformReceiptService;
use app\service\upgrade\UpgradeProcessSupervisor;
use app\service\upgrade\UpgradeRuntimeFenceService;
use app\service\upgrade\UpgradeWritableSurfaceAuditService;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use app\service\client\payment\WechatPaymentResultService;
use app\service\admin\order\RefundOrderAdminService;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeControlRateLimiter;
use app\service\upgrade\UpgradeRecoveryCapabilityService;
use app\service\upgrade\UpgradeSameOriginPolicy;
use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use app\service\upgrade\UpgradeViewService;
use app\service\upgrade\VerifiedUpgradeRuntimeRetirementGuard;
use think\App;
use think\Cache;
use think\Event;
use think\Queue;
use think\Service;
use think\exception\Handle;
use think\queue\Worker;
use PDO;

/**
 * 升级运行设施只在配置加载完成且显式启用后进入容器。
 */
final class UpgradeService extends Service
{
    public function register(): void
    {
        $this->bindSessionInfrastructure();

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
            UpgradeDrainControl::class => UpgradeDrainCoordinator::class,
            UpgradeJobControlService::class => function (App $app): UpgradeJobControlService {
                return new UpgradeJobControlService(
                    files: $app->make(UpgradeSharedFileStore::class),
                    sessions: $app->make(UpgradeSessionAuthStore::class),
                    gate: $app->make(UpgradeGateRepository::class),
                    drain: $app->make(UpgradeDrainControl::class),
                    callbackBaseUrl: (string) config('upgrade.public_origin', ''),
                );
            },
            UpgradeAgentNonceStore::class => function (App $app): UpgradeAgentNonceStore {
                return new UpgradeAgentNonceStore(
                    redis: $app->make(UpgradeRedisConnectionFactory::class),
                    namespace: (string) config('agent.upgrade_namespace_id', ''),
                    lifetimeSeconds: (int) config('upgrade.agent_nonce_lifetime', 300),
                );
            },
            UpgradeAgentRuntimePolicy::class => function (App $app): UpgradeAgentRuntimePolicy {
                return new UpgradeAgentRuntimePolicy(
                    gate: $app->make(UpgradeGateRepository::class),
                    identity: $app->make(UpgradeRuntimeIdentityLoader::class),
                    operations: $app->make(UpgradeOperationStore::class),
                );
            },
            UpgradeOperationStore::class => function (App $app): UpgradeOperationStore {
                return new UpgradeOperationStore(
                    files: $app->make(UpgradeSharedFileStore::class),
                    ownerWindowSeconds: (int) config('upgrade.operation_owner_window', 15),
                );
            },
            UpgradePlatformReceiptService::class => function (App $app): UpgradePlatformReceiptService {
                return new UpgradePlatformReceiptService(
                    operations: $app->make(UpgradeOperationStore::class),
                    gate: $app->make(UpgradeGateRepository::class),
                );
            },
            UpgradeProcessSupervisor::class => function (): UpgradeProcessSupervisor {
                return new UpgradeProcessSupervisor(
                    setprivPath: (string) config('upgrade.setpriv_executable', '/usr/bin/setpriv'),
                    phpPath: PHP_BINARY,
                );
            },
            DatabaseBackupService::class => function (App $app): DatabaseBackupService {
                $database = $this->databaseConfiguration();
                $root = rtrim((string) config('agent.upgrade_root', ''), DIRECTORY_SEPARATOR);

                return new DatabaseBackupService(
                    operations: $app->make(UpgradeOperationStore::class),
                    processes: $app->make(UpgradeProcessSupervisor::class),
                    backupRoot: $root . '/backups',
                    dumpExecutable: (string) config('upgrade.dump_executable', '/usr/bin/mariadb-dump'),
                    database: $database,
                );
            },
            FileMigrationRegistry::class => function (App $app): FileMigrationRegistry {
                return new FileMigrationRegistry($app->make(UpgradeSharedFileStore::class));
            },
            UpgradeMigrationAdvisoryLock::class => function (): UpgradeMigrationAdvisoryLock {
                return new UpgradeMigrationAdvisoryLock(
                    connection: fn(): PDO => $this->databasePdo(),
                    namespace: (string) config('agent.upgrade_namespace_id', ''),
                    timeoutSeconds: (int) config('upgrade.migration_lock_timeout', 2),
                );
            },
            SchemaMigrationService::class => function (App $app): SchemaMigrationService {
                return new SchemaMigrationService(
                    registry: $app->make(FileMigrationRegistry::class),
                    advisoryLock: $app->make(UpgradeMigrationAdvisoryLock::class),
                );
            },
            UpgradeMigrationPlanRegistry::class => function (): UpgradeMigrationPlanRegistry {
                $root = rtrim((string) config('agent.upgrade_root', ''), DIRECTORY_SEPARATOR);

                return new UpgradeMigrationPlanRegistry($root . '/staging');
            },
            PersistentStateVerificationService::class => function (App $app): PersistentStateVerificationService {
                return new PersistentStateVerificationService(
                    operations: $app->make(UpgradeOperationStore::class),
                    roots: (array) config('upgrade.persistent_roots', []),
                );
            },
            UpgradeRuntimeFenceService::class => function (App $app): UpgradeRuntimeFenceService {
                return new UpgradeRuntimeFenceService(
                    operations: $app->make(UpgradeOperationStore::class),
                    gate: $app->make(UpgradeGateRepository::class),
                );
            },
            UpgradeWritableSurfaceAuditService::class => function (): UpgradeWritableSurfaceAuditService {
                return new UpgradeWritableSurfaceAuditService(
                    policy: new LocalUploadRootPolicy(),
                    publicRoot: rtrim(public_path(), DIRECTORY_SEPARATOR),
                    localRootProvider: static fn(): string => (string) getSystemSetting('local_root_path', 'uploads'),
                );
            },
            UpgradePaymentReconciliationStore::class => function (): UpgradePaymentReconciliationStore {
                $root = rtrim((string) config('agent.upgrade_root', ''), DIRECTORY_SEPARATOR) . '/jobs';

                return new UpgradePaymentReconciliationStore($root);
            },
            UpgradePaymentReconciliationService::class => function (App $app): UpgradePaymentReconciliationService {
                return new UpgradePaymentReconciliationService(
                    store: $app->make(UpgradePaymentReconciliationStore::class),
                    activity: $app->make(UpgradeActivityTracker::class),
                    factory: $app->make(WechatPayFactory::class),
                    client: $app->make(WechatPayClient::class),
                    paymentResults: $app->make(WechatPaymentResultService::class),
                    refundResults: $app->make(RefundOrderAdminService::class),
                    clock: static fn(): int => time(),
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
            ...($this->isIsolatedMaintenanceRole() ? [] : [
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
            ]),
        ]);
    }

    private function bindSessionInfrastructure(): void
    {
        // The session endpoint is what tells the operator how to start the
        // foreground Agent, so these bindings must exist before runtime enable.
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
            InstallLockService::class => static fn(): InstallLockService => new InstallLockService(),
            AgentInstanceConfigStore::class => function (App $app): AgentInstanceConfigStore {
                return new AgentInstanceConfigStore(
                    files: $app->make(UpgradeSharedFileStore::class),
                    platformOrigin: (string) config('agent.platform_origin', ''),
                    upgradeNamespaceId: (string) config('agent.upgrade_namespace_id', ''),
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
            UpgradeSessionAuthStore::class => function (App $app): UpgradeSessionAuthStore {
                return new UpgradeSessionAuthStore($app->make(UpgradeSharedFileStore::class));
            },
            UpgradeSessionService::class => function (App $app): UpgradeSessionService {
                return new UpgradeSessionService(
                    instances: $app->make(AgentInstanceConfigStore::class),
                    legacy: $app->make(InstallLockService::class),
                    platform: $app->make(AgentPlatformBootstrapService::class),
                    sessions: $app->make(UpgradeSessionAuthStore::class),
                );
            },
            UpgradeRecoveryCapabilityService::class => function (App $app): UpgradeRecoveryCapabilityService {
                return new UpgradeRecoveryCapabilityService(
                    instances: $app->make(AgentInstanceConfigStore::class),
                    sessions: $app->make(UpgradeSessionAuthStore::class),
                );
            },
            UpgradeSameOriginPolicy::class => static fn(): UpgradeSameOriginPolicy =>
                new UpgradeSameOriginPolicy((string) config('upgrade.public_origin', '')),
            UpgradeStrictJsonDecoder::class => static fn(): UpgradeStrictJsonDecoder => new UpgradeStrictJsonDecoder(8192),
            UpgradeControlRateLimiter::class => function (App $app): UpgradeControlRateLimiter {
                return new UpgradeControlRateLimiter(
                    redis: $app->make(UpgradeRedisConnectionFactory::class),
                    namespace: (string) config('agent.upgrade_namespace_id', ''),
                );
            },
            UpgradeViewService::class => function (App $app): UpgradeViewService {
                return new UpgradeViewService(
                    files: $app->make(UpgradeSharedFileStore::class),
                    sessions: $app->make(UpgradeSessionAuthStore::class),
                    jobStatusReader: static fn(string $jobId): ?array => (bool) config('upgrade.enabled', false)
                        ? $app->make(UpgradeJobControlService::class)->status($jobId)
                        : null,
                );
            },
        ]);
    }

    public function boot(): void
    {
        if (!(bool) config('upgrade.enabled', false)) {
            return;
        }
        // 隔离维护命令不注册为正常 HTTP/queue/cron 运行时，也不参与
        // 活动账本引导；它们只使用各自命令所需的明确服务绑定。
        if ($this->isIsolatedMaintenanceRole()) {
            return;
        }
        $ready = $this->app->make(UpgradeActivityBootstrapper::class)->initialize();
        if (!$ready) {
            defined('MALLBASE_AUTOMATIC_UPGRADE_DISABLED')
                || define('MALLBASE_AUTOMATIC_UPGRADE_DISABLED', true);
            fwrite(STDERR, "[MallBase Upgrade] 活动账本初始化失败，已持久化安全闩；商业服务继续运行。\n");
        }
    }

    private function isIsolatedMaintenanceRole(): bool
    {
        return in_array((string) getenv('MALLBASE_RUNTIME_ROLE'), [
            'target-verify',
            'bootstrap-retention-finalize',
        ], true);
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
