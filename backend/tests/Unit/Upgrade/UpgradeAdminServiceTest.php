<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\model\upgrade\UpgradeRecord;
use app\service\admin\upgrade\UpgradeAdminService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeAdminServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const CREATED_JOB_ID = '22222222-2222-4222-8222-222222222222';
    private const TICKET = 'abcdefghijklmnopqrstuvwxyzABCDEFGH012345678';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-admin-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/jobs/' . self::JOB_ID, 0770, true);
        mkdir($this->root . '/run', 02770, true);
        chmod($this->root . '/run', 02770);
        mkdir($this->root . '/run/requests', 02770);
        chmod($this->root . '/run/requests', 02770);
        mkdir($this->root . '/runtime/install', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testListsSafeGoRecordProjection(): void
    {
        file_put_contents($this->root . '/jobs/' . self::JOB_ID . '/record.json', json_encode([
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'action' => 'upgrade',
            'source_version' => '1.2.2',
            'target_version' => '1.2.3',
            'status' => 'succeeded',
            'backup_path' => 'backups/' . self::JOB_ID,
            'package_path' => 'packages/' . self::JOB_ID . '.tar.gz',
            'created_at' => 100,
            'started_at' => 101,
            'finished_at' => 102,
            'error' => '',
        ], JSON_THROW_ON_ERROR));

        $result = (new UpgradeAdminService($this->root))->getList(1, 20);

        self::assertSame(1, $result['total']);
        self::assertSame('upgrade/backups/' . self::JOB_ID, $result['list'][0]['backup_path']);
        self::assertSame('succeeded', $result['list'][0]['status']);
    }

    public function testRejectsArtifactPathTraversal(): void
    {
        file_put_contents($this->root . '/jobs/' . self::JOB_ID . '/record.json', json_encode([
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'action' => 'upgrade',
            'source_version' => '1.2.2',
            'target_version' => '1.2.3',
            'status' => 'failed',
            'backup_path' => 'backups/../secret',
            'package_path' => '',
            'created_at' => 100,
            'started_at' => 100,
            'finished_at' => 101,
            'error' => 'failed',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RECORD_INVALID');
        (new UpgradeRecord())->scan($this->root);
    }

    public function testCreatesQueuedOneShotRequestWithoutPersistingSecrets(): void
    {
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1000,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => self::CREATED_JOB_ID,
        );

        $result = $service->createJob(7, 'upgrade', '1.2.0');

        $hash = hash('sha256', self::TICKET);
        $path = $this->root . '/run/requests/' . self::CREATED_JOB_ID . '.json';
        self::assertSame(self::CREATED_JOB_ID, $result['job_id']);
        self::assertSame('queued', $result['status']);
        self::assertSame('/upgrade/?ticket=' . self::TICKET, $result['status_url']);
        self::assertSame(1600, $result['expires_at']);
        self::assertFileExists($path);
        self::assertSame(02770, fileperms($this->root . '/run') & 07777);
        self::assertSame(02770, fileperms($this->root . '/run/requests') & 07777);
        self::assertSame(0660, fileperms($path) & 0777);
        $stored = (string) file_get_contents($path);
        self::assertStringNotContainsString(self::TICKET, $stored);
        self::assertStringNotContainsString('platform_token', $stored);
        self::assertStringNotContainsString('mbt_upgrade_token', $stored);
        self::assertSame([
            'schema_version' => 1,
            'job_id' => self::CREATED_JOB_ID,
            'action' => 'upgrade',
            'target_version' => '1.2.0',
            'requested_by' => 7,
            'ticket_hash' => $hash,
            'created_at' => 1000,
            'expires_at' => 1600,
        ], json_decode($stored, true, 32, JSON_THROW_ON_ERROR));
        $record = json_decode(
            (string) file_get_contents($this->root . '/jobs/' . self::CREATED_JOB_ID . '/record.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('queued', $record['status'] ?? null);
        self::assertSame('upgrade', $record['action'] ?? null);
        self::assertSame('1.2.0', $record['target_version'] ?? null);
        self::assertArrayNotHasKey('log_path', $record);
    }

    public function testRejectsInvalidOneShotJobArguments(): void
    {
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1000,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => self::CREATED_JOB_ID,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_ENTRY_ARGUMENT_INVALID');
        $service->createJob(7, 'upgrade', 'latest');
    }

    public function testRejectsASecondActiveJobInsideTheCreationLock(): void
    {
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1000,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => self::CREATED_JOB_ID,
        );
        $service->createJob(7, 'upgrade', '1.2.0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_ENTRY_CONFLICT');
        (new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1001,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => '33333333-3333-4333-8333-333333333333',
        ))->createJob(8, 'rollback');
    }

    public function testReturnsCurrentReleaseOverviewWithoutPlatformAccess(): void
    {
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            currentReleaseReader: static fn(): array => [
                'version' => '1.0.0',
                'released_at' => '2026-04-23 12:00:00',
                'notes' => ['安装模块契约统一', '后台路由与响应风格收口'],
            ],
        );

        self::assertSame([
            'current' => [
                'version' => '1.0.0',
                'released_at' => '2026-04-23 12:00:00',
                'notes' => ['安装模块契约统一', '后台路由与响应风格收口'],
            ],
        ], $service->getOverview());
    }

    public function testCreatesRollbackRequestWithoutTargetVersion(): void
    {
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1000,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => self::CREATED_JOB_ID,
        );

        $service->createJob(7, 'rollback', '');

        $stored = json_decode(
            (string) file_get_contents($this->root . '/run/requests/' . self::CREATED_JOB_ID . '.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('rollback', $stored['action'] ?? null);
        self::assertSame('', $stored['target_version'] ?? null);
    }

    public function testJobRequestRejectsAnExistingSharedDirectoryWithTheWrongMode(): void
    {
        chmod($this->root . '/run', 0770);
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1000,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => self::CREATED_JOB_ID,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_ENTRY_UNAVAILABLE');
        $service->createJob(7, 'upgrade', '1.2.0');
    }

    public function testJobRequestDoesNotCreateTheSharedRunDirectory(): void
    {
        rmdir($this->root . '/run/requests');
        rmdir($this->root . '/run');
        $service = new UpgradeAdminService(
            configuredRoot: $this->root,
            clock: static fn(): int => 1000,
            ticketFactory: static fn(): string => self::TICKET,
            jobIdFactory: static fn(): string => self::CREATED_JOB_ID,
        );

        try {
            $service->createJob(7, 'upgrade', '1.2.0');
            self::fail('job request created the shared run directory');
        } catch (RuntimeException $exception) {
            self::assertSame('UPGRADE_ENTRY_UNAVAILABLE', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/run');
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
