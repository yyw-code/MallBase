<?php

declare(strict_types=1);

namespace {
    if (!function_exists('config')) {
        function config(string $name, mixed $default = null): mixed
        {
            return $default;
        }
    }

    if (!function_exists('root_path')) {
        function root_path(): string
        {
            return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        }
    }
}

namespace Tests\Unit\Install {

    use app\service\install\InstallLockService;
    use app\service\install\PlatformReporter;
    use PHPUnit\Framework\TestCase;

    final class PlatformReporterTest extends TestCase
    {
        private string $lockPath;

        protected function setUp(): void
        {
            parent::setUp();

            $dir = sys_get_temp_dir() . '/mallbase-platform-report-' . bin2hex(random_bytes(6));
            mkdir($dir, 0755, true);
            $this->lockPath = $dir . '/install.lock';
        }

        protected function tearDown(): void
        {
            if (is_file($this->lockPath)) {
                unlink($this->lockPath);
            }

            $dir = dirname($this->lockPath);
            if (is_dir($dir)) {
                rmdir($dir);
            }

            parent::tearDown();
        }

        public function testActivationFailureKeepsShortRetryWindow(): void
        {
            $lock = new InstallLockService($this->lockPath);
            $lock->writeInstalledLock('2026-06-19 12:00:00');
            $before = time();

            $reporter = new PlatformReporter($lock, static fn() => ['_error' => 'http_500']);
            $reporter->tick('admin_web');

            $state = $lock->getPlatformState();
            $this->assertSame('http_500', $state['last_report_error'] ?? null);
            $this->assertArrayNotHasKey('last_report_at', $state);
            $this->assertArrayNotHasKey('instance_id', $state);
            $this->assertGreaterThanOrEqual($before + 300, $state['next_report_after'] ?? 0);
            $this->assertLessThanOrEqual(time() + 300, $state['next_report_after'] ?? 0);
        }

        public function testActivationSuccessStoresCredentialsAndDailyWindow(): void
        {
            $lock = new InstallLockService($this->lockPath);
            $lock->writeInstalledLock('2026-06-19 12:00:00');
            $before = time();
            $calls = [];

            $reporter = new PlatformReporter($lock, static function (string $path, array $payload) use (&$calls): array {
                $calls[] = compact('path', 'payload');

                return [
                    'data' => [
                        'instance_id' => 'd3ec761b-c5d1-4663-8c76-7d2d351efad5',
                        'token' => 'mbt_token',
                    ],
                ];
            });
            $reporter->tick('backend_php');

            $this->assertSame('/api/v1/telemetry/activate', $calls[0]['path'] ?? null);
            $this->assertSame('mallbase', $calls[0]['payload']['app_code'] ?? null);

            $state = $lock->getPlatformState();
            $this->assertSame('d3ec761b-c5d1-4663-8c76-7d2d351efad5', $state['instance_id'] ?? null);
            $this->assertSame('mbt_token', $state['token'] ?? null);
            $this->assertSame('', $state['last_report_error'] ?? null);
            $this->assertGreaterThanOrEqual($before, $state['last_report_at'] ?? 0);
            $this->assertGreaterThanOrEqual($before + 86400, $state['next_report_after'] ?? 0);
            $this->assertLessThanOrEqual(time() + 86400, $state['next_report_after'] ?? 0);
        }

        public function testHeartbeatFailureKeepsShortRetryWindow(): void
        {
            $lock = new InstallLockService($this->lockPath);
            $lock->writeInstalledLock('2026-06-19 12:00:00');
            $lock->savePlatformState([
                'instance_id' => 'd3ec761b-c5d1-4663-8c76-7d2d351efad5',
                'token' => 'mbt_token',
            ]);
            $before = time();
            $calls = [];

            $reporter = new PlatformReporter($lock, static function (string $path, array $payload, array $headers) use (&$calls): array {
                $calls[] = compact('path', 'payload', 'headers');

                return ['data' => ['accepted' => false]];
            });
            $reporter->tick('admin_web');

            $this->assertSame('/api/v1/telemetry/heartbeat', $calls[0]['path'] ?? null);
            $this->assertSame('Bearer mbt_token', $calls[0]['headers']['Authorization'] ?? null);

            $state = $lock->getPlatformState();
            $this->assertSame('heartbeat_rejected', $state['last_report_error'] ?? null);
            $this->assertArrayNotHasKey('last_report_at', $state);
            $this->assertGreaterThanOrEqual($before + 300, $state['next_report_after'] ?? 0);
            $this->assertLessThanOrEqual(time() + 300, $state['next_report_after'] ?? 0);
        }

        public function testPendingActivationWindowIsRepairedAfterOldFailure(): void
        {
            $lock = new InstallLockService($this->lockPath);
            $lock->writeInstalledLock('2026-06-19 12:00:00');
            $lock->savePlatformState([
                'next_report_after' => time() + 86400,
            ]);
            $calls = [];

            $reporter = new PlatformReporter($lock, static function (string $path) use (&$calls): array {
                $calls[] = $path;

                return ['_error' => 'http_500'];
            });
            $reporter->tick('admin_web');

            $state = $lock->getPlatformState();
            $this->assertSame(['/api/v1/telemetry/activate'], $calls);
            $this->assertSame('http_500', $state['last_report_error'] ?? null);
            $this->assertLessThanOrEqual(time() + 300, $state['next_report_after'] ?? 0);
        }

        public function testFormatHeadersAcceptsAssociativeHeaders(): void
        {
            $method = new \ReflectionMethod(PlatformReporter::class, 'formatHeaders');
            $reporter = new PlatformReporter();

            $this->assertSame([
                'Authorization: Bearer mbt_token',
                'X-Test: 1',
                'Accept: application/json',
            ], $method->invoke($reporter, [
                'Authorization' => 'Bearer mbt_token',
                'X-Test' => '1',
                'Accept: application/json',
            ]));
        }
    }
}
