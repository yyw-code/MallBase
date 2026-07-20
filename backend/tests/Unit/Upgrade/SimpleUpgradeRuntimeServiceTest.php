<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\SimpleDatabaseSnapshotService;
use app\service\upgrade\SimpleSqlMigrationService;
use app\service\upgrade\SimpleUpgradeGate;
use app\service\upgrade\SimpleUpgradeRuntimeService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SimpleUpgradeRuntimeServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const ROLLBACK_JOB_ID = '22222222-2222-4222-8222-222222222222';

    private string $root;
    private SimpleUpgradeGate $gate;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-simple-runtime-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0770, true);
        $this->gate = new SimpleUpgradeGate($this->root . '/run');
        mkdir($this->root . '/staging/' . self::JOB_ID . '/migrations', 0770, true);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testProductionServiceExists(): void
    {
        self::assertTrue(
            class_exists(SimpleUpgradeRuntimeService::class),
            'SimpleUpgradeRuntimeService production class is missing',
        );
    }

    public function testRunsPauseBackupRestoreMigrationAndAwaitingRestartWorkflow(): void
    {
        $service = $this->service();
        $paused = $service->pause(self::JOB_ID, [
            'action' => 'upgrade',
            'source_version' => '1.2.0',
            'target_version' => '1.3.0',
        ]);
        self::assertSame('paused', $paused['state']);

        $backup = $service->backup(self::JOB_ID, []);
        self::assertSame('upgrade/backups/' . self::JOB_ID . '/database.sql', $backup['database_path']);
        $restored = $service->restore(self::ROLLBACK_JOB_ID, [
            'source_job_id' => self::JOB_ID,
            'database_path' => $backup['database_path'],
            'database_sha256' => $backup['database_sha256'],
        ]);
        self::assertSame('restored', $restored['state']);

        $sql = 'CREATE TABLE IF NOT EXISTS migrated (id INTEGER PRIMARY KEY)';
        file_put_contents($this->root . '/staging/' . self::JOB_ID . '/migrations/demo.sql', $sql);
        $migration = $service->migrate(self::JOB_ID, [
            'migration_id' => 'demo',
            'version' => '1.3.0',
            'path' => 'migrations/demo.sql',
            'sha256' => hash('sha256', $sql),
        ]);
        self::assertSame('completed', $migration['state']);

        $awaiting = $service->awaitingRestart(self::JOB_ID, [
            'action' => 'upgrade',
            'target_version' => '1.3.0',
        ]);
        self::assertSame('awaiting_php_restart', $awaiting['state']);
        self::assertSame('awaiting_php_restart', $this->gate->state());
    }

    public function testRestoreMigrationAndAwaitingRequirePausedGate(): void
    {
        $service = $this->service();
        foreach ([
            fn() => $service->backup(self::JOB_ID, []),
            fn() => $service->restore(self::JOB_ID, [
                'source_job_id' => self::JOB_ID,
                'database_path' => 'upgrade/backups/' . self::JOB_ID . '/database.sql',
                'database_sha256' => str_repeat('0', 64),
            ]),
            fn() => $service->migrate(self::JOB_ID, [
                'migration_id' => 'demo',
                'version' => '1.3.0',
                'path' => 'migrations/demo.sql',
                'sha256' => str_repeat('0', 64),
            ]),
            fn() => $service->awaitingRestart(self::JOB_ID, [
                'action' => 'upgrade',
                'target_version' => '1.3.0',
            ]),
        ] as $operation) {
            try {
                $operation();
                self::fail('operation entered before pause');
            } catch (RuntimeException $exception) {
                self::assertSame('SIMPLE_UPGRADE_GATE_NOT_PAUSED', $exception->getMessage());
            }
        }
    }

    public function testRestoreForgetsMigrationCheckpointsFromSourceUpgradeJob(): void
    {
        $service = $this->service();
        $service->pause(self::JOB_ID, [
            'action' => 'upgrade',
            'source_version' => '1.2.0',
            'target_version' => '1.3.0',
        ]);
        $backup = $service->backup(self::JOB_ID, []);
        $sql = 'CREATE TABLE IF NOT EXISTS migrated (id INTEGER PRIMARY KEY)';
        file_put_contents($this->root . '/staging/' . self::JOB_ID . '/migrations/demo.sql', $sql);
        $service->migrate(self::JOB_ID, [
            'migration_id' => 'demo',
            'version' => '1.3.0',
            'path' => 'migrations/demo.sql',
            'sha256' => hash('sha256', $sql),
        ]);

        $restored = $service->restore(self::ROLLBACK_JOB_ID, [
            'source_job_id' => self::JOB_ID,
            'database_path' => $backup['database_path'],
            'database_sha256' => $backup['database_sha256'],
        ]);

        self::assertSame('restored', $restored['state']);
        $checkpoint = json_decode(
            (string) file_get_contents($this->root . '/run/simple-migrations.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame([], $checkpoint['migrations']);
    }

    public function testResumeOnlyAcceptsExactEmptyBodyAndRestoresPausedGate(): void
    {
        $service = $this->service();
        $service->pause(self::JOB_ID, [
            'action' => 'upgrade',
            'source_version' => '1.2.0',
            'target_version' => '1.3.0',
        ]);

        try {
            $service->resume(self::JOB_ID, ['unknown' => true]);
            self::fail('resume accepted an unknown body field');
        } catch (RuntimeException $exception) {
            self::assertSame('SIMPLE_UPGRADE_INPUT_INVALID', $exception->getMessage());
        }

        self::assertSame(['state' => 'normal'], $service->resume(self::JOB_ID, []));
        self::assertSame('normal', $this->gate->state());
        self::assertSame(['state' => 'normal'], $service->resume(self::JOB_ID, []));
    }

    public function testPauseRejectsUnknownFieldOrInvalidAction(): void
    {
        foreach ([
            [
                'action' => 'upgrade',
                'source_version' => '1.2.0',
                'target_version' => '1.3.0',
                'unknown' => true,
            ],
            [
                'action' => 'downgrade',
                'source_version' => '1.2.0',
                'target_version' => '1.3.0',
            ],
            [
                'action' => 'upgrade',
                'source_version' => '1.2.0',
                'target_version' => '1.2.0',
            ],
        ] as $body) {
            try {
                $this->service()->pause(self::JOB_ID, $body);
                self::fail('invalid pause body was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame('SIMPLE_UPGRADE_INPUT_INVALID', $exception->getMessage());
            }
        }
    }

    public function testRollbackActionMatchesGoControlPlaneContract(): void
    {
        $paused = $this->service()->pause(self::JOB_ID, [
            'action' => 'rollback',
            'source_version' => '1.3.0',
            'target_version' => '1.2.0',
        ]);

        self::assertSame('rollback', $paused['action']);
        self::assertSame('paused', $paused['state']);
    }

    private function service(): SimpleUpgradeRuntimeService
    {
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
        $migrations = new SimpleSqlMigrationService($this->root, fn(): PDO => $this->pdo);

        return new SimpleUpgradeRuntimeService($this->gate, $database, $migrations);
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
