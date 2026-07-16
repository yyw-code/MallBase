<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\SimpleSqlMigrationService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SimpleSqlMigrationServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const OTHER_JOB_ID = '22222222-2222-4222-8222-222222222222';
    private const MIGRATION_ID = '20260714_demo';

    private string $root;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-simple-migration-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/run', 0770, true);
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
            class_exists(SimpleSqlMigrationService::class),
            'SimpleSqlMigrationService production class is missing',
        );
    }

    public function testExecutesCheckpointedStatementsAndReplaysCompletedResult(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS demo (id INTEGER PRIMARY KEY)\n"
            . "-- mallbase:statement-breakpoint\n"
            . 'CREATE INDEX IF NOT EXISTS idx_demo_id ON demo(id)';
        $this->writeMigration($sql);
        $service = $this->service();

        $first = $service->execute(
            self::JOB_ID,
            self::MIGRATION_ID,
            '1.2.0',
            'migrations/' . self::MIGRATION_ID . '.sql',
            hash('sha256', $sql),
        );
        $second = $service->execute(
            self::JOB_ID,
            self::MIGRATION_ID,
            '1.2.0',
            'migrations/' . self::MIGRATION_ID . '.sql',
            hash('sha256', $sql),
        );

        self::assertSame($first, $second);
        self::assertSame('completed', $first['state']);
        self::assertSame(2, $first['statement_count']);
        self::assertSame(2, $first['next_statement']);
        self::assertFileExists($this->root . '/run/simple-migrations.lock');
        $checkpoint = json_decode(
            (string) file_get_contents($this->root . '/run/simple-migrations.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('completed', $checkpoint['migrations'][0]['state'] ?? null);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='demo'")->fetchColumn());
    }

    public function testRejectsPathHashAndForbiddenStatementBeforeExecution(): void
    {
        $sql = 'CREATE TABLE forbidden (id INTEGER PRIMARY KEY)';
        $this->writeMigration($sql);
        $service = $this->service();

        $cases = [
            ['migrations/other.sql', hash('sha256', $sql)],
            ['migrations/' . self::MIGRATION_ID . '.sql', str_repeat('0', 64)],
        ];
        foreach ($cases as [$path, $hash]) {
            $this->assertFailure(static fn() => $service->execute(
                self::JOB_ID,
                self::MIGRATION_ID,
                '1.2.0',
                $path,
                $hash,
            ));
        }

        $forbidden = 'BEGIN';
        $this->writeMigration($forbidden);
        $this->assertFailure(static fn() => $service->execute(
            self::JOB_ID,
            self::MIGRATION_ID,
            '1.2.0',
            'migrations/' . self::MIGRATION_ID . '.sql',
            hash('sha256', $forbidden),
        ));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='forbidden'")->fetchColumn());
    }

    public function testForgetJobRemovesOnlyItsMigrationCheckpointsAndIsIdempotent(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS demo (id INTEGER PRIMARY KEY)';
        $this->writeMigration($sql);
        mkdir($this->root . '/staging/' . self::OTHER_JOB_ID . '/migrations', 0770, true);
        file_put_contents(
            $this->root . '/staging/' . self::OTHER_JOB_ID . '/migrations/' . self::MIGRATION_ID . '.sql',
            $sql,
        );
        $service = $this->service();
        foreach ([self::JOB_ID, self::OTHER_JOB_ID] as $jobId) {
            $service->execute(
                $jobId,
                self::MIGRATION_ID,
                '1.2.0',
                'migrations/' . self::MIGRATION_ID . '.sql',
                hash('sha256', $sql),
            );
        }

        $service->forgetJob(self::JOB_ID);
        $service->forgetJob(self::JOB_ID);

        $checkpoint = json_decode(
            (string) file_get_contents($this->root . '/run/simple-migrations.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame([self::OTHER_JOB_ID], array_column($checkpoint['migrations'], 'job_id'));
    }

    private function service(): SimpleSqlMigrationService
    {
        return new SimpleSqlMigrationService($this->root, fn(): PDO => $this->pdo);
    }

    private function writeMigration(string $sql): void
    {
        file_put_contents(
            $this->root . '/staging/' . self::JOB_ID . '/migrations/' . self::MIGRATION_ID . '.sql',
            $sql,
        );
    }

    private function assertFailure(callable $callback): void
    {
        try {
            $callback();
            self::fail('invalid migration input was accepted');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('SIMPLE_MIGRATION_', $exception->getMessage());
        }
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
