<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentInstanceConfigStore;
use app\service\install\InstallLockService;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class AgentInstanceConfigStoreTest extends TestCase
{
    private string $root;
    private string $legacyPath;
    private UpgradeSharedFileStore $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-instance-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0770, true);
        mkdir($this->root . '/run', 0770, true);
        chmod($this->root . '/config', 02770);
        chmod($this->root . '/run', 02770);
        $this->legacyPath = $this->root . '/install.lock';
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $gid = function_exists('posix_getegid') ? posix_getegid() : getmygid();
        $this->files = new UpgradeSharedFileStore($this->root, $uid, $gid, $uid, 65536, 50);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testFreshInitializationNoLongerNeedsStorageProjectionOrSessionCredential(): void
    {
        $instance = $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);

        self::assertSame('activating', $instance['activation_state']);
        self::assertArrayNotHasKey('platform_base_url', $instance);
        self::assertArrayNotHasKey('upgrade_namespace_id', $instance);
        self::assertArrayNotHasKey('session_derivation_key', $instance);

        $persisted = json_decode((string) file_get_contents($this->root . '/config/instance.json'), true);
        self::assertIsArray($persisted);
        self::assertArrayNotHasKey('platform_base_url', $persisted);
        self::assertArrayNotHasKey('upgrade_namespace_id', $persisted);
        self::assertArrayNotHasKey('session_derivation_key', $persisted);
    }

    public function testLegacyPlatformIdentityIsImportedWithoutStorageIdentity(): void
    {
        file_put_contents($this->legacyPath, json_encode([
            'installed_at' => '2026-07-14 10:00:00',
            'platform' => [
                'instance_id' => 'c6f83b5e-aadc-4a65-9c71-79a64aa22e58',
                'token' => 'mbt_' . str_repeat('a', 32),
                'components' => ['backend_php' => 900],
            ],
        ], JSON_THROW_ON_ERROR));

        $instance = $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);

        self::assertSame('confirmed', $instance['activation_state']);
        self::assertSame('c6f83b5e-aadc-4a65-9c71-79a64aa22e58', $instance['instance_id']);
        self::assertSame(900, $instance['components']['backend_php']);
        self::assertArrayNotHasKey('upgrade_namespace_id', $instance);
        self::assertArrayNotHasKey('platform_base_url', $instance);
    }

    public function testLegacyInstanceDocumentLoadsAndConvergesOnNextWrite(): void
    {
        $legacy = $this->confirmedDocument();
        $legacy['schema_version'] = 2;
        $legacy = array_slice($legacy, 0, 3, true)
            + ['upgrade_namespace_id' => 'mbs_legacy']
            + array_slice($legacy, 3, 2, true)
            + ['session_derivation_key' => rtrim(strtr(base64_encode(str_repeat('k', 32)), '+/', '-_'), '=')]
            + array_slice($legacy, 5, null, true);
        $this->files->writeJson('instance', (object) $legacy);

        $loaded = $this->store()->load();
        self::assertIsArray($loaded);
        self::assertSame(1, $loaded['schema_version']);
        self::assertArrayNotHasKey('upgrade_namespace_id', $loaded);
        self::assertArrayNotHasKey('session_derivation_key', $loaded);

        $reservation = $this->store()->reserveReportWindow('backend_php', 1001, 60);
        self::assertNotNull($reservation);
        $persisted = json_decode((string) file_get_contents($this->root . '/config/instance.json'), true);
        self::assertArrayNotHasKey('upgrade_namespace_id', $persisted);
        self::assertArrayNotHasKey('session_derivation_key', $persisted);
        self::assertArrayNotHasKey('platform_base_url', $persisted);
    }

    public function testActivationAndReportStateMachinesRemainAvailable(): void
    {
        $store = $this->store();
        $activating = $store->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);
        $confirming = $store->storeActivationResponse(
            $activating['activation_generation'],
            $activating['revision'],
            $activating['instance_id'],
            'mbt_' . str_repeat('b', 32),
            1001,
        );
        $confirmed = $store->confirmActivation(
            $confirming['activation_generation'],
            $confirming['revision'],
            1002,
        );
        self::assertSame('confirmed', $confirmed['activation_state']);

        $reservation = $store->reserveReportWindow('backend_php', 1003, 60);
        self::assertNotNull($reservation);
        self::assertTrue($store->recordReportResult(
            $reservation['reservation_id'],
            $reservation['reservation_revision'],
            true,
            1004,
            3600,
        ));
        self::assertSame(1004, $store->load()['report']['last_success_at']);
    }

    /** @return array<string,mixed> */
    private function confirmedDocument(): array
    {
        return [
            'schema_version' => 1,
            'revision' => 1,
            'platform_base_url' => 'https://legacy-platform.invalid',
            'instance_id' => 'c6f83b5e-aadc-4a65-9c71-79a64aa22e58',
            'token' => 'mbt_' . str_repeat('a', 32),
            'activation_secret' => '',
            'activation_generation' => '550e8400-e29b-41d4-a716-446655440000',
            'activation_secret_expires_at' => 0,
            'activation_state' => 'confirmed',
            'disabled' => false,
            'components' => (object) [],
            'report' => (object) [
                'next_after' => 0,
                'reservation_id' => '',
                'reservation_until' => 0,
                'last_success_at' => 0,
                'last_error_code' => '',
                'last_error_at' => 0,
            ],
            'updated_at' => 1000,
        ];
    }

    private function store(): AgentInstanceConfigStore
    {
        return new AgentInstanceConfigStore(
            $this->files,
            900,
            3600,
            50,
        );
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}
