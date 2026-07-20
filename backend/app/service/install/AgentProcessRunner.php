<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use RuntimeException;

/**
 * 以固定 argv、空环境和有界管道执行一次 Agent 子命令。
 *
 * 自定义 executor 仅用于测试；生产路径始终先验证活动二进制，再通过
 * proc_open 的数组命令执行，禁止 shell 解析。
 */
final class AgentProcessRunner
{
    /** @var Closure(array<int,string>,string,int):array<string,mixed>|null */
    private readonly ?Closure $executor;

    public function __construct(
        ?Closure $executor = null,
        private readonly ?AgentBinaryTrustValidator $trustValidator = null,
    ) {
        $this->executor = $executor;
    }

    /** @return array<string, mixed> */
    public function run(
        string $binary,
        string $operation,
        string $stdin,
        int $timeoutMilliseconds,
        int $maximumStdoutBytes,
        int $maximumStderrBytes,
    ): array {
        if (!in_array($operation, ['heartbeat', 'catalog'], true)
            || $binary === '' || $timeoutMilliseconds < 1 || $timeoutMilliseconds > 60_000
            || $maximumStdoutBytes < 1 || $maximumStderrBytes < 1) {
            throw new RuntimeException('agent process configuration invalid');
        }

        $command = [$binary, $operation];
        if ($this->executor !== null) {
            $executor = $this->executor;

            return $executor($command, $stdin, $timeoutMilliseconds);
        }

        ($this->trustValidator ?? AgentBinaryTrustValidator::fromConfig())->validate($binary);

        return $this->executeNative(
            $command,
            $stdin,
            $timeoutMilliseconds,
            $maximumStdoutBytes,
            $maximumStderrBytes,
        );
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
     */
    private function executeNative(
        array $command,
        string $stdin,
        int $timeoutMilliseconds,
        int $maximumStdoutBytes,
        int $maximumStderrBytes,
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, [], ['bypass_shell' => true]);
        if (!is_resource($process) || count($pipes) !== 3) {
            throw new RuntimeException('process unavailable');
        }

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $observedExitCode = null;
        $stdinOffset = 0;
        $stdinOpen = true;
        try {
            foreach ($pipes as $pipe) {
                if (!stream_set_blocking($pipe, false)) {
                    throw new RuntimeException('nonblocking pipe unavailable');
                }
            }

            while (true) {
                if ($stdinOpen && $stdinOffset < strlen($stdin)) {
                    $count = @fwrite($pipes[0], substr($stdin, $stdinOffset, 8192));
                    if ($count === false) {
                        throw new RuntimeException('stdin write failed');
                    }
                    $stdinOffset += $count;
                }
                if ($stdinOpen && $stdinOffset === strlen($stdin)) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                    $stdinOpen = false;
                }

                $stdout .= $this->readBounded($pipes[1], $maximumStdoutBytes - strlen($stdout));
                $stderr .= $this->readBounded($pipes[2], $maximumStderrBytes - strlen($stderr));
                if (strlen($stdout) > $maximumStdoutBytes || strlen($stderr) > $maximumStderrBytes) {
                    proc_terminate($process, 9);
                    break;
                }

                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new RuntimeException('process status failed');
                }
                if (($status['running'] ?? false) !== true) {
                    if (is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                        $observedExitCode = $status['exitcode'];
                    }
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process, 15);
                    usleep(20_000);
                    $status = proc_get_status($process);
                    if (is_array($status) && ($status['running'] ?? false) === true) {
                        proc_terminate($process, 9);
                    }
                    break;
                }
                usleep(1_000);
            }
            $stdout .= $this->readBounded($pipes[1], $maximumStdoutBytes - strlen($stdout));
            $stderr .= $this->readBounded($pipes[2], $maximumStderrBytes - strlen($stderr));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        $exitCode = proc_close($process);
        if ((!is_int($exitCode) || $exitCode < 0) && is_int($observedExitCode)) {
            $exitCode = $observedExitCode;
        }

        return [
            'exit_code' => is_int($exitCode) ? $exitCode : -1,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
        ];
    }

    /** @param resource $pipe */
    private function readBounded($pipe, int $remainingBytes): string
    {
        $chunk = stream_get_contents($pipe, max(1, $remainingBytes + 1));
        if (!is_string($chunk)) {
            throw new RuntimeException('process output read failed');
        }

        return $chunk;
    }
}
