<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentHeartbeatClient;
use app\service\install\AgentHeartbeatPayloadFactory;
use app\service\install\AgentHeartbeatResult;
use app\service\install\AgentInstanceStateStore;
use app\service\install\AgentPlatformBootstrapService;
use app\service\install\InstallLockService;
use app\service\install\PlatformReporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlatformReporterTest extends TestCase
{
    private string $lockPath;
    private string $versionPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = sys_get_temp_dir() . '/mallbase-platform-report-' . bin2hex(random_bytes(8)) . '.lock';
        $this->versionPath = sys_get_temp_dir() . '/mallbase-platform-version-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($this->versionPath, '{"version":"1.0.0"}');
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        @unlink($this->versionPath);
        parent::tearDown();
    }

    public function testUninstalledAndDisabledInstancesNeverSpawnHeartbeat(): void
    {
        $client = new ReporterQueueClient([]);
        $store = new ReporterMemoryStore($this->confirmed());
        $reporter = $this->reporter(new InstallLockService($this->lockPath), $store, $client);
        $reporter->tick();
        self::assertCount(0, $client->payloads);

        $legacy = $this->installedLock();
        $store->instance['disabled'] = true;
        $this->reporter($legacy, $store, $client)->tick();
        self::assertCount(0, $client->payloads);
        self::assertSame(0, $store->reservationCount);
    }

    public function testConfirmedInstanceUsesOneSharedReservationAndRecordsSuccess(): void
    {
        $legacy = $this->installedLock();
        $store = new ReporterMemoryStore($this->confirmed());
        $client = new ReporterQueueClient([
            new AgentHeartbeatResult(true, ReporterMemoryStore::INSTANCE_ID),
        ]);
        $reporter = $this->reporter($legacy, $store, $client);

        $reporter->tick('admin_web');
        $reporter->tick('admin_web');

        self::assertCount(1, $client->payloads);
        self::assertSame('mbt_token', $client->payloads[0]['token']);
        self::assertSame(1, $store->reservationCount);
        self::assertSame([
            'success' => true,
            'next' => 86400,
            'error' => '',
        ], $store->lastResult);
    }

    public function testHeartbeatFailureUsesStableShortRetryWithoutLeakingRunnerText(): void
    {
        $legacy = $this->installedLock();
        $store = new ReporterMemoryStore($this->confirmed());
        $client = new ReporterQueueClient([
            AgentHeartbeatResult::failure('secret platform token'),
        ]);

        $this->reporter($legacy, $store, $client)->tick('backend_php');

        self::assertSame([
            'success' => false,
            'next' => 300,
            'error' => 'AGENT_HEARTBEAT_FAILED',
        ], $store->lastResult);
    }

    public function testConcurrentHeartbeatIsTreatedAsASuccessfulSkip(): void
    {
        $store = new ReporterMemoryStore($this->confirmed());
        $client = new ReporterQueueClient([
            new AgentHeartbeatResult(true, skipped: 'heartbeat_active'),
        ]);

        $this->reporter($this->installedLock(), $store, $client)->tick();

        self::assertSame([
            'success' => true,
            'next' => 86400,
            'error' => '',
        ], $store->lastResult);
    }

    public function testActivationDelegatesToCrashSafeBootstrapAndDoesNotUseHttpTransport(): void
    {
        $legacy = $this->installedLock();
        $store = new ReporterMemoryStore($this->activating());
        $client = new ReporterQueueClient([
            new AgentHeartbeatResult(true, ReporterMemoryStore::INSTANCE_ID, 'mbt_token', 86400),
            new AgentHeartbeatResult(true, ReporterMemoryStore::INSTANCE_ID),
        ]);

        $this->reporter($legacy, $store, $client)->tick('backend_php');

        self::assertSame('confirmed', $store->instance['activation_state']);
        self::assertSame('mbt_token', $store->instance['token']);
        self::assertCount(2, $client->payloads);
        self::assertSame('', $client->payloads[0]['token']);
        self::assertSame('mbt_token', $client->payloads[1]['token']);
    }

    private function reporter(
        InstallLockService $legacy,
        ReporterMemoryStore $store,
        ReporterQueueClient $client,
    ): PlatformReporter {
        $payloads = new AgentHeartbeatPayloadFactory($this->versionPath, static fn(): array => []);
        $clock = static fn(): int => 1000;
        $bootstrap = new AgentPlatformBootstrapService($store, $client, $payloads, $legacy, $clock);

        return new PlatformReporter(
            $legacy,
            $store,
            $client,
            $payloads,
            $bootstrap,
            $clock,
            ['reservation_interval' => 60, 'report_interval' => 86400, 'retry_interval' => 300],
        );
    }

    private function installedLock(): InstallLockService
    {
        $legacy = new InstallLockService($this->lockPath);
        $legacy->writeInstalledLock('2026-07-13 12:00:00');

        return $legacy;
    }

    /** @return array<string,mixed> */
    private function confirmed(): array
    {
        $instance = $this->activating();
        $instance['activation_state'] = 'confirmed';
        $instance['token'] = 'mbt_token';
        $instance['activation_secret'] = '';
        $instance['activation_secret_expires_at'] = 0;

        return $instance;
    }

    /** @return array<string,mixed> */
    private function activating(): array
    {
        return [
            'schema_version' => 1,
            'revision' => 1,
            'instance_id' => ReporterMemoryStore::INSTANCE_ID,
            'token' => '',
            'activation_secret' => 'activation-proof',
            'activation_generation' => ReporterMemoryStore::GENERATION,
            'activation_secret_expires_at' => 1900,
            'activation_state' => 'activating',
            'disabled' => false,
            'components' => [],
            'report' => [],
            'updated_at' => 1000,
        ];
    }
}

final class ReporterQueueClient implements AgentHeartbeatClient
{
    /** @var list<array<string,mixed>> */
    public array $payloads = [];

    /** @param list<AgentHeartbeatResult> $results */
    public function __construct(private array $results)
    {
    }

    public function run(array $payload): AgentHeartbeatResult
    {
        $this->payloads[] = $payload;
        if ($this->results === []) {
            throw new RuntimeException('unexpected heartbeat');
        }

        return array_shift($this->results);
    }
}

final class ReporterMemoryStore implements AgentInstanceStateStore
{
    public const INSTANCE_ID = 'c6f83b5e-aadc-4a65-9c71-79a64aa22e58';
    public const GENERATION = '550e8400-e29b-41d4-a716-446655440000';

    public int $reservationCount = 0;

    /** @var array{success:bool,next:int,error:string}|null */
    public ?array $lastResult = null;

    private bool $reserved = false;

    /** @param array<string,mixed> $instance */
    public function __construct(public array $instance)
    {
    }

    public function load(): ?array
    {
        return $this->instance;
    }

    public function initializeFromLegacy(InstallLockService $legacy, int $now): array
    {
        return $this->instance;
    }

    public function reserveReportWindow(string $componentType, int $now, int $reservationSeconds): ?array
    {
        if ($this->reserved) {
            return null;
        }
        $this->reserved = true;
        $this->reservationCount++;
        $this->instance['revision']++;
        $this->instance['components'][$componentType] = $now;

        return [
            'reservation_id' => '018f5d35-3f42-7a31-a731-9e45df3356c2',
            'reservation_revision' => $this->instance['revision'],
            'instance' => $this->instance,
        ];
    }

    public function recordReportResult(string $reservationId, int $reservationRevision, bool $success, int $now, int $nextReportAfterSeconds, string $errorCode = ''): bool
    {
        $this->lastResult = ['success' => $success, 'next' => $nextReportAfterSeconds, 'error' => $errorCode];

        return true;
    }

    public function storeActivationResponse(string $generation, int $expectedRevision, string $instanceId, string $token, int $now): array
    {
        $this->instance['revision']++;
        $this->instance['token'] = $token;
        $this->instance['activation_state'] = 'confirming';

        return $this->instance;
    }

    public function confirmActivation(string $generation, int $expectedRevision, int $now): array
    {
        $this->instance['revision']++;
        $this->instance['activation_state'] = 'confirmed';
        $this->instance['activation_secret'] = '';
        $this->instance['activation_secret_expires_at'] = 0;

        return $this->instance;
    }

    public function markExpiredActivationRecoveryRequired(int $now): array
    {
        $this->instance['revision']++;
        $this->instance['activation_state'] = 'recovery_required';

        return $this->instance;
    }
}
