<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use RuntimeException;
use Throwable;

/**
 * 通过独立 PHP CLI 进程执行安装期 ThinkPHP 命令。
 *
 * 子进程继承当前安装进程已经应用的环境变量，但始终使用数组 argv，禁止 shell 解析。
 * 自定义 executor 和 PHP 路径仅用于测试。
 */
final class InstallCommandRunner
{
    private const MAX_TIMEOUT_MS = 300_000;
    private const MAX_STDOUT_BYTES = 256 * 1024;
    private const MAX_STDERR_BYTES = 64 * 1024;

    /** @var Closure(array<int,string>,string,int):array<string,mixed>|null */
    private readonly ?Closure $executor;

    public function __construct(?Closure $executor = null, private readonly ?string $phpBinary = null)
    {
        $this->executor = $executor;
    }

    /**
     * @param array<int, string> $arguments
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,output_exceeded:bool}
     */
    public function runThinkCommand(string $backendRoot, array $arguments, int $timeoutMilliseconds): array
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > self::MAX_TIMEOUT_MS || $arguments === []) {
            throw new RuntimeException('安装命令配置无效');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new RuntimeException('安装命令参数无效');
            }
        }

        $resolvedRoot = realpath($backendRoot);
        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot)) {
            throw new RuntimeException('后端项目目录无效');
        }
        $thinkPath = $resolvedRoot . DIRECTORY_SEPARATOR . 'think';
        if (!is_file($thinkPath) || is_link($thinkPath)) {
            throw new RuntimeException('ThinkPHP 命令入口不存在或不安全');
        }

        $command = array_merge([$this->resolvePhpCliBinary(), $thinkPath], array_values($arguments));
        $rawResult = $this->executor !== null
            ? ($this->executor)($command, $resolvedRoot, $timeoutMilliseconds)
            : $this->executeNative($command, $resolvedRoot, $timeoutMilliseconds);
        $result = $this->normalizeResult($rawResult);
        $this->assertSucceeded($result, $timeoutMilliseconds);

        return $result;
    }

    private function resolvePhpCliBinary(): string
    {
        $candidates = [];
        if ($this->phpBinary !== null) {
            $candidates[] = $this->phpBinary;
        } else {
            $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR
                . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
            $binaryName = basename(PHP_BINARY);
            if (preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?(?:\.exe)?$/iD', $binaryName) === 1) {
                $candidates[] = PHP_BINARY;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || str_contains($candidate, "\0")) {
                continue;
            }
            $resolved = realpath($candidate);
            if (!is_string($resolved) || !is_file($resolved)) {
                continue;
            }
            if (DIRECTORY_SEPARATOR !== '\\' && !is_executable($resolved)) {
                continue;
            }

            return $resolved;
        }

        throw new RuntimeException('未找到可执行的 PHP CLI');
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,output_exceeded:bool}
     */
    private function executeNative(array $command, string $workingDirectory, int $timeoutMilliseconds): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('当前 PHP 禁用了 proc_open，无法执行权限同步');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process) || count($pipes) !== 3) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            throw new RuntimeException('权限同步子进程启动失败');
        }

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $outputExceeded = false;
        $observedExitCode = null;
        $closeExitCode = -1;
        $failure = null;
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);

        try {
            fclose($pipes[0]);
            unset($pipes[0]);
            if (!stream_set_blocking($pipes[1], false) || !stream_set_blocking($pipes[2], false)) {
                throw new RuntimeException('权限同步子进程管道初始化失败');
            }

            while (true) {
                $stdoutWithinLimit = $this->appendPipeOutput(
                    $pipes[1],
                    $stdout,
                    self::MAX_STDOUT_BYTES,
                );
                $stderrWithinLimit = $this->appendPipeOutput(
                    $pipes[2],
                    $stderr,
                    self::MAX_STDERR_BYTES,
                );
                if (!$stdoutWithinLimit || !$stderrWithinLimit) {
                    $outputExceeded = true;
                    $this->terminateProcess($process);
                    break;
                }

                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new RuntimeException('权限同步子进程状态读取失败');
                }
                if (($status['running'] ?? false) !== true) {
                    if (is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                        $observedExitCode = $status['exitcode'];
                    }
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
                    $this->terminateProcess($process);
                    break;
                }

                usleep(10_000);
            }

            if (!$outputExceeded) {
                $stdoutWithinLimit = $this->appendPipeOutput(
                    $pipes[1],
                    $stdout,
                    self::MAX_STDOUT_BYTES,
                );
                $stderrWithinLimit = $this->appendPipeOutput(
                    $pipes[2],
                    $stderr,
                    self::MAX_STDERR_BYTES,
                );
                $outputExceeded = !$stdoutWithinLimit || !$stderrWithinLimit;
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $status = @proc_get_status($process);
            if (is_array($status)) {
                if (($status['running'] ?? false) === true) {
                    $this->terminateProcess($process);
                } elseif (is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                    $observedExitCode ??= $status['exitcode'];
                }
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $closed = proc_close($process);
            if (is_int($closed)) {
                $closeExitCode = $closed;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
        $exitCode = $closeExitCode >= 0 ? $closeExitCode : ($observedExitCode ?? -1);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
            'output_exceeded' => $outputExceeded,
        ];
    }

    /** @param resource $pipe */
    private function appendPipeOutput($pipe, string &$buffer, int $maximumBytes): bool
    {
        $remaining = $maximumBytes - strlen($buffer);
        $chunk = stream_get_contents($pipe, max(1, $remaining + 1));
        if (!is_string($chunk)) {
            throw new RuntimeException('权限同步子进程输出读取失败');
        }
        if (strlen($chunk) > $remaining) {
            if ($remaining > 0) {
                $buffer .= substr($chunk, 0, $remaining);
            }

            return false;
        }
        $buffer .= $chunk;

        return true;
    }

    /** @param resource $process */
    private function terminateProcess($process): void
    {
        @proc_terminate($process, 15);
        $deadline = hrtime(true) + 200_000_000;
        do {
            $status = @proc_get_status($process);
            if (!is_array($status) || ($status['running'] ?? false) !== true) {
                return;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        @proc_terminate($process, 9);
    }

    /**
     * @param array<string, mixed> $result
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,output_exceeded:bool}
     */
    private function normalizeResult(array $result): array
    {
        if (!is_int($result['exit_code'] ?? null)
            || !is_string($result['stdout'] ?? null)
            || !is_string($result['stderr'] ?? null)
            || (isset($result['timed_out']) && !is_bool($result['timed_out']))
            || (isset($result['output_exceeded']) && !is_bool($result['output_exceeded']))) {
            throw new RuntimeException('权限同步子进程返回结果无效');
        }

        return [
            'exit_code' => $result['exit_code'],
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'timed_out' => $result['timed_out'] ?? false,
            'output_exceeded' => $result['output_exceeded'] ?? false,
        ];
    }

    /**
     * @param array{exit_code:int,stdout:string,stderr:string,timed_out:bool,output_exceeded:bool} $result
     */
    private function assertSucceeded(array $result, int $timeoutMilliseconds): void
    {
        if ($result['timed_out']) {
            throw new RuntimeException(sprintf(
                '权限同步子进程执行超时（%.1f 秒）',
                $timeoutMilliseconds / 1000,
            ));
        }
        if ($result['output_exceeded']) {
            throw new RuntimeException('权限同步子进程输出超过安全限制');
        }
        if ($result['exit_code'] !== 0) {
            $detail = $this->errorDetail($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new RuntimeException(sprintf(
                '权限同步子进程退出码 %d%s',
                $result['exit_code'],
                $detail !== '' ? '：' . $detail : '',
            ));
        }
    }

    private function errorDetail(string $output): string
    {
        $detail = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', trim($output));
        $detail = is_string($detail) ? $detail : trim($output);
        $collapsed = preg_replace('/\s+/u', ' ', strip_tags($detail));
        $detail = is_string($collapsed) ? trim($collapsed) : trim($detail);
        if (strlen($detail) > 1000) {
            $detail = substr($detail, 0, 1000) . '…';
        }

        return $detail;
    }
}
