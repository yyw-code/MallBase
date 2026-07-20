<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeSharedFileStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-shared-files-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0770, true);
        mkdir($this->root . '/run', 0770, true);
        chmod($this->root . '/config', 02770);
        chmod($this->root . '/run', 02770);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        putenv('MALLBASE_STORAGE_NAMESPACE');
        parent::tearDown();
    }

    public function testAgentConfigurationNoLongerContainsStorageNamespaceProjection(): void
    {
        putenv('MALLBASE_STORAGE_NAMESPACE=mbs_old');
        $configuration = require dirname(__DIR__, 3) . '/config/agent.php';

        self::assertArrayNotHasKey('upgrade_namespace_id', $configuration);
        self::assertArrayHasKey('upgrade_root', $configuration);
        self::assertArrayHasKey('agent_uid', $configuration);
        self::assertArrayHasKey('expected_gid', $configuration);
    }

    public function testInstanceDocumentCanBeWrittenAndRead(): void
    {
        $store = $this->store();
        $document = (object) ['schema_version' => 1, 'revision' => 1, 'token' => 'token'];

        $store->writeJson('instance', $document);

        self::assertEquals($document, $store->readJson('instance'));
        self::assertSame(0660, fileperms($this->root . '/config/instance.json') & 0777);
    }

    public function testOldLogicalDocumentsAreUnavailable(): void
    {
        $store = $this->store();
        foreach (['agent_status', 'upgrade_gate', 'namespace_projection', 'session_auth', 'runtime_registry'] as $name) {
            $this->assertFailure('SHARED_FILE_UNAVAILABLE', fn() => $store->readJson($name));
        }
    }

    public function testInstanceLockExecutesCallback(): void
    {
        self::assertSame('locked', $this->store()->withInstanceLock(static fn(): string => 'locked'));
    }

    public function testWorldReadableDocumentIsRejected(): void
    {
        $path = $this->root . '/config/instance.json';
        file_put_contents($path, "{}\n");
        chmod($path, 0664);

        $this->assertFailure('SHARED_FILE_PERMISSION_INVALID', fn() => $this->store()->readJson('instance'));
    }

    private function store(): UpgradeSharedFileStore
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $gid = function_exists('posix_getegid') ? posix_getegid() : getmygid();

        return new UpgradeSharedFileStore($this->root, $uid, $gid, $uid, 65536, 50);
    }

    private function assertFailure(string $message, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
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
