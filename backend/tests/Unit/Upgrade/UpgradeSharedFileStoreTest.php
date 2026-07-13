<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpgradeSharedFileStoreTest extends TestCase
{
    private const AGENT_UID = 31001;
    private const SHARED_GID = 31002;
    private const PHP_UID = 31003;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-shared-store-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        mkdir($this->root . '/config', 02770);
        mkdir($this->root . '/run', 02770);
        mkdir($this->root . '/staging', 0750);
        chmod($this->root, 0750);
        chmod($this->root . '/config', 02770);
        chmod($this->root . '/run', 02770);
        chmod($this->root . '/staging', 0750);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testConfigurationContainsOnlyFixedServerSideValues(): void
    {
        putenv('MALLBASE_AGENT_TEST_PLATFORM_ORIGIN=http://127.0.0.2:8080');
        try {
            $configuration = require dirname(__DIR__, 3) . '/config/agent.php';
        } finally {
            putenv('MALLBASE_AGENT_TEST_PLATFORM_ORIGIN');
        }

        $this->assertSame(65536, $configuration['max_json_bytes']);
        $this->assertSame('https://platform.gosowong.cn', $configuration['platform_origin']);
        $this->assertArrayHasKey('upgrade_root', $configuration);
        $this->assertArrayHasKey('platform_origin', $configuration);
        $this->assertArrayHasKey('expected_gid', $configuration);
        $this->assertArrayHasKey('agent_uid', $configuration);
        $this->assertArrayHasKey('php_euid', $configuration);
        $this->assertArrayHasKey('upgrade_namespace_id', $configuration);
        $this->assertArrayNotHasKey('binary', $configuration);
        $this->assertArrayNotHasKey('relative_path', $configuration);
    }

    public function testLogicalPathAllowlistRejectsTraversalAndReadOnlyWrites(): void
    {
        $store = $this->store();

        $this->assertPublicFailure('SHARED_FILE_UNAVAILABLE', fn() => $store->readJson('../config/instance.json'));
        $this->assertPublicFailure('SHARED_FILE_UNAVAILABLE', fn() => $store->writeJson('namespace_projection', (object) ['schema_version' => 1]));
        $this->assertPublicFailure('SHARED_FILE_UNAVAILABLE', fn() => $store->writeJson('run/instance-config.lock', (object) []));
    }

    public function testMissingDocumentsReturnNullWithoutCreatingCapabilityDirectories(): void
    {
        $store = $this->store();

        $this->assertNull($store->readJson('instance'));
        $this->assertNull($store->readJson('namespace_projection'));
        $this->assertDirectoryDoesNotExist($this->root . '/state');
    }

    public function testReadAndWriteUseCanonicalCompactJsonObjectBytes(): void
    {
        $store = $this->store();
        $document = (object) [
            'schema_version' => 1,
            'nested' => (object) [],
            'enabled' => true,
        ];

        $store->writeJson('instance', $document);

        $this->assertSame('{"schema_version":1,"nested":{},"enabled":true}', file_get_contents($this->root . '/config/instance.json'));
        $this->assertEquals($document, $store->readJson('instance'));
        $this->assertSame(0660, fileperms($this->root . '/config/instance.json') & 07777);
    }

    #[DataProvider('invalidJsonProvider')]
    public function testStrictJsonRejectsMalformedDuplicateNestedAndNonObjectDocuments(string $raw): void
    {
        $this->writeRawInstance($raw);

        $this->assertPublicFailure('SHARED_FILE_INVALID', fn() => $this->store()->readJson('instance'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonProvider(): iterable
    {
        yield 'duplicate' => ['{"a":1,"a":2}'];
        yield 'escaped duplicate' => ['{"a":1,"\\u0061":2}'];
        yield 'nested duplicate' => ['{"a":{"b":1,"b":2}}'];
        yield 'array duplicate' => ['{"a":[{"b":1,"\\u0062":2}]}'];
        yield 'null' => ['null'];
        yield 'list' => ['[]'];
        yield 'multiple' => ['{} {}'];
        yield 'trailing comma' => ['{"a":1,}'];
        yield 'invalid number' => ['{"a":01}'];
        yield 'empty' => [''];
    }

    public function testStrictJsonRejectsInvalidUtf8AndOversizedInput(): void
    {
        $this->writeRawInstance("{\"bad\":\"\xFF\"}");
        $this->assertPublicFailure('SHARED_FILE_INVALID', fn() => $this->store()->readJson('instance'));

        $this->writeRawInstance('{"value":"' . str_repeat('a', 65536) . '"}');
        $this->assertPublicFailure('SHARED_FILE_INVALID', fn() => $this->store()->readJson('instance'));
    }

    public function testLayoutRejectsSymlinkHardlinkWrongModeAndWrongOwner(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside, 02770, true);
        rmdir($this->root . '/config');
        symlink($outside, $this->root . '/config');
        $this->assertPublicFailure('SHARED_FILE_PERMISSION_INVALID', fn() => $this->store()->readJson('instance'));
        unlink($this->root . '/config');
        rmdir($outside);

        mkdir($this->root . '/config', 02770);
        chmod($this->root . '/config', 0770);
        $this->assertPublicFailure('SHARED_FILE_PERMISSION_INVALID', fn() => $this->store()->readJson('instance'));
        chmod($this->root . '/config', 02770);

        $this->writeRawInstance('{}');
        link($this->root . '/config/instance.json', $this->root . '/config/instance-copy.json');
        $this->assertPublicFailure('SHARED_FILE_PERMISSION_INVALID', fn() => $this->store()->readJson('instance'));
        unlink($this->root . '/config/instance-copy.json');

        $operations = $this->statOperations();
        $operations['lstat'] = static function (string $path): array|false {
            $stat = lstat($path);
            if ($stat !== false) {
                $stat['uid'] = 99999;
            }

            return $stat;
        };
        $this->assertPublicFailure('SHARED_FILE_PERMISSION_INVALID', fn() => $this->store($operations)->readJson('instance'));
    }

    public function testWritePreservesStablePermissionFailureForInvalidExistingTarget(): void
    {
        $this->writeRawInstance('{}');
        chmod($this->root . '/config/instance.json', 0644);

        $this->assertPublicFailure(
            'SHARED_FILE_PERMISSION_INVALID',
            fn() => $this->store()->writeJson('instance', (object) ['revision' => 2]),
        );
        $this->assertSame('{}', file_get_contents($this->root . '/config/instance.json'));
    }

    public function testConstructorRejectsUnknownPhpIdentityAndAgentPhpIdentityCollision(): void
    {
        $this->assertPublicFailure(
            'SHARED_FILE_PERMISSION_INVALID',
            fn() => new UpgradeSharedFileStore($this->root, self::AGENT_UID, self::SHARED_GID, -1, 65536, 20, $this->statOperations()),
        );
        $this->assertPublicFailure(
            'SHARED_FILE_PERMISSION_INVALID',
            fn() => new UpgradeSharedFileStore($this->root, self::AGENT_UID, self::SHARED_GID, self::AGENT_UID, 65536, 20, $this->statOperations()),
        );
    }

    public function testCompleteShortWritesAreRetriedButZeroWriteFailsWithoutPublishing(): void
    {
        $operations = $this->statOperations();
        $operations['fwrite'] = static fn($handle, string $bytes): int|false => fwrite($handle, substr($bytes, 0, 3));
        $this->store($operations)->writeJson('instance', (object) ['revision' => 1]);
        $this->assertSame('{"revision":1}', file_get_contents($this->root . '/config/instance.json'));

        $old = file_get_contents($this->root . '/config/instance.json');
        $operations['fwrite'] = static fn(): int => 0;
        $this->assertPublicFailure('SHARED_FILE_UNAVAILABLE', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]));
        $this->assertSame($old, file_get_contents($this->root . '/config/instance.json'));
        $this->assertSame([], glob($this->root . '/config/.instance.*.tmp') ?: []);
    }

    #[DataProvider('preRenameFaultProvider')]
    public function testEveryPreRenameFaultLeavesOldBytesPublished(string $checkpoint): void
    {
        $this->store()->writeJson('instance', (object) ['revision' => 1]);
        $old = file_get_contents($this->root . '/config/instance.json');
        $operations = $this->statOperations();
        $operations['fault'] = static function (string $current) use ($checkpoint): void {
            if ($current === $checkpoint) {
                throw new \RuntimeException('secret-internal-fault');
            }
        };

        $this->assertPublicFailure('SHARED_FILE_UNAVAILABLE', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]));
        $this->assertSame($old, file_get_contents($this->root . '/config/instance.json'));
        $this->assertSame([], glob($this->root . '/config/.instance.*.tmp') ?: []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preRenameFaultProvider(): iterable
    {
        foreach (['after_temp_create', 'after_write', 'after_fflush', 'after_file_fsync', 'after_verify'] as $checkpoint) {
            yield $checkpoint => [$checkpoint];
        }
    }

    public function testNativeOperationFailuresBeforeRenameDoNotPublish(): void
    {
        foreach (['fwrite', 'fflush', 'fsync', 'rename', 'fstat', 'lstat'] as $operation) {
            @unlink($this->root . '/config/instance.json');
            $operations = $this->statOperations();
            $operations[$operation] = static fn() => false;
            $this->assertPublicFailure(
                'SHARED_FILE_UNAVAILABLE',
                fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]),
            );
            $this->assertFileDoesNotExist($this->root . '/config/instance.json');
        }
    }

    #[DataProvider('postRenameFaultProvider')]
    public function testEveryPostRenameFaultReturnsDurabilityUncertainAndRereadsVisibleMatch(string $checkpoint): void
    {
        $observed = [];
        $operations = $this->statOperations();
        $operations['fault'] = static function (string $current) use ($checkpoint, &$observed): void {
            $observed[] = $current;
            if ($current === $checkpoint) {
                throw new \RuntimeException('token=must-not-leak');
            }
        };

        $this->assertPublicFailure('DURABILITY_UNCERTAIN', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]));
        $this->assertSame('{"revision":2}', file_get_contents($this->root . '/config/instance.json'));
        $this->assertContains('uncertain_reread_match', $observed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function postRenameFaultProvider(): iterable
    {
        foreach (['after_rename', 'before_parent_fsync', 'after_parent_fsync'] as $checkpoint) {
            yield $checkpoint => [$checkpoint];
        }
    }

    #[DataProvider('uncertainVisibleStateProvider')]
    public function testUncertainRereadClassifiesMismatchAndMissingWithoutLeakingBytes(string $state, string $expectedCheckpoint): void
    {
        $observed = [];
        $target = $this->root . '/config/instance.json';
        $operations = $this->statOperations();
        $operations['fault'] = static function (string $checkpoint) use ($state, $target, &$observed): void {
            $observed[] = $checkpoint;
            if ($checkpoint !== 'after_rename') {
                return;
            }
            if ($state === 'missing') {
                unlink($target);
            } else {
                file_put_contents($target, '{"revision":999}');
                chmod($target, 0660);
            }
            throw new \RuntimeException('private-bytes');
        };

        $this->assertPublicFailure('DURABILITY_UNCERTAIN', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]));
        $this->assertContains($expectedCheckpoint, $observed);
    }

    /** @return iterable<string, array{string, string}> */
    public static function uncertainVisibleStateProvider(): iterable
    {
        yield 'mismatch' => ['mismatch', 'uncertain_reread_mismatch'];
        yield 'missing' => ['missing', 'uncertain_reread_missing'];
    }

    public function testParentDirectoryOpenOrFsyncFailureIsDurabilityUncertain(): void
    {
        $operations = $this->statOperations();
        $operations['open_dir'] = static fn() => false;
        $this->assertPublicFailure('DURABILITY_UNCERTAIN', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 1]));

        @unlink($this->root . '/config/instance.json');
        $operations = $this->statOperations();
        $operations['fsync'] = static function ($handle): bool {
            $uri = stream_get_meta_data($handle)['uri'] ?? '';

            return !is_dir($uri);
        };
        $this->assertPublicFailure('DURABILITY_UNCERTAIN', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]));
    }

    public function testPostRenameTargetSwapAndWrongDirectoryDescriptorAreDurabilityUncertain(): void
    {
        $target = $this->root . '/config/instance.json';
        $attacker = $this->root . '/config/attacker.json';
        file_put_contents($attacker, '{"revision":999}');
        chmod($attacker, 0660);
        $operations = $this->statOperations();
        $operations['rename'] = static function (string $from, string $to) use ($attacker): bool {
            if (!rename($from, $to)) {
                return false;
            }
            unlink($to);

            return link($attacker, $to);
        };
        $this->assertPublicFailure('DURABILITY_UNCERTAIN', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 1]));
        $this->assertSame('{"revision":999}', file_get_contents($target));

        unlink($target);
        unlink($attacker);
        $operations = $this->statOperations();
        $operations['open_dir'] = fn() => fopen($this->root . '/run', 'r');
        $this->assertPublicFailure('DURABILITY_UNCERTAIN', fn() => $this->store($operations)->writeJson('instance', (object) ['revision' => 2]));
    }

    public function testInstanceLockIsExclusiveBoundedStableAndReleasedAfterClosureException(): void
    {
        $first = $this->store(lockTimeoutMilliseconds: 25);
        $second = $this->store(lockTimeoutMilliseconds: 25);

        $first->withInstanceLock(function () use ($second): void {
            $this->assertPublicFailure('INSTANCE_CONFIG_BUSY', fn() => $second->withInstanceLock(static fn() => null));
        });

        try {
            $first->withInstanceLock(static function (): void {
                throw new \DomainException('child failure');
            });
            self::fail('Expected child failure.');
        } catch (\DomainException $exception) {
            self::assertSame('child failure', $exception->getMessage());
        }

        $this->assertSame('released', $second->withInstanceLock(static fn(): string => 'released'));
        $this->assertSame(0660, fileperms($this->root . '/run/instance-config.lock') & 07777);
    }

    public function testInstanceLockRejectsHardlinksAndNeverRenamesOrUnlinksStableInode(): void
    {
        $store = $this->store();
        $store->withInstanceLock(static fn() => null);
        $path = $this->root . '/run/instance-config.lock';
        $inode = fileinode($path);
        link($path, $this->root . '/run/lock-copy');

        $this->assertPublicFailure('SHARED_FILE_PERMISSION_INVALID', fn() => $store->withInstanceLock(static fn() => null));
        $this->assertSame($inode, fileinode($path));
        unlink($this->root . '/run/lock-copy');
    }

    public function testInstanceLockConvergesWhenAnotherProcessWinsExclusiveCreationRace(): void
    {
        $lockPath = $this->root . '/run/instance-config.lock';
        $operations = $this->statOperations();
        $operations['fopen'] = static function (string $path, string $mode) use ($lockPath) {
            if ($path === $lockPath && $mode === 'x+b') {
                $winner = fopen($path, 'x+b');
                chmod($path, 0660);
                fclose($winner);

                return false;
            }

            return fopen($path, $mode);
        };

        $this->assertSame('converged', $this->store($operations)->withInstanceLock(static fn(): string => 'converged'));
        $this->assertFileExists($lockPath);
    }

    public function testInstanceLockExcludesASeparateProcessWithinTheBoundedDeadline(): void
    {
        $this->assertTrue(function_exists('proc_open'), 'proc_open is required for the lock contract.');
        $result = '';
        $this->store()->withInstanceLock(function () use (&$result): void {
            $result = $this->runLockContenderProcess();
        });

        $this->assertSame('INSTANCE_CONFIG_BUSY', $result);
    }

    public function testPublicFilesystemExceptionsHaveExactRedactedMessagesAndNoPreviousException(): void
    {
        $operations = $this->statOperations();
        $operations['fault'] = static function (string $checkpoint): void {
            if ($checkpoint === 'after_write') {
                throw new \RuntimeException('token=abc activation_secret=secret raw-json');
            }
        };

        try {
            $this->store($operations)->writeJson('instance', (object) ['token' => 'abc']);
            self::fail('Expected a public filesystem failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SHARED_FILE_UNAVAILABLE', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('token', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    /**
     * @param array<string, callable> $operations
     */
    private function store(array $operations = [], int $lockTimeoutMilliseconds = 50): UpgradeSharedFileStore
    {
        return new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            $lockTimeoutMilliseconds,
            array_replace($this->statOperations(), $operations),
        );
    }

    /**
     * @return array<string, callable>
     */
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
        if ($directory || str_ends_with($path, '/staging/storage-namespace.json')) {
            return self::AGENT_UID;
        }

        return self::PHP_UID;
    }

    private function writeRawInstance(string $raw): void
    {
        file_put_contents($this->root . '/config/instance.json', $raw);
        chmod($this->root . '/config/instance.json', 0660);
    }

    private function assertPublicFailure(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected public failure ' . $message);
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function runLockContenderProcess(): string
    {
        $script = <<<'PHP'
require $argv[1];
$root = $argv[2];
$agentUid = (int) $argv[3];
$gid = (int) $argv[4];
$phpUid = (int) $argv[5];
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
$store = new \app\service\upgrade\UpgradeSharedFileStore($root, $agentUid, $gid, $phpUid, 65536, 40, $operations);
try {
    $store->withInstanceLock(static fn() => null);
    echo 'acquired';
} catch (Throwable $exception) {
    echo $exception->getMessage();
}
PHP;
        $command = [
            PHP_BINARY,
            '-d',
            'opcache.jit=0',
            '-d',
            'opcache.jit_buffer_size=0',
            '-r',
            $script,
            '--',
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            $this->root,
            (string) self::AGENT_UID,
            (string) self::SHARED_GID,
            (string) self::PHP_UID,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($process);
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
