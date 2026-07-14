<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\BackendEnvFileStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackendEnvFileStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-backend-env-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testWritesMergedEnvironmentWithAtomicMode(): void
    {
        $template = $this->root . '/template.env';
        $target = $this->root . '/backend.env';
        file_put_contents($template, "DB_HOST=127.0.0.1\nJWT_SECRET=old\n");

        (new BackendEnvFileStore())->write($target, $template, [
            'DB_HOST' => 'mysql',
            'JWT_SECRET' => 'secret with spaces',
            'SITE_URL' => 'https://shop.example.com',
        ]);

        self::assertSame(
            "DB_HOST=\"mysql\"\nJWT_SECRET=\"secret with spaces\"\nSITE_URL=\"https://shop.example.com\"\n",
            file_get_contents($target),
        );
        self::assertSame(0600, fileperms($target) & 0777);
        self::assertFileExists($this->root . '/.backend-env.lock');
        self::assertSame([], glob($this->root . '/.backend.env.*.tmp') ?: []);
    }

    public function testEmptyExistingTargetFallsBackToTemplate(): void
    {
        $template = $this->root . '/template.env';
        $target = $this->root . '/backend.env';
        file_put_contents($template, "DB_HOST=127.0.0.1\n");
        touch($target);

        (new BackendEnvFileStore())->write($target, $template, ['DB_HOST' => 'mysql']);

        self::assertSame("DB_HOST=\"mysql\"\n", file_get_contents($target));
    }

    public function testRejectsSymlinkTargetWithoutChangingCanary(): void
    {
        $template = $this->root . '/template.env';
        $canary = $this->root . '/canary';
        $target = $this->root . '/backend.env';
        file_put_contents($template, "DB_HOST=127.0.0.1\n");
        file_put_contents($canary, 'unchanged');
        symlink($canary, $target);

        try {
            (new BackendEnvFileStore())->write($target, $template, ['DB_HOST' => 'mysql']);
            self::fail('Symlink target must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('BACKEND_ENV_FILE_INVALID', $exception->getMessage());
        }

        self::assertSame('unchanged', file_get_contents($canary));
    }

    public function testRejectsHardlinkTargetWithoutChangingCanary(): void
    {
        $template = $this->root . '/template.env';
        $canary = $this->root . '/canary';
        $target = $this->root . '/backend.env';
        file_put_contents($template, "DB_HOST=127.0.0.1\n");
        file_put_contents($canary, 'unchanged');
        link($canary, $target);

        try {
            (new BackendEnvFileStore())->write($target, $template, ['DB_HOST' => 'mysql']);
            self::fail('Hardlink target must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('BACKEND_ENV_FILE_INVALID', $exception->getMessage());
        }

        self::assertSame('unchanged', file_get_contents($canary));
    }

    public function testRejectsSymlinkLockWithoutChangingCanary(): void
    {
        $template = $this->root . '/template.env';
        $canary = $this->root . '/canary';
        file_put_contents($template, "DB_HOST=127.0.0.1\n");
        file_put_contents($canary, 'unchanged');
        symlink($canary, $this->root . '/.backend-env.lock');

        try {
            (new BackendEnvFileStore())->write(
                $this->root . '/backend.env',
                $template,
                ['DB_HOST' => 'mysql'],
            );
            self::fail('Symlink lock must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('BACKEND_ENV_LOCK_INVALID', $exception->getMessage());
        }

        self::assertSame('unchanged', file_get_contents($canary));
        self::assertFileDoesNotExist($this->root . '/backend.env');
    }

    public function testRejectsDuplicateKey(): void
    {
        $template = $this->root . '/template.env';
        file_put_contents($template, "DB_HOST=one\nDB_HOST=two\n");
        $store = new BackendEnvFileStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKEND_ENV_TEMPLATE_INVALID');
        $store->write($this->root . '/backend.env', $template, ['DB_HOST' => 'mysql']);
    }

    public function testRejectsNewlineValue(): void
    {
        $template = $this->root . '/template.env';
        file_put_contents($template, "DB_HOST=one\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKEND_ENV_VALUE_INVALID');
        (new BackendEnvFileStore())->write(
            $this->root . '/backend.env',
            $template,
            ['DB_HOST' => "mysql\nother"],
        );
    }

    public function testShellAndThinkPhpRoundTripKeepsMetacharactersLiteral(): void
    {
        $template = $this->root . '/template.env';
        $target = $this->root . '/backend.env';
        $canary = $this->root . '/command-substitution-must-not-run';
        file_put_contents($template, "JWT_SECRET=old\n");
        $value = 'space $(touch ' . $canary . ') `touch ' . $canary
            . '` $HOME $1 ${2} single\'quote back\\slash double"quote ; # = ,';

        (new BackendEnvFileStore())->write($target, $template, ['JWT_SECRET' => $value]);

        $env = new \think\Env();
        $env->load($target);
        self::assertSame($value, $env->get('JWT_SECRET'));
        self::assertFileDoesNotExist($canary);

        $export = $this->root . '/export.sh';
        [$exportCode, $exportOutput] = $this->runProcess([
            PHP_BINARY,
            dirname(__DIR__, 4) . '/deploy/docker/export-backend-env.php',
            $target,
        ]);
        self::assertSame(0, $exportCode, $exportOutput);
        file_put_contents($export, $exportOutput . "printf '%s' \"\$JWT_SECRET\"\n");
        [$shellCode, $shellOutput] = $this->runProcess(['/bin/sh', $export]);
        self::assertSame(0, $shellCode, $shellOutput);
        self::assertSame($value, $shellOutput);
        self::assertFileDoesNotExist($canary);
    }

    /** @param array<int,string> $command @return array{int,string} */
    private function runProcess(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $this->root, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
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
        @chmod($path, 0770);
        @rmdir($path);
    }
}
