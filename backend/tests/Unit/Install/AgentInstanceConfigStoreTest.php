<?php

declare(strict_types=1);

namespace app\service\install;

final class AgentInstanceConfigStoreLegacyIoHook
{
    /** @var callable(string):void|null */
    public static $beforeOpen = null;
}

function fopen(string $filename, string $mode)
{
    $hook = AgentInstanceConfigStoreLegacyIoHook::$beforeOpen;
    if (is_callable($hook)) {
        AgentInstanceConfigStoreLegacyIoHook::$beforeOpen = null;
        $hook($filename);
    }

    return \fopen($filename, $mode);
}

namespace Tests\Unit\Install;

use app\service\install\AgentInstanceConfigStore;
use app\service\install\AgentInstanceConfigStoreLegacyIoHook;
use app\service\install\InstallLockService;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentInstanceConfigStoreTest extends TestCase
{
    private const AGENT_UID = 32001;
    private const SHARED_GID = 32002;
    private const PHP_UID = 32003;
    private const ORIGIN = 'https://platform.gosowong.cn';
    private const NAMESPACE = 'mbs_test_namespace';
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';

    private string $root;
    private string $legacyPath;
    private UpgradeSharedFileStore $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-instance-store-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        mkdir($this->root . '/config', 02770);
        mkdir($this->root . '/run', 02770);
        mkdir($this->root . '/staging', 0750);
        chmod($this->root, 0750);
        chmod($this->root . '/config', 02770);
        chmod($this->root . '/run', 02770);
        chmod($this->root . '/staging', 0750);
        $this->legacyPath = $this->root . '/legacy-install.lock';
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            50,
            $this->statOperations(),
        );
        $this->writeProjection(self::NAMESPACE);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testLoadReturnsNullBeforeInitialization(): void
    {
        $this->assertNull($this->store()->load());
    }

    public function testFreshInitializationEmitsExactGoSchemaWithObjectComponentMap(): void
    {
        $instance = $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);

        $this->assertSame(1, $instance['schema_version']);
        $this->assertSame(1, $instance['revision']);
        $this->assertSame(self::ORIGIN, $instance['platform_base_url']);
        $this->assertSame(self::NAMESPACE, $instance['upgrade_namespace_id']);
        $this->assertMatchesRegularExpression($this->uuidPattern(), $instance['instance_id']);
        $this->assertSame('', $instance['token']);
        $this->assertSame('activating', $instance['activation_state']);
        $this->assertSame(1900, $instance['activation_secret_expires_at']);
        $this->assertSame(43, strlen($instance['activation_secret']));
        $this->assertSame([], $instance['components']);
        $this->assertSame([
            'next_after' => 0,
            'reservation_id' => '',
            'reservation_until' => 0,
            'last_success_at' => 0,
            'last_error_code' => '',
            'last_error_at' => 0,
        ], $instance['report']);
        $this->assertSame(1000, $instance['updated_at']);

        $raw = (string) file_get_contents($this->root . '/config/instance.json');
        $this->assertStringContainsString('"components":{}', $raw);
        $this->assertStringNotContainsString('"components":[]', $raw);
        $this->assertSame($instance, $this->store()->load());
    }

    public function testLegacyConfirmedIdentityMigratesOnceAndNeverOverwritesSharedInstance(): void
    {
        $this->writeLegacy([
            'platform' => [
                'instance_id' => self::INSTANCE_ID,
                'token' => 'mbt_token',
                'disabled' => true,
                'components' => ['backend_php' => 900, 'unknown' => 800],
            ],
        ]);
        $store = $this->store();

        $first = $store->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);
        $this->assertSame('confirmed', $first['activation_state']);
        $this->assertSame(self::INSTANCE_ID, $first['instance_id']);
        $this->assertSame('mbt_token', $first['token']);
        $this->assertTrue($first['disabled']);
        $this->assertSame(['backend_php' => 900], $first['components']);

        $this->writeLegacy(['platform' => ['instance_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'token' => 'replaced']]);
        $second = $store->initializeFromLegacy(new InstallLockService($this->legacyPath), 1001);
        $this->assertSame($first, $second);
    }

    public function testLegacyIdentityWithoutTokenRequiresRecovery(): void
    {
        $this->writeLegacy(['platform' => ['instance_id' => self::INSTANCE_ID]]);

        $instance = $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);

        $this->assertSame('recovery_required', $instance['activation_state']);
        $this->assertSame(self::INSTANCE_ID, $instance['instance_id']);
        $this->assertSame('', $instance['token']);
        $this->assertSame('', $instance['activation_secret']);
        $this->assertSame(0, $instance['activation_secret_expires_at']);
    }

    #[DataProvider('invalidLegacyIdentityProvider')]
    public function testMalformedLegacyIdentityFailsClosed(array $platform): void
    {
        $this->writeLegacy(['platform' => $platform]);

        $this->assertStoreFailure(
            'LEGACY_PLATFORM_STATE_INVALID',
            fn() => $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000),
        );
        $this->assertFileDoesNotExist($this->root . '/config/instance.json');
    }

    #[DataProvider('duplicateLegacyJsonProvider')]
    public function testLegacyJsonRejectsDuplicateAndEscapedDuplicateKeys(string $raw): void
    {
        file_put_contents($this->legacyPath, $raw);
        chmod($this->legacyPath, 0660);

        $this->assertStoreFailure(
            'LEGACY_PLATFORM_STATE_INVALID',
            fn() => $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000),
        );
        $this->assertFileDoesNotExist($this->root . '/config/instance.json');
    }

    /** @return iterable<string, array{string}> */
    public static function duplicateLegacyJsonProvider(): iterable
    {
        yield 'top level' => ['{"platform":{},"platform":{}}'];
        yield 'identity' => ['{"platform":{"instance_id":"' . self::INSTANCE_ID . '","instance_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","token":"mbt_token"}}'];
        yield 'escaped identity' => ['{"platform":{"instance_id":"' . self::INSTANCE_ID . '","\\u0069nstance_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","token":"mbt_token"}}'];
        yield 'nested component' => ['{"platform":{"components":{"backend_php":1,"\\u0062ackend_php":2}}}'];
        yield 'excessive depth' => ['{"platform":{"extra":' . str_repeat('[', 34) . '0' . str_repeat(']', 34) . '}}'];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidLegacyIdentityProvider(): iterable
    {
        yield 'token only' => [['token' => 'mbt_token']];
        yield 'invalid uuid' => [['instance_id' => 'not-a-uuid']];
        yield 'invalid token whitespace' => [['instance_id' => self::INSTANCE_ID, 'token' => 'bad token']];
        yield 'non string token' => [['instance_id' => self::INSTANCE_ID, 'token' => 123]];
    }

    public function testNamespaceProjectionMissingMalformedAndMismatchFailClosed(): void
    {
        unlink($this->root . '/staging/storage-namespace.json');
        $this->assertStoreFailure('NAMESPACE_PROJECTION_UNAVAILABLE', fn() => $this->initialize());

        $this->writeProjection('mbs_other');
        $this->assertStoreFailure('NAMESPACE_PROJECTION_MISMATCH', fn() => $this->initialize());

        unlink($this->root . '/staging/storage-namespace.json');
        file_put_contents($this->root . '/staging/storage-namespace.json', '{"schema_version":1,"installation_storage_namespace":"mbs_other","extra":true}');
        chmod($this->root . '/staging/storage-namespace.json', 0444);
        $this->assertStoreFailure('NAMESPACE_PROJECTION_UNAVAILABLE', fn() => $this->initialize());
    }

    public function testReportReservationAndSuccessResultUseOneRevisionEach(): void
    {
        $store = $this->confirmedStore(1000);

        $reservation = $store->reserveReportWindow('backend_php', 1000, 30);
        $this->assertNotNull($reservation);
        $this->assertSame(2, $reservation['reservation_revision']);
        $this->assertSame(2, $reservation['instance']['revision']);
        $this->assertSame(1000, $reservation['instance']['components']['backend_php']);
        $this->assertSame(1030, $reservation['instance']['report']['reservation_until']);

        $this->assertTrue($store->recordReportResult(
            $reservation['reservation_id'],
            $reservation['reservation_revision'],
            true,
            1030,
            60,
        ));
        $instance = $store->load();
        $this->assertSame(3, $instance['revision']);
        $this->assertSame(1090, $instance['report']['next_after']);
        $this->assertSame(1030, $instance['report']['last_success_at']);
        $this->assertSame('', $instance['report']['last_error_code']);
        $this->assertSame('', $instance['report']['reservation_id']);
    }

    public function testReportFailurePreservesLastSuccessAndUsesStableErrorCode(): void
    {
        $store = $this->confirmedStore(1000);
        $first = $store->reserveReportWindow('backend_php', 1000, 10);
        self::assertNotNull($first);
        $store->recordReportResult($first['reservation_id'], $first['reservation_revision'], true, 1001, 10);
        $second = $store->reserveReportWindow('backend_php', 1011, 10);
        self::assertNotNull($second);

        $this->assertTrue($store->recordReportResult($second['reservation_id'], $second['reservation_revision'], false, 1012, 5, 'HTTP_500'));
        $instance = $store->load();
        $this->assertSame(1001, $instance['report']['last_success_at']);
        $this->assertSame('HTTP_500', $instance['report']['last_error_code']);
        $this->assertSame(1012, $instance['report']['last_error_at']);
        $this->assertSame(1017, $instance['report']['next_after']);
    }

    public function testReservationEqualityBoundaryAndStaleResultProtection(): void
    {
        $store = $this->confirmedStore(1000);
        $first = $store->reserveReportWindow('backend_php', 1000, 10);
        self::assertNotNull($first);

        $this->assertNull($store->reserveReportWindow('backend_php', 1010, 10));
        $this->assertTrue($store->recordReportResult($first['reservation_id'], $first['reservation_revision'], true, 1010, 1));

        $second = $store->reserveReportWindow('backend_php', 1011, 10);
        self::assertNotNull($second);
        $third = $store->reserveReportWindow('backend_php', 1022, 10);
        self::assertNotNull($third);
        $bytes = file_get_contents($this->root . '/config/instance.json');
        $this->assertFalse($store->recordReportResult($second['reservation_id'], $second['reservation_revision'], false, 1022, 5, 'HTTP_500'));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
        $this->assertSame($third['reservation_id'], $store->load()['report']['reservation_id']);
    }

    public function testReportResultAllowsUnrelatedRevisionGrowthAfterReservation(): void
    {
        $store = $this->confirmedStore(1000);
        $reservation = $store->reserveReportWindow('backend_php', 1000, 30);
        self::assertNotNull($reservation);
        $this->assertSame(2, $reservation['reservation_revision']);

        $this->assertNull($store->reserveReportWindow('admin_web', 1001, 30));
        $this->assertSame(3, $store->load()['revision']);
        $this->assertTrue($store->recordReportResult(
            $reservation['reservation_id'],
            $reservation['reservation_revision'],
            true,
            1002,
            60,
        ));
        $instance = $store->load();
        $this->assertSame(4, $instance['revision']);
        $this->assertSame(1002, $instance['report']['last_success_at']);
    }

    public function testDisabledNotConfirmedAndNotDueReservationsAreNoOps(): void
    {
        $fresh = $this->initialize();
        $bytes = file_get_contents($this->root . '/config/instance.json');
        $this->assertNull($this->store()->reserveReportWindow('backend_php', 1000, 10));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));

        $this->removeInstance();
        $this->writeLegacy(['platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'mbt_token', 'disabled' => true]]);
        $disabled = $this->initialize();
        $bytes = file_get_contents($this->root . '/config/instance.json');
        $this->assertNull($this->store()->reserveReportWindow('backend_php', 1000, 10));
        $this->assertSame($disabled['revision'], $fresh['revision']);
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
    }

    public function testComponentSeenThrottleDoesNotMoveBackwardOrIncrementNoOpRevision(): void
    {
        $store = $this->confirmedStore(1000);
        $reservation = $store->reserveReportWindow('backend_php', 1000, 10);
        self::assertNotNull($reservation);
        $store->recordReportResult($reservation['reservation_id'], $reservation['reservation_revision'], true, 1001, 100);
        $first = $store->reserveReportWindow('backend_php', 1010, 10);
        $this->assertNull($first);
        $revision = $store->load()['revision'];
        $bytes = file_get_contents($this->root . '/config/instance.json');

        $this->assertNull($store->reserveReportWindow('backend_php', 999, 10));
        $this->assertSame($revision, $store->load()['revision']);
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
    }

    public function testComponentSeenThrottleUpdatesAtTheExactEqualityBoundary(): void
    {
        $store = $this->confirmedStore(1000);
        $reservation = $store->reserveReportWindow('backend_php', 1000, 30);
        self::assertNotNull($reservation);
        $store->recordReportResult($reservation['reservation_id'], $reservation['reservation_revision'], true, 1001, 10000);
        $before = file_get_contents($this->root . '/config/instance.json');

        $this->assertNull($store->reserveReportWindow('backend_php', 4599, 30));
        $this->assertSame($before, file_get_contents($this->root . '/config/instance.json'));
        $this->assertNull($store->reserveReportWindow('backend_php', 4600, 30));
        $instance = $store->load();
        $this->assertSame(4, $instance['revision']);
        $this->assertSame(4600, $instance['components']['backend_php']);
    }

    public function testActivationMovesThroughConfirmingToConfirmedAndClearsProof(): void
    {
        $store = $this->store();
        $initial = $this->initialize();

        $confirming = $store->storeActivationResponse(
            $initial['activation_generation'],
            $initial['revision'],
            $initial['instance_id'],
            'mbt_token',
            1001,
        );
        $this->assertSame('confirming', $confirming['activation_state']);
        $this->assertSame(2, $confirming['revision']);
        $this->assertNotSame('', $confirming['activation_secret']);

        $confirmed = $store->confirmActivation($initial['activation_generation'], 2, 1002);
        $this->assertSame('confirmed', $confirmed['activation_state']);
        $this->assertSame(3, $confirmed['revision']);
        $this->assertSame('', $confirmed['activation_secret']);
        $this->assertSame(0, $confirmed['activation_secret_expires_at']);
        $this->assertSame('mbt_token', $confirmed['token']);
    }

    public function testActivationCasAndStateFailuresNeverChangeBytes(): void
    {
        $store = $this->store();
        $initial = $this->initialize();
        $bytes = file_get_contents($this->root . '/config/instance.json');

        $this->assertStoreFailure('INSTANCE_CAS_MISMATCH', fn() => $store->storeActivationResponse($initial['activation_generation'], 99, self::INSTANCE_ID, 'mbt_token', 1001));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
        $this->assertStoreFailure('INSTANCE_CAS_MISMATCH', fn() => $store->storeActivationResponse('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1, self::INSTANCE_ID, 'mbt_token', 1001));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
    }

    public function testActivationStateFailuresLeaveBytesAndRevisionUnchanged(): void
    {
        $store = $this->store();
        $activating = $this->initialize();
        $bytes = file_get_contents($this->root . '/config/instance.json');
        $this->assertStoreFailure('ACTIVATION_STATE_INVALID', fn() => $store->confirmActivation(
            $activating['activation_generation'],
            $activating['revision'],
            1001,
        ));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
        $this->assertSame(1, $store->load()['revision']);

        $this->assertStoreFailure('ACTIVATION_STATE_INVALID', fn() => $store->storeActivationResponse(
            $activating['activation_generation'],
            $activating['revision'],
            $activating['instance_id'],
            'mbt_token',
            1900,
        ));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));

        $this->removeInstance();
        $confirmedStore = $this->confirmedStore(2000);
        $confirmed = $confirmedStore->load();
        $bytes = file_get_contents($this->root . '/config/instance.json');
        $this->assertStoreFailure('ACTIVATION_STATE_INVALID', fn() => $confirmedStore->storeActivationResponse(
            $confirmed['activation_generation'],
            $confirmed['revision'],
            $confirmed['instance_id'],
            'replacement_token',
            2001,
        ));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
        $this->assertSame(1, $confirmedStore->load()['revision']);
    }

    public function testExpiredActivatingMovesToRecoveryAtEqualityButConfirmingRemainsConfirmable(): void
    {
        $store = $this->store();
        $initial = $this->initialize(1000);
        $unchanged = $store->markExpiredActivationRecoveryRequired(1899);
        $this->assertSame($initial, $unchanged);

        $recovery = $store->markExpiredActivationRecoveryRequired(1900);
        $this->assertSame('recovery_required', $recovery['activation_state']);
        $this->assertSame(2, $recovery['revision']);
        $this->assertSame('', $recovery['activation_secret']);

        $this->removeInstance();
        $initial = $this->initialize(1000);
        $confirming = $store->storeActivationResponse($initial['activation_generation'], 1, $initial['instance_id'], 'mbt_token', 1001);
        $same = $store->markExpiredActivationRecoveryRequired(1900);
        $this->assertSame($confirming, $same);
        $confirmed = $store->confirmActivation($initial['activation_generation'], 2, 1901);
        $this->assertSame('confirmed', $confirmed['activation_state']);
    }

    #[DataProvider('invalidArgumentProvider')]
    public function testInvalidTimesIntervalsComponentsAndErrorsDoNotMutate(string $operation): void
    {
        $store = $this->confirmedStore(1000);
        $bytes = file_get_contents($this->root . '/config/instance.json');

        $callback = match ($operation) {
            'negative now' => fn() => $store->reserveReportWindow('backend_php', -1, 10),
            'zero reservation' => fn() => $store->reserveReportWindow('backend_php', 1000, 0),
            'unknown component' => fn() => $store->reserveReportWindow('unknown', 1000, 10),
            'overflow reservation' => fn() => $store->reserveReportWindow('backend_php', 4102444800, 1),
            'invalid error' => function () use ($store): void {
                $reservation = $store->reserveReportWindow('backend_php', 1000, 10);
                self::assertNotNull($reservation);
                $store->recordReportResult($reservation['reservation_id'], $reservation['reservation_revision'], false, 1001, 5, 'raw http error');
            },
        };

        $this->assertStoreFailure('INSTANCE_ARGUMENT_INVALID', $callback);
        if ($operation !== 'invalid error') {
            $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidArgumentProvider(): iterable
    {
        foreach (['negative now', 'zero reservation', 'unknown component', 'overflow reservation', 'invalid error'] as $name) {
            yield $name => [$name];
        }
    }

    public function testExactSchemaRejectsUnknownNullListFloatAndWrongCaseValues(): void
    {
        $valid = $this->initialize();
        $cases = [];
        $cases['unknown'] = $valid + ['extra' => true];
        $cases['null'] = array_replace($valid, ['token' => null]);
        $cases['float'] = array_replace($valid, ['revision' => 1.0]);
        $cases['components list'] = array_replace($valid, ['components' => [1]]);
        $cases['uuid case'] = array_replace($valid, ['instance_id' => strtoupper($valid['instance_id'])]);
        $cases['report list'] = array_replace($valid, ['report' => []]);

        foreach ($cases as $name => $document) {
            $this->files->writeJson('instance', $this->instanceObject($document));
            $this->assertStoreFailure('INSTANCE_INVALID', fn() => $this->store()->load(), $name);
        }
    }

    public function testGoSchemaRejectsEveryInvalidFieldFamilyWithoutSecretBearingErrors(): void
    {
        $valid = $this->initialize();
        $invalid = [];
        $invalid['future schema'] = array_replace($valid, ['schema_version' => 2]);
        $invalid['zero revision'] = array_replace($valid, ['revision' => 0]);
        $invalid['missing token'] = $valid;
        unset($invalid['missing token']['token']);
        $invalid['external http'] = array_replace($valid, ['platform_base_url' => 'http://example.com']);
        $invalid['origin path'] = array_replace($valid, ['platform_base_url' => self::ORIGIN . '/api']);
        $invalid['origin query'] = array_replace($valid, ['platform_base_url' => self::ORIGIN . '?x=1']);
        $invalid['namespace case'] = array_replace($valid, ['upgrade_namespace_id' => 'mbs_Invalid']);
        $invalid['uuid version'] = array_replace($valid, ['instance_id' => '00000000-0000-0000-8000-000000000000']);
        $invalid['token whitespace'] = array_replace($valid, ['token' => 'bad token']);
        $invalid['token unicode'] = array_replace($valid, ['token' => '令牌']);
        $invalid['token too long'] = array_replace($valid, ['token' => str_repeat('a', 4097)]);
        $invalid['secret size'] = array_replace($valid, ['activation_secret' => str_repeat('A', 42)]);
        $invalid['generation case'] = array_replace($valid, ['activation_generation' => strtoupper($valid['activation_generation'])]);
        $invalid['negative expiry'] = array_replace($valid, ['activation_secret_expires_at' => -1]);
        $invalid['future expiry'] = array_replace($valid, ['activation_secret_expires_at' => 4102444801]);
        $invalid['unknown state'] = array_replace($valid, ['activation_state' => 'pending']);
        $invalid['state invariant'] = array_replace($valid, ['token' => 'mbt_token']);
        $invalid['disabled integer'] = array_replace($valid, ['disabled' => 0]);
        $invalid['unknown component'] = array_replace($valid, ['components' => ['worker' => 1]]);
        $invalid['component float'] = array_replace($valid, ['components' => ['backend_php' => 1.0]]);
        $invalid['report reservation pair'] = array_replace($valid, [
            'report' => array_replace($valid['report'], ['reservation_id' => self::INSTANCE_ID]),
        ]);
        $invalid['report error case'] = array_replace($valid, [
            'report' => array_replace($valid['report'], ['last_error_code' => 'http_500']),
        ]);
        $invalid['report unknown'] = array_replace($valid, [
            'report' => $valid['report'] + ['raw_error' => 'secret'],
        ]);
        $invalid['updated float'] = array_replace($valid, ['updated_at' => 1000.0]);

        foreach ($invalid as $name => $document) {
            $this->files->writeJson('instance', $this->instanceObject($document));
            $this->assertStoreFailure('INSTANCE_INVALID', fn() => $this->store()->load(), $name);
        }
    }

    public function testGoSchemaAcceptsIntegerAndLengthBoundaries(): void
    {
        $instance = $this->initialize();
        $instance['revision'] = PHP_INT_MAX;
        $instance['activation_secret_expires_at'] = 4102444800;
        $instance['updated_at'] = 4102444800;
        $instance['components'] = array_fill_keys(
            ['backend_php', 'admin_web', 'uniapp', 'wechat_miniapp', 'queue', 'cron', 'agent'],
            4102444800,
        );
        $instance['report']['next_after'] = 4102444800;
        $instance['report']['last_success_at'] = 4102444800;
        $instance['report']['last_error_code'] = str_repeat('A', 64);
        $instance['report']['last_error_at'] = 4102444800;
        $this->files->writeJson('instance', $this->instanceObject($instance));

        $this->assertSame($instance, $this->store()->load());

        $instance['activation_state'] = 'confirmed';
        $instance['activation_secret'] = '';
        $instance['activation_secret_expires_at'] = 0;
        $instance['token'] = str_repeat('~', 4096);
        $this->files->writeJson('instance', $this->instanceObject($instance));
        $this->assertSame(4096, strlen($this->store()->load()['token']));
    }

    public function testLoopbackHttpOriginMatchesGoLoopbackRange(): void
    {
        $store = new AgentInstanceConfigStore(
            $this->files,
            'http://127.0.0.2:8080/',
            self::NAMESPACE,
            900,
            3600,
            50,
        );

        $instance = $store->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);

        $this->assertSame('http://127.0.0.2:8080', $instance['platform_base_url']);
    }

    public function testTwoProcessesCanCreateOnlyOneReportReservation(): void
    {
        $this->assertTrue(function_exists('proc_open'), 'proc_open is required for the reservation contract.');
        $store = $this->confirmedStore(1000);
        $barrierPath = $this->root . '/reservation-barrier';
        $children = [];

        for ($index = 0; $index < 2; $index++) {
            $children[] = $this->startAgentProcess(<<<'PHP'
while (!file_exists($argv[9])) {
    usleep(1000);
}
try {
    $result = $store->reserveReportWindow('backend_php', 1000, 30);
    echo $result === null ? 'none' : 'reserved';
} catch (Throwable $exception) {
    echo $exception->getMessage();
}
PHP, [$barrierPath]);
        }

        touch($barrierPath);
        $results = array_map(fn(array $child): string => $this->finishAgentProcess($child), $children);
        sort($results);
        $this->assertSame(['none', 'reserved'], $results);
        $this->assertSame(2, $store->load()['revision']);
    }

    public function testTwoFirstInitializersPublishOnlyOneRevisionOneLegacyIdentity(): void
    {
        $this->assertTrue(function_exists('proc_open'), 'proc_open is required for the initialization contract.');
        $legacyA = $this->root . '/legacy-a.lock';
        $legacyB = $this->root . '/legacy-b.lock';
        file_put_contents($legacyA, json_encode([
            'platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'token_a'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($legacyB, json_encode([
            'platform' => ['instance_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'token' => 'token_b'],
        ], JSON_THROW_ON_ERROR));
        chmod($legacyA, 0660);
        chmod($legacyB, 0660);
        $barrierPath = $this->root . '/initialize-barrier';
        $body = <<<'PHP'
while (!file_exists($argv[10])) {
    usleep(1000);
}
try {
    $instance = $store->initializeFromLegacy(new \app\service\install\InstallLockService($argv[9]), 1000);
    echo json_encode([
        'revision' => $instance['revision'],
        'instance_id' => $instance['instance_id'],
        'token' => $instance['token'],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo $exception->getMessage();
}
PHP;
        $children = [
            $this->startAgentProcess($body, [$legacyA, $barrierPath], 1000),
            $this->startAgentProcess($body, [$legacyB, $barrierPath], 1000),
        ];

        touch($barrierPath);
        $results = array_map(fn(array $child): string => $this->finishAgentProcess($child), $children);
        $this->assertSame($results[0], $results[1]);
        $winner = json_decode($results[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $winner['revision']);
        $this->assertContains($winner['token'], ['token_a', 'token_b']);
        $this->assertSame($winner, array_intersect_key($this->store()->load(), $winner));
    }

    public function testLegacyReadWaitsForOldExclusiveWriterAndReadsOnlyCompletedJson(): void
    {
        $this->assertTrue(function_exists('proc_open'), 'proc_open is required for the legacy lock contract.');
        $this->writeLegacy(['platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'old_token']]);
        $handle = fopen($this->legacyPath, 'c+b');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX));
        $child = $this->startAgentProcess(<<<'PHP'
try {
    $instance = $store->initializeFromLegacy(new \app\service\install\InstallLockService($argv[9]), 1000);
    echo $instance['token'];
} catch (Throwable $exception) {
    echo $exception->getMessage();
}
PHP, [$this->legacyPath], 500);
        usleep(50_000);
        $replacement = json_encode([
            'platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'new_token'],
        ], JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $replacement);
        fflush($handle);
        usleep(50_000);
        flock($handle, LOCK_UN);
        fclose($handle);
        $this->assertSame('new_token', $this->finishAgentProcess($child));
    }

    public function testLegacyReadRejectsPathReplacementBetweenInitialStatAndOpen(): void
    {
        $this->writeLegacy(['platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'old_token']]);
        $movedPath = $this->legacyPath . '.before-open';
        AgentInstanceConfigStoreLegacyIoHook::$beforeOpen = function (string $filename) use ($movedPath): void {
            $this->assertSame($this->legacyPath, $filename);
            rename($this->legacyPath, $movedPath);
            file_put_contents($this->legacyPath, json_encode([
                'platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'new_token'],
            ], JSON_THROW_ON_ERROR));
            chmod($this->legacyPath, 0660);
        };

        try {
            $this->assertStoreFailure(
                'LEGACY_PLATFORM_STATE_INVALID',
                fn() => $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000),
            );
        } finally {
            AgentInstanceConfigStoreLegacyIoHook::$beforeOpen = null;
        }
        $this->assertFileDoesNotExist($this->root . '/config/instance.json');
        $this->assertFileExists($movedPath);
    }

    public function testPostRenameActivationFailureConvergesFromVisibleRevision(): void
    {
        $renameCount = 0;
        $operations = $this->statOperations();
        $operations['fault'] = static function (string $checkpoint) use (&$renameCount): void {
            if ($checkpoint === 'after_rename' && ++$renameCount === 2) {
                throw new \RuntimeException('private activation response');
            }
        };
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            50,
            $operations,
        );
        $store = $this->store();
        $initial = $this->initialize();

        $this->assertStoreFailure('DURABILITY_UNCERTAIN', fn() => $store->storeActivationResponse(
            $initial['activation_generation'],
            1,
            $initial['instance_id'],
            'mbt_token',
            1001,
        ));
        $visible = $store->load();
        $this->assertSame(2, $visible['revision']);
        $this->assertSame('confirming', $visible['activation_state']);
        $this->assertStoreFailure('INSTANCE_CAS_MISMATCH', fn() => $store->storeActivationResponse(
            $initial['activation_generation'],
            1,
            $initial['instance_id'],
            'mbt_token',
            1001,
        ));
    }

    public function testRevisionExhaustionFailsWithoutChangingBytes(): void
    {
        $instance = $this->confirmedStore(1000)->load();
        $instance['revision'] = PHP_INT_MAX;
        $this->files->writeJson('instance', $this->instanceObject($instance));
        $bytes = file_get_contents($this->root . '/config/instance.json');

        $this->assertStoreFailure('INSTANCE_REVISION_EXHAUSTED', fn() => $this->store()->reserveReportWindow('backend_php', 1000, 10));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
    }

    public function testResultReplayReturnsFalseAndDoesNotIncrementRevision(): void
    {
        $store = $this->confirmedStore(1000);
        $reservation = $store->reserveReportWindow('backend_php', 1000, 10);
        self::assertNotNull($reservation);
        $this->assertTrue($store->recordReportResult($reservation['reservation_id'], $reservation['reservation_revision'], true, 1001, 10));
        $bytes = file_get_contents($this->root . '/config/instance.json');

        $this->assertFalse($store->recordReportResult($reservation['reservation_id'], $reservation['reservation_revision'], true, 1001, 10));
        $this->assertSame($bytes, file_get_contents($this->root . '/config/instance.json'));
    }

    private function store(int $legacyLockTimeoutMilliseconds = 50): AgentInstanceConfigStore
    {
        return new AgentInstanceConfigStore(
            $this->files,
            self::ORIGIN,
            self::NAMESPACE,
            900,
            3600,
            $legacyLockTimeoutMilliseconds,
        );
    }

    private function initialize(int $now = 1000): array
    {
        return $this->store()->initializeFromLegacy(new InstallLockService($this->legacyPath), $now);
    }

    private function confirmedStore(int $now): AgentInstanceConfigStore
    {
        $this->writeLegacy(['platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'mbt_token']]);
        $store = $this->store();
        $store->initializeFromLegacy(new InstallLockService($this->legacyPath), $now);

        return $store;
    }

    private function writeProjection(string $namespace): void
    {
        file_put_contents(
            $this->root . '/staging/storage-namespace.json',
            json_encode((object) [
                'schema_version' => 1,
                'installation_storage_namespace' => $namespace,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        chmod($this->root . '/staging/storage-namespace.json', 0444);
    }

    /** @param array<string, mixed> $info */
    private function writeLegacy(array $info): void
    {
        file_put_contents($this->legacyPath, json_encode($info, JSON_THROW_ON_ERROR));
        chmod($this->legacyPath, 0660);
    }

    private function removeInstance(): void
    {
        @unlink($this->root . '/config/instance.json');
        @unlink($this->root . '/run/instance-config.lock');
    }

    /** @param array<string, mixed> $instance */
    private function instanceObject(array $instance): object
    {
        $instance['components'] = (object) $instance['components'];
        $instance['report'] = (object) $instance['report'];

        return (object) $instance;
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat === false) {
                    return false;
                }
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
            'fstat' => function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat === false) {
                    return false;
                }
                $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($uri, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
        ];
    }

    private function expectedOwner(string $path, bool $directory): int
    {
        return $directory || str_ends_with($path, '/staging/storage-namespace.json')
            ? self::AGENT_UID
            : self::PHP_UID;
    }

    private function assertStoreFailure(string $message, callable $callback, string $context = ''): void
    {
        try {
            $callback();
            self::fail('Expected store failure ' . $message . ($context === '' ? '' : ' for ' . $context));
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage(), $context);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('mbt_token', $exception->getMessage());
        }
    }

    private function uuidPattern(): string
    {
        return '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    }

    /**
     * @param list<string> $extraArguments
     * @return array{0:resource,1:array<int,resource>}
     */
    private function startAgentProcess(string $body, array $extraArguments, int $legacyTimeout = 50): array
    {
        $script = <<<'PHP'
require $argv[1];
$root = $argv[2];
$agentUid = (int) $argv[3];
$gid = (int) $argv[4];
$phpUid = (int) $argv[5];
$origin = $argv[6];
$namespace = $argv[7];
$legacyTimeout = (int) $argv[8];
$expectedOwner = static function (string $path, bool $directory) use ($agentUid, $phpUid): int {
    return $directory || str_ends_with($path, '/staging/storage-namespace.json') ? $agentUid : $phpUid;
};
$operations = [
    'lstat' => static function (string $path) use ($gid, $expectedOwner): array|false {
        $stat = @lstat($path);
        if ($stat !== false) {
            $stat['gid'] = $gid;
            $stat['uid'] = $expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);
        }
        return $stat;
    },
    'fstat' => static function ($handle) use ($gid, $expectedOwner): array|false {
        $stat = fstat($handle);
        if ($stat !== false) {
            $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
            $stat['gid'] = $gid;
            $stat['uid'] = $expectedOwner($uri, ($stat['mode'] & 0170000) === 0040000);
        }
        return $stat;
    },
];
$files = new \app\service\upgrade\UpgradeSharedFileStore($root, $agentUid, $gid, $phpUid, 65536, 1000, $operations);
$store = new \app\service\install\AgentInstanceConfigStore($files, $origin, $namespace, 900, 3600, $legacyTimeout);
PHP;
        $command = [
            PHP_BINARY,
            '-d',
            'opcache.jit=0',
            '-d',
            'opcache.jit_buffer_size=0',
            '-r',
            $script . "\n" . $body,
            '--',
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            $this->root,
            (string) self::AGENT_UID,
            (string) self::SHARED_GID,
            (string) self::PHP_UID,
            self::ORIGIN,
            self::NAMESPACE,
            (string) $legacyTimeout,
            ...$extraArguments,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    /** @param array{0:resource,1:array<int,resource>} $child */
    private function finishAgentProcess(array $child): string
    {
        [$process, $pipes] = $child;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $this->assertSame(0, $exitCode, (string) $error);

        return (string) $output;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0660);
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0770);
        @rmdir($path);
    }
}
