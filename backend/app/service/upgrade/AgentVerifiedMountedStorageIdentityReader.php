<?php

declare(strict_types=1);

namespace app\service\upgrade;

use app\service\install\AgentBinaryTrustValidator;
use Closure;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * 通过固定 Agent 命令读取已验签的挂载存储身份。
 *
 * Agent 命令负责读取固定 storage-ready.pub/storage-ready.json、验证 Ed25519 签名，
 * 并将投影与实际挂载卷 marker 逐项核对；本类只接受其严格输出并与镜像 marker 交叉比对。
 */
final class AgentVerifiedMountedStorageIdentityReader implements UpgradeMountedStorageIdentityReader
{
    private const MAX_STDOUT_BYTES = 1024 * 1024;
    private const MAX_STDERR_BYTES = 64 * 1024;
    private const MAX_TIMESTAMP = 4_102_444_800;

    /** @var Closure(array<int,string>,string,int):array<string,mixed>|null */
    private readonly ?Closure $testExecutor;

    public function __construct(
        ?Closure $testExecutor = null,
        private readonly ?string $testBinaryPath = null,
        private readonly int $timeoutMilliseconds = 5000,
        private readonly ?AgentBinaryTrustValidator $trustValidator = null,
    ) {
        if ($testExecutor === null && $this->testBinaryPath !== null) {
            $this->fail();
        }
        $this->testExecutor = $testExecutor;
    }

    public function read(
        string $appVersion,
        string $deploymentId,
        string $releaseInventorySha256,
        int $storageLayoutVersion,
        int $storageLayoutGeneration,
    ): UpgradeMountedStorageIdentity {
        if ($this->timeoutMilliseconds < 1 || $this->timeoutMilliseconds > 60_000) {
            $this->fail();
        }
        $binary = $this->testBinaryPath ?? $this->defaultBinaryPath();
        if ($binary === null) {
            $this->fail();
        }

        try {
            $command = [$binary, 'storage', 'verify-ready-projection'];
            if ($this->testExecutor !== null) {
                $process = ($this->testExecutor)($command, '{}', $this->timeoutMilliseconds);
            } else {
                ($this->trustValidator ?? AgentBinaryTrustValidator::fromConfig())->validate($binary);
                $process = $this->executeNative($command, '{}', $this->timeoutMilliseconds);
            }
            $identity = $this->decodeVerifiedProjection($process);
            if (!$identity->matchesImage(
                $appVersion,
                $deploymentId,
                $releaseInventorySha256,
                $storageLayoutVersion,
                $storageLayoutGeneration,
            )) {
                $this->fail();
            }

            return $identity;
        } catch (Throwable) {
            $this->fail();
        }
    }

    /** @param array<string, mixed> $process */
    private function decodeVerifiedProjection(array $process): UpgradeMountedStorageIdentity
    {
        $exitCode = $process['exit_code'] ?? null;
        $stdout = $process['stdout'] ?? null;
        $stderr = $process['stderr'] ?? null;
        $timedOut = $process['timed_out'] ?? null;
        if (!is_int($exitCode) || !is_string($stdout) || !is_string($stderr) || !is_bool($timedOut)
            || $timedOut || $exitCode !== 0
            || strlen($stdout) > self::MAX_STDOUT_BYTES || strlen($stderr) > self::MAX_STDERR_BYTES) {
            $this->fail();
        }

        $raw = trim($stdout);
        if ($raw === '' || $raw[0] !== '{' || preg_match('//u', $raw) !== 1) {
            $this->fail();
        }
        try {
            $values = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $canonical = json_encode(
                $values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $this->fail();
        }
        if (!is_array($values) || array_is_list($values) || !is_string($canonical) || !hash_equals($canonical, $raw)) {
            $this->fail();
        }

        $expectedFields = [
            'app_version', 'deployment_id', 'finalize_receipt_sha256', 'installation_storage_namespace',
            'issued_at', 'issued_authority_revision', 'key_id', 'layout_generation', 'purpose',
            'release_inventory_sha256', 'schema_version', 'signature', 'storage_layout_version', 'volume_markers',
        ];
        $actualFields = array_keys($values);
        sort($actualFields, SORT_STRING);
        sort($expectedFields, SORT_STRING);
        if ($actualFields !== $expectedFields
            || ($values['schema_version'] ?? null) !== 1
            || ($values['purpose'] ?? null) !== 'business_boot'
            || !is_string($values['key_id']) || !$this->validHash($values['key_id'], true)
            || !is_string($values['installation_storage_namespace'])
            || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $values['installation_storage_namespace']) !== 1
            || !is_string($values['app_version'])
            || !is_string($values['deployment_id'])
            || !is_string($values['release_inventory_sha256'])
            || !is_int($values['storage_layout_version'])
            || !is_int($values['layout_generation'])
            || !is_int($values['issued_authority_revision']) || $values['issued_authority_revision'] < 1
            || !is_string($values['finalize_receipt_sha256'])
            || !is_int($values['issued_at']) || $values['issued_at'] < 1 || $values['issued_at'] > self::MAX_TIMESTAMP
            || !is_string($values['signature']) || !$this->validSignature($values['signature'])
            || !is_array($values['volume_markers']) || array_is_list($values['volume_markers'])) {
            $this->fail();
        }

        $markers = [];
        foreach ($values['volume_markers'] as $artifact => $marker) {
            if (!is_string($artifact) || !is_array($marker) || array_is_list($marker)) {
                $this->fail();
            }
            $fields = array_keys($marker);
            sort($fields, SORT_STRING);
            if ($fields !== ['marker_id', 'marker_sha256']
                || !is_string($marker['marker_id']) || !is_string($marker['marker_sha256'])) {
                $this->fail();
            }
            $markers[$artifact] = [
                'marker_id' => $marker['marker_id'],
                'marker_sha256' => $marker['marker_sha256'],
            ];
        }
        ksort($markers, SORT_STRING);

        return new UpgradeMountedStorageIdentity(
            $values['purpose'],
            $values['installation_storage_namespace'],
            $values['app_version'],
            $values['deployment_id'],
            $values['release_inventory_sha256'],
            $values['storage_layout_version'],
            $values['layout_generation'],
            $values['finalize_receipt_sha256'],
            $markers,
        );
    }

    private function validHash(string $value, bool $requirePrefix = false): bool
    {
        $pattern = $requirePrefix ? '/^sha256:[0-9a-f]{64}$/D' : '/^(?:sha256:)?[0-9a-f]{64}$/D';

        return preg_match($pattern, $value) === 1;
    }

    private function validSignature(string $value): bool
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) && strlen($decoded) === 64 && hash_equals(base64_encode($decoded), $value);
    }

    private function defaultBinaryPath(): ?string
    {
        $suffix = match (strtolower((string) php_uname('m'))) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };

        if ($suffix === null) {
            return null;
        }
        $root = (string) config('agent.upgrade_root', '/upgrade');
        if ($root === '' || !str_starts_with($root, DIRECTORY_SEPARATOR)
            || str_contains($root, "\0") || str_contains($root, '/../') || str_contains($root, '/./')) {
            return null;
        }

        return rtrim($root, DIRECTORY_SEPARATOR) . '/bin/mallbase-agent-linux-' . $suffix;
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
     */
    private function executeNative(array $command, string $stdin, int $timeoutMilliseconds): array
    {
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

                $stdoutChunk = stream_get_contents($pipes[1]);
                $stderrChunk = stream_get_contents($pipes[2]);
                if (!is_string($stdoutChunk) || !is_string($stderrChunk)) {
                    throw new RuntimeException('process output read failed');
                }
                $stdout .= $stdoutChunk;
                $stderr .= $stderrChunk;
                if (strlen($stdout) > self::MAX_STDOUT_BYTES || strlen($stderr) > self::MAX_STDERR_BYTES) {
                    proc_terminate($process, 9);
                    break;
                }

                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new RuntimeException('process status unavailable');
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
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
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

    private function fail(): never
    {
        throw new RuntimeException('UPGRADE_MOUNTED_STORAGE_IDENTITY_UNAVAILABLE');
    }
}
