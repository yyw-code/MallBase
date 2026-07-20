<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\SimpleDatabaseSnapshotService;
use app\service\upgrade\SimpleSqlMigrationService;
use app\service\upgrade\SimpleUpgradeCliService;
use app\service\upgrade\SimpleUpgradeGate;
use app\service\upgrade\SimpleUpgradeRuntimeService;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use PDO;
use PHPUnit\Framework\TestCase;

final class SimpleUpgradeCliServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const ROLLBACK_JOB_ID = '22222222-2222-4222-8222-222222222222';

    private string $root;
    private SimpleUpgradeCliService $cli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-simple-cli-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/run', 0770, true);
        mkdir($this->root . '/staging/' . self::JOB_ID . '/migrations', 0770, true);
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database = new SimpleDatabaseSnapshotService(
            $this->root,
            '/opt/bin/mariadb-dump',
            '/opt/bin/mariadb',
            [
                'host' => 'db',
                'port' => 3306,
                'user' => 'mallbase',
                'password' => 'secret',
                'database' => 'mallbase',
                'charset' => 'utf8mb4',
            ],
            static function (array $argv, array $environment, mixed $stdin, mixed $stdout): int {
                if (str_ends_with($argv[0], 'mariadb-dump')) {
                    fwrite($stdout, "-- dump\n");
                } else {
                    stream_get_contents($stdin);
                }

                return 0;
            },
        );
        $runtime = new SimpleUpgradeRuntimeService(
            new SimpleUpgradeGate($this->root . '/run'),
            $database,
            new SimpleSqlMigrationService($this->root, static fn(): PDO => $pdo),
        );
        $this->cli = new SimpleUpgradeCliService($runtime, new UpgradeStrictJsonDecoder(8192));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testSixOperationsUseOneStrictEnvelopeAndStableOutput(): void
    {
        $pause = $this->call('pause', self::JOB_ID, [
            'action' => 'upgrade',
            'source_version' => '1.2.0',
            'target_version' => '1.3.0',
        ]);
        self::assertSame(0, $pause['exit_code']);
        self::assertSame('paused', $this->decoded($pause)['data']['state']);

        $backup = $this->call('backup_database', self::JOB_ID, []);
        self::assertSame(0, $backup['exit_code']);
        $backupData = $this->decoded($backup)['data'];

        $sql = 'CREATE TABLE IF NOT EXISTS migrated (id INTEGER PRIMARY KEY)';
        file_put_contents($this->root . '/staging/' . self::JOB_ID . '/migrations/demo.sql', $sql);
        $migration = $this->call('migrate', self::JOB_ID, [
            'migration_id' => 'demo',
            'version' => '1.3.0',
            'path' => 'migrations/demo.sql',
            'sha256' => hash('sha256', $sql),
        ]);
        self::assertSame('completed', $this->decoded($migration)['data']['state']);

        $restore = $this->call('restore_database', self::ROLLBACK_JOB_ID, [
            'source_job_id' => self::JOB_ID,
            'database_path' => $backupData['database_path'],
            'database_sha256' => $backupData['database_sha256'],
        ]);
        self::assertSame('restored', $this->decoded($restore)['data']['state']);

        $awaiting = $this->call('awaiting_restart', self::JOB_ID, [
            'action' => 'upgrade',
            'target_version' => '1.3.0',
        ]);
        self::assertSame('awaiting_php_restart', $this->decoded($awaiting)['data']['state']);

        $resume = $this->call('resume', self::JOB_ID, []);
        self::assertSame(['state' => 'normal'], $this->decoded($resume)['data']);
        self::assertSame(0, $this->call('resume', self::JOB_ID, [])['exit_code']);

        foreach ([$pause, $backup, $migration, $restore, $awaiting, $resume] as $result) {
            self::assertStringEndsWith("\n", $result['stdout']);
            self::assertSame('', $result['stderr']);
            self::assertSame(
                ['schema_version', 'ok', 'operation', 'job_id', 'data', 'error'],
                array_keys($this->decoded($result)),
            );
        }
    }

    /** @dataProvider invalidInputProvider */
    public function testInvalidInputReturnsExitTwoWithoutLeakingDetails(string $stdin): void
    {
        $result = $this->cli->handle($stdin);
        $output = $this->decoded($result);

        self::assertSame(2, $result['exit_code']);
        self::assertSame('', $result['stderr']);
        self::assertFalse($output['ok']);
        self::assertNull($output['data']);
        self::assertIsArray($output['error']);
        self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]{0,127}$/D', $output['error']['code']);
        self::assertStringNotContainsString('trace', strtolower($result['stdout']));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'duplicate key' => ['{"schema_version":1,"schema_version":1,"operation":"resume","job_id":"' . self::JOB_ID . '","payload":{}}'];
        yield 'unknown top-level key' => ['{"schema_version":1,"operation":"resume","job_id":"' . self::JOB_ID . '","payload":{},"unknown":true}'];
        yield 'unsupported operation' => ['{"schema_version":1,"operation":"shell","job_id":"' . self::JOB_ID . '","payload":{}}'];
        yield 'raw sql field' => ['{"schema_version":1,"operation":"migrate","job_id":"' . self::JOB_ID . '","payload":{"sql":"DROP TABLE users"}}'];
        yield 'trailing json' => ['{"schema_version":1,"operation":"resume","job_id":"' . self::JOB_ID . '","payload":{}}{}'];
        yield 'array root' => ['[]'];
        yield 'oversized' => [str_repeat('x', 8193)];
    }

    /** @param array<string,mixed> $payload @return array{exit_code:int,stdout:string,stderr:string} */
    private function call(string $operation, string $jobId, array $payload): array
    {
        return $this->cli->handle(json_encode([
            'schema_version' => 1,
            'operation' => $operation,
            'job_id' => $jobId,
            'payload' => (object) $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array{stdout:string} $result @return array<string,mixed> */
    private function decoded(array $result): array
    {
        $decoded = json_decode(trim($result['stdout']), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
