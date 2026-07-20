<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentHeartbeatClient;
use app\service\install\AgentHeartbeatPayloadFactory;
use app\service\install\AgentHeartbeatResult;
use app\service\install\AgentInstanceStateStore;
use app\service\install\AgentPlatformBootstrapService;
use app\service\install\InstallLockService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentPlatformBootstrapServiceTest extends TestCase
{
    private string $versionPath;
    private string $legacyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versionPath = sys_get_temp_dir() . '/mallbase-bootstrap-version-' . bin2hex(random_bytes(8));
        $this->legacyPath = sys_get_temp_dir() . '/mallbase-bootstrap-lock-' . bin2hex(random_bytes(8));
        file_put_contents($this->versionPath, '{"version":"1.0.0"}');
    }

    protected function tearDown(): void
    {
        @unlink($this->versionPath);
        @unlink($this->legacyPath);
        parent::tearDown();
    }

    public function testFreshActivationIsPersistedThenConfirmedBySecondHeartbeat(): void
    {
        $store = new BootstrapMemoryStore($this->activating());
        $client = new BootstrapQueueClient([
            new AgentHeartbeatResult(true, BootstrapMemoryStore::INSTANCE_ID, 'mbt_token', 86400),
            new AgentHeartbeatResult(true, BootstrapMemoryStore::INSTANCE_ID),
        ]);

        $result = $this->service($store, $client)->ensureConnected();

        self::assertTrue($result->ok);
        self::assertSame('confirmed', $store->instance['activation_state']);
        self::assertSame('', $store->instance['activation_secret']);
        self::assertSame('mbt_token', $store->instance['token']);
        self::assertCount(2, $client->payloads);
        self::assertSame('', $client->payloads[0]['token']);
        self::assertSame('activation-proof', $client->payloads[0]['activation_secret']);
        self::assertSame('mbt_token', $client->payloads[1]['token']);
    }

    public function testConfirmingCheckpointResumesWithoutSecondActivation(): void
    {
        $instance = $this->activating();
        $instance['revision'] = 2;
        $instance['activation_state'] = 'confirming';
        $instance['token'] = 'mbt_token';
        $store = new BootstrapMemoryStore($instance);
        $client = new BootstrapQueueClient([new AgentHeartbeatResult(true, BootstrapMemoryStore::INSTANCE_ID)]);

        $result = $this->service($store, $client)->ensureConnected();

        self::assertTrue($result->ok);
        self::assertSame('confirmed', $store->instance['activation_state']);
        self::assertCount(1, $client->payloads);
        self::assertSame('mbt_token', $client->payloads[0]['token']);
    }

    public function testDurabilityExceptionAfterVisibleMutationConvergesByReloading(): void
    {
        $store = new BootstrapMemoryStore($this->activating());
        $store->throwAfterActivationStore = true;
        $store->throwAfterConfirmation = true;
        $client = new BootstrapQueueClient([
            new AgentHeartbeatResult(true, BootstrapMemoryStore::INSTANCE_ID, 'mbt_token', 86400),
            new AgentHeartbeatResult(true, BootstrapMemoryStore::INSTANCE_ID),
        ]);

        $result = $this->service($store, $client)->ensureConnected();

        self::assertTrue($result->ok);
        self::assertSame('confirmed', $store->instance['activation_state']);
        self::assertCount(2, $client->payloads);
    }

    public function testFailedOrExpiredActivationNeverBecomesConnected(): void
    {
        $store = new BootstrapMemoryStore($this->activating());
        $client = new BootstrapQueueClient([AgentHeartbeatResult::failure('AGENT_TIMEOUT')]);
        $failed = $this->service($store, $client)->ensureConnected();
        self::assertFalse($failed->ok);
        self::assertSame('activating', $store->instance['activation_state']);

        $store->instance['activation_secret_expires_at'] = 999;
        $expired = $this->service($store, new BootstrapQueueClient([]))->ensureConnected();
        self::assertFalse($expired->ok);
        self::assertSame('PLATFORM_TOKEN_RECOVERY_REQUIRED', $expired->error);
        self::assertSame('recovery_required', $store->instance['activation_state']);
    }

    public function testAlreadyConfirmedAndRecoveryRequiredDoNotCallAgent(): void
    {
        $confirmed = $this->activating();
        $confirmed['activation_state'] = 'confirmed';
        $confirmed['token'] = 'mbt_token';
        $confirmed['activation_secret'] = '';
        $confirmed['activation_secret_expires_at'] = 0;
        $confirmedClient = new BootstrapQueueClient([]);
        self::assertTrue($this->service(new BootstrapMemoryStore($confirmed), $confirmedClient)->ensureConnected()->ok);
        self::assertCount(0, $confirmedClient->payloads);

        $recovery = $confirmed;
        $recovery['activation_state'] = 'recovery_required';
        $recovery['token'] = '';
        $recoveryClient = new BootstrapQueueClient([]);
        $result = $this->service(new BootstrapMemoryStore($recovery), $recoveryClient)->ensureConnected();
        self::assertFalse($result->ok);
        self::assertSame('PLATFORM_TOKEN_RECOVERY_REQUIRED', $result->error);
        self::assertCount(0, $recoveryClient->payloads);
    }

    private function service(BootstrapMemoryStore $store, BootstrapQueueClient $client): AgentPlatformBootstrapService
    {
        return new AgentPlatformBootstrapService(
            $store,
            $client,
            new AgentHeartbeatPayloadFactory($this->versionPath, static fn(): array => []),
            new InstallLockService($this->legacyPath),
            static fn(): int => 1000,
        );
    }

    /** @return array<string, mixed> */
    private function activating(): array
    {
        return [
            'schema_version' => 1,
            'revision' => 1,
            'instance_id' => BootstrapMemoryStore::INSTANCE_ID,
            'token' => '',
            'activation_secret' => 'activation-proof',
            'activation_generation' => BootstrapMemoryStore::GENERATION,
            'activation_secret_expires_at' => 1900,
            'activation_state' => 'activating',
            'disabled' => false,
            'components' => [],
            'report' => [],
            'updated_at' => 1000,
        ];
    }
}

final class BootstrapQueueClient implements AgentHeartbeatClient
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

final class BootstrapMemoryStore implements AgentInstanceStateStore
{
    public const INSTANCE_ID = 'c6f83b5e-aadc-4a65-9c71-79a64aa22e58';
    public const GENERATION = '550e8400-e29b-41d4-a716-446655440000';

    public bool $throwAfterActivationStore = false;
    public bool $throwAfterConfirmation = false;

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
        return null;
    }

    public function recordReportResult(string $reservationId, int $reservationRevision, bool $success, int $now, int $nextReportAfterSeconds, string $errorCode = ''): bool
    {
        return false;
    }

    public function storeActivationResponse(string $generation, int $expectedRevision, string $instanceId, string $token, int $now): array
    {
        if ($generation !== self::GENERATION || $expectedRevision !== $this->instance['revision'] || $instanceId !== self::INSTANCE_ID) {
            throw new RuntimeException('cas mismatch');
        }
        $this->instance['revision']++;
        $this->instance['token'] = $token;
        $this->instance['activation_state'] = 'confirming';
        if ($this->throwAfterActivationStore) {
            $this->throwAfterActivationStore = false;
            throw new RuntimeException('durability uncertain');
        }

        return $this->instance;
    }

    public function confirmActivation(string $generation, int $expectedRevision, int $now): array
    {
        if ($generation !== self::GENERATION || $expectedRevision !== $this->instance['revision']) {
            throw new RuntimeException('cas mismatch');
        }
        $this->instance['revision']++;
        $this->instance['activation_state'] = 'confirmed';
        $this->instance['activation_secret'] = '';
        $this->instance['activation_secret_expires_at'] = 0;
        if ($this->throwAfterConfirmation) {
            $this->throwAfterConfirmation = false;
            throw new RuntimeException('durability uncertain');
        }

        return $this->instance;
    }

    public function markExpiredActivationRecoveryRequired(int $now): array
    {
        $this->instance['revision']++;
        $this->instance['activation_state'] = 'recovery_required';
        $this->instance['activation_secret'] = '';
        $this->instance['activation_secret_expires_at'] = 0;

        return $this->instance;
    }
}
