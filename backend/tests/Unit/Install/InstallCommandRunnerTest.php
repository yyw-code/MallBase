<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\InstallCommandRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallCommandRunnerTest extends TestCase
{
    /** @var array<int, string> */
    private const PROCESS_FUNCTIONS = [
        'proc_open',
        'proc_get_status',
        'proc_terminate',
        'proc_close',
    ];

    private string $root;

    private string $thinkPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-install-command-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0755, true);
        $this->thinkPath = $this->root . '/think';
        file_put_contents($this->thinkPath, "<?php\nexit(0);\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testUsesFixedArrayArgvWithoutShellParsing(): void
    {
        $executor = function (array $command, string $workingDirectory, int $timeoutMilliseconds): array {
            self::assertSame([
                (string) realpath(PHP_BINARY),
                (string) realpath($this->thinkPath),
                'sync:permissions',
            ], $command);
            self::assertSame((string) realpath($this->root), $workingDirectory);
            self::assertSame(120_000, $timeoutMilliseconds);

            return [
                'exit_code' => 0,
                'stdout' => '同步完成',
                'stderr' => '',
            ];
        };

        $result = (new InstallCommandRunner($executor, PHP_BINARY))->runThinkCommand(
            $this->root,
            ['sync:permissions'],
            120_000,
        );

        self::assertSame(0, $result['exit_code']);
        self::assertFalse($result['timed_out']);
        self::assertFalse($result['output_exceeded']);
    }

    public function testInjectedPhpCliCanRunWhenWebProcessCannotInspectItsPath(): void
    {
        $phpCliOutsideOpenBasedir = '/opt/1panel/php/82/bin/php';
        $executor = static function (array $command) use ($phpCliOutsideOpenBasedir): array {
            self::assertSame($phpCliOutsideOpenBasedir, $command[0]);

            return [
                'exit_code' => 0,
                'stdout' => '',
                'stderr' => '',
            ];
        };

        $result = (new InstallCommandRunner($executor, $phpCliOutsideOpenBasedir))->runThinkCommand(
            $this->root,
            ['sync:permissions'],
            5_000,
        );

        self::assertSame(0, $result['exit_code']);
    }

    public function testRejectsNonZeroExitCodeAndSurfacesBoundedError(): void
    {
        $runner = new InstallCommandRunner(
            static fn(): array => [
                'exit_code' => 7,
                'stdout' => '',
                'stderr' => "\033[31mpermission sync failed\033[0m\nsecond line",
            ],
            PHP_BINARY,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('权限同步子进程退出码 7：permission sync failed second line');
        $runner->runThinkCommand($this->root, ['sync:permissions'], 120_000);
    }

    public function testRejectsTimedOutExecutorResultBeforeExitCode(): void
    {
        $runner = new InstallCommandRunner(
            static fn(): array => [
                'exit_code' => -1,
                'stdout' => '',
                'stderr' => '',
                'timed_out' => true,
            ],
            PHP_BINARY,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('权限同步子进程执行超时（0.1 秒）');
        $runner->runThinkCommand($this->root, ['sync:permissions'], 100);
    }

    /** @dataProvider missingProcessFunctionProvider */
    public function testNativeRunnerRejectsEveryMissingProcessFunctionBeforeStartup(string $missingFunction): void
    {
        $runner = new InstallCommandRunner(
            null,
            PHP_BINARY,
            static fn(string $function): bool => $function !== $missingFunction,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('当前 PHP 缺少子进程函数：' . $missingFunction . '，无法执行权限同步');
        $runner->runThinkCommand($this->root, ['sync:permissions'], 5_000);
    }

    /** @return iterable<string, array{string}> */
    public static function missingProcessFunctionProvider(): iterable
    {
        foreach (self::PROCESS_FUNCTIONS as $function) {
            yield $function => [$function];
        }
    }

    public function testNativeProcessInheritsEnvironmentAndReturnsRealExitCode(): void
    {
        if (!$this->nativeProcessFunctionsAvailable()) {
            self::markTestSkipped('native process functions unavailable');
        }

        $previous = getenv('MALLBASE_INSTALL_RUNNER_TEST');
        putenv('MALLBASE_INSTALL_RUNNER_TEST=inherited');
        file_put_contents(
            $this->thinkPath,
            "<?php\n"
            . "if (getenv('MALLBASE_INSTALL_RUNNER_TEST') !== 'inherited') { fwrite(STDERR, 'env missing'); exit(6); }\n"
            . "fwrite(STDERR, 'native failure');\n"
            . "exit(9);\n",
        );

        try {
            (new InstallCommandRunner(null, PHP_BINARY))->runThinkCommand(
                $this->root,
                ['sync:permissions'],
                5_000,
            );
            self::fail('non-zero native process was accepted');
        } catch (RuntimeException $exception) {
            self::assertSame('权限同步子进程退出码 9：native failure', $exception->getMessage());
        } finally {
            $previous === false
                ? putenv('MALLBASE_INSTALL_RUNNER_TEST')
                : putenv('MALLBASE_INSTALL_RUNNER_TEST=' . $previous);
        }
    }

    public function testNativeProcessIsTerminatedAtDeadline(): void
    {
        if (!$this->nativeProcessFunctionsAvailable()) {
            self::markTestSkipped('native process functions unavailable');
        }

        file_put_contents($this->thinkPath, "<?php\nusleep(2_000_000);\nexit(0);\n");
        $started = hrtime(true);

        try {
            (new InstallCommandRunner(null, PHP_BINARY))->runThinkCommand(
                $this->root,
                ['sync:permissions'],
                50,
            );
            self::fail('timed-out native process was accepted');
        } catch (RuntimeException $exception) {
            $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
            self::assertSame('权限同步子进程执行超时（0.1 秒）', $exception->getMessage());
            self::assertLessThan(1500, $elapsedMilliseconds);
        }
    }

    private function nativeProcessFunctionsAvailable(): bool
    {
        foreach (self::PROCESS_FUNCTIONS as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @chmod($path, 0770);
        @rmdir($path);
    }
}
