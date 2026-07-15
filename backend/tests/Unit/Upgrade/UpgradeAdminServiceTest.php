<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\model\upgrade\UpgradeRecord;
use app\service\admin\upgrade\UpgradeAdminService;
use app\service\install\InstallLockService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeAdminServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const TICKET = 'abcdefghijklmnopqrstuvwxyzABCDEFGH012345678';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-admin-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/jobs/' . self::JOB_ID, 0770, true);
        mkdir($this->root . '/run', 02770, true);
        chmod($this->root . '/run', 02770);
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
            'status' => 'awaiting_php_restart',
            'backup_path' => 'backups/' . self::JOB_ID,
            'package_path' => 'packages/' . self::JOB_ID . '.tar.gz',
            'log_path' => 'logs/' . self::JOB_ID . '.log',
            'created_at' => 100,
            'started_at' => 101,
            'finished_at' => 0,
            'error' => '',
        ], JSON_THROW_ON_ERROR));

        $result = (new UpgradeAdminService($this->root))->getList(1, 20);

        self::assertSame(1, $result['total']);
        self::assertSame('upgrade/backups/' . self::JOB_ID, $result['list'][0]['backup_path']);
        self::assertSame('awaiting_php_restart', $result['list'][0]['status']);
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
            'log_path' => '',
            'created_at' => 100,
            'started_at' => 100,
            'finished_at' => 101,
            'error' => 'failed',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RECORD_INVALID');
        (new UpgradeRecord())->scan($this->root);
    }

    public function testCreatesShortLivedHashedTicketWithoutPersistingRawSecret(): void
    {
        $service = new UpgradeAdminService(
            $this->root,
            static fn(): int => 1000,
            static fn(): string => self::TICKET,
            $this->installLock(),
        );

        $result = $service->createEntryTicket(7);

        $hash = hash('sha256', self::TICKET);
        $path = $this->root . '/run/access-tickets/' . $hash . '.json';
        self::assertSame('/upgrade/?ticket=' . self::TICKET, $result['upgrade_url']);
        self::assertSame(1060, $result['expires_at']);
        self::assertFileExists($path);
        self::assertSame(02770, fileperms($this->root . '/run') & 07777);
        self::assertSame(02770, fileperms($this->root . '/run/access-tickets') & 07777);
        self::assertSame(0660, fileperms($path) & 0777);
        $stored = (string) file_get_contents($path);
        self::assertStringNotContainsString(self::TICKET, $stored);
        self::assertSame([
            'schema_version' => 1,
            'ticket_hash' => $hash,
            'admin_id' => 7,
            'platform_token' => 'mbt_upgrade_token',
            'issued_at' => 1000,
            'expires_at' => 1060,
        ], json_decode($stored, true, 32, JSON_THROW_ON_ERROR));
        self::assertSame(0755, fileperms($this->root . '/runtime') & 0777);
        self::assertSame(0755, fileperms($this->root . '/runtime/install') & 0777);
        self::assertSame(0600, fileperms($this->root . '/runtime/install/install.lock') & 0777);
    }

    public function testCreatesTargetBoundEntryTicketForDirectUpgrade(): void
    {
        $service = new UpgradeAdminService(
            $this->root,
            static fn(): int => 1000,
            static fn(): string => self::TICKET,
            $this->installLock(),
        );

        $service->createEntryTicket(7, '1.2.0');

        $hash = hash('sha256', self::TICKET);
        $stored = json_decode(
            (string) file_get_contents($this->root . '/run/access-tickets/' . $hash . '.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('1.2.0', $stored['target_version'] ?? null);
    }

    public function testRejectsInvalidTargetBoundEntryTicket(): void
    {
        $service = new UpgradeAdminService(
            $this->root,
            static fn(): int => 1000,
            static fn(): string => self::TICKET,
            $this->installLock(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_ENTRY_ARGUMENT_INVALID');
        $service->createEntryTicket(7, 'latest');
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

    public function testEntryTicketFailsClosedWhenPlatformTokenIsDisabledOrUnavailable(): void
    {
        foreach ([
            ['disabled' => true],
            ['token' => ''],
            ['token' => 'invalid token'],
        ] as $override) {
            $service = new UpgradeAdminService(
                $this->root,
                static fn(): int => 1000,
                static fn(): string => self::TICKET,
                $this->installLock($override),
            );
            try {
                $service->createEntryTicket(7);
                self::fail('entry ticket accepted an unavailable platform token');
            } catch (RuntimeException $exception) {
                self::assertSame('UPGRADE_ENTRY_UNAVAILABLE', $exception->getMessage());
            }
        }
        self::assertSame([], glob($this->root . '/run/access-tickets/*.json') ?: []);
    }

    public function testEntryTicketRejectsAnExistingSharedDirectoryWithTheWrongMode(): void
    {
        chmod($this->root . '/run', 0770);
        $service = new UpgradeAdminService(
            $this->root,
            static fn(): int => 1000,
            static fn(): string => self::TICKET,
            $this->installLock(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_ENTRY_UNAVAILABLE');
        $service->createEntryTicket(7);
    }

    public function testEntryTicketDoesNotCreateTheAgentOwnedRunDirectory(): void
    {
        rmdir($this->root . '/run');
        $service = new UpgradeAdminService(
            $this->root,
            static fn(): int => 1000,
            static fn(): string => self::TICKET,
            $this->installLock(),
        );

        try {
            $service->createEntryTicket(7);
            self::fail('entry ticket created the Agent-owned run directory');
        } catch (RuntimeException $exception) {
            self::assertSame('UPGRADE_ENTRY_UNAVAILABLE', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/run');
    }

    /** @param array<string,mixed> $override */
    private function installLock(array $override = []): InstallLockService
    {
        $path = $this->root . '/runtime/install/install.lock';
        file_put_contents($path, json_encode([
            'installed_at' => '2026-07-14 10:00:00',
            'platform' => array_replace([
                'instance_id' => '22222222-2222-4222-8222-222222222222',
                'token' => 'mbt_upgrade_token',
                'disabled' => false,
            ], $override),
        ], JSON_THROW_ON_ERROR));
        chmod($this->root . '/runtime', 0777);
        chmod($this->root . '/runtime/install', 0777);
        chmod($path, 0777);

        return new InstallLockService($path);
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
