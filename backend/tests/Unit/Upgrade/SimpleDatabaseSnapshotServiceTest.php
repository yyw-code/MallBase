<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\SimpleDatabaseSnapshotService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SimpleDatabaseSnapshotServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-simple-database-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testProductionServiceExists(): void
    {
        self::assertTrue(
            class_exists(SimpleDatabaseSnapshotService::class),
            'SimpleDatabaseSnapshotService production class is missing',
        );
    }

    public function testBackupUsesArgvAndMysqlPwdThenReplaysLegalFinal(): void
    {
        $calls = [];
        $service = $this->service(static function (array $argv, array $environment, mixed $stdin, mixed $stdout) use (&$calls): int {
            $calls[] = [$argv, $environment, $stdin];
            fwrite($stdout, "-- portable dump\nCREATE TABLE demo(id INT);\n");

            return 0;
        });

        $first = $service->backup(self::JOB_ID);
        $second = $service->backup(self::JOB_ID);

        self::assertSame($first, $second);
        self::assertCount(1, $calls);
        self::assertSame('/opt/bin/mariadb-dump', $calls[0][0][0]);
        self::assertContains('--host=db.internal', $calls[0][0]);
        self::assertContains('--port=3307', $calls[0][0]);
        self::assertContains('--user=mallbase', $calls[0][0]);
        self::assertContains('--add-drop-database', $calls[0][0]);
        self::assertContains('--databases', $calls[0][0]);
        self::assertSame('mallbase_db', $calls[0][0][count($calls[0][0]) - 1]);
        self::assertNotContains('secret-canary', $calls[0][0]);
        self::assertSame(['MYSQL_PWD' => 'secret-canary'], $calls[0][1]);
        self::assertNull($calls[0][2]);
        self::assertSame('upgrade/backups/' . self::JOB_ID . '/database.sql', $first['database_path']);
        self::assertSame(filesize($this->root . '/backups/' . self::JOB_ID . '/database.sql'), $first['size']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $first['database_sha256']);
    }

    public function testRestoreUsesExactVerifiedBackupAsStdinWithoutShell(): void
    {
        $dump = "-- dump\nCREATE TABLE demo(id INT);\n";
        $path = $this->root . '/backups/' . self::JOB_ID;
        mkdir($path, 0770, true);
        file_put_contents($path . '/database.sql', $dump);
        $calls = [];
        $service = $this->service(static function (array $argv, array $environment, mixed $stdin, mixed $stdout) use (&$calls): int {
            $calls[] = [$argv, $environment, stream_get_contents($stdin), is_resource($stdout)];

            return 0;
        });

        $result = $service->restore(
            self::JOB_ID,
            'upgrade/backups/' . self::JOB_ID . '/database.sql',
            hash('sha256', $dump),
        );

        self::assertSame('restored', $result['state']);
        self::assertSame('/opt/bin/mariadb', $calls[0][0][0]);
        self::assertSame('mallbase_db', $calls[0][0][count($calls[0][0]) - 1]);
        self::assertSame(['MYSQL_PWD' => 'secret-canary'], $calls[0][1]);
        self::assertSame($dump, $calls[0][2]);
        self::assertTrue($calls[0][3]);
    }

    public function testRestoreRejectsWrongPathOrHashBeforeRunner(): void
    {
        $directory = $this->root . '/backups/' . self::JOB_ID;
        mkdir($directory, 0770, true);
        file_put_contents($directory . '/database.sql', 'dump');
        $runnerCalls = 0;
        $service = $this->service(static function () use (&$runnerCalls): int {
            $runnerCalls++;

            return 0;
        });

        foreach ([
            ['upgrade/backups/22222222-2222-4222-8222-222222222222/database.sql', hash('sha256', 'dump')],
            ['upgrade/backups/' . self::JOB_ID . '/database.sql', str_repeat('0', 64)],
        ] as [$path, $hash]) {
            try {
                $service->restore(self::JOB_ID, $path, $hash);
                self::fail('invalid restore input was accepted');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith('SIMPLE_DATABASE_', $exception->getMessage());
            }
        }
        self::assertSame(0, $runnerCalls);
    }

    private function service(callable $runner): SimpleDatabaseSnapshotService
    {
        return new SimpleDatabaseSnapshotService(
            upgradeRoot: $this->root,
            dumpExecutable: '/opt/bin/mariadb-dump',
            restoreExecutable: '/opt/bin/mariadb',
            database: [
                'host' => 'db.internal',
                'port' => 3307,
                'user' => 'mallbase',
                'password' => 'secret-canary',
                'database' => 'mallbase_db',
                'charset' => 'utf8mb4',
            ],
            runner: $runner,
        );
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
