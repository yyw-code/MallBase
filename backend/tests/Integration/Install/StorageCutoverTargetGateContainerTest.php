<?php

declare(strict_types=1);

namespace Tests\Integration\Install;

use PHPUnit\Framework\TestCase;

final class StorageCutoverTargetGateContainerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 4);
    }

    public function testCandidateImageReadsTheRealRedisAndCheckpointGate(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon and the MallBase backend test image are required.');
        }

        $suffix = bin2hex(random_bytes(6));
        $fixture = sys_get_temp_dir() . '/mallbase-target-gate-' . $suffix;
        $network = 'mallbase-target-gate-' . $suffix;
        $redisContainer = 'mallbase-target-redis-' . $suffix;
        $sourceContainer = 'mallbase-target-source-' . $suffix;
        $upgradeVolume = 'mallbase-target-upgrade-' . $suffix;
        $installVolume = 'mallbase-target-install-' . $suffix;
        $candidateImage = 'mallbase-target-gate-test:' . $suffix;
        $namespace = 'mbs_gate_' . $suffix;
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d1';
        $deploymentId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d2';
        mkdir($fixture, 0700, true);

        try {
            $this->mustRun(['docker', 'network', 'create', $network]);
            $this->mustRun(['docker', 'volume', 'create', $upgradeVolume]);
            $this->mustRun(['docker', 'volume', 'create', $installVolume]);
            $this->mustRun([
                'docker', 'run', '--rm',
                '--mount', 'type=volume,src=' . $installVolume . ',dst=/install',
                'alpine:3.20', 'sh', '-c',
                'printf installed > /install/install.lock'
                    . ' && chown 10000:23456 /install/install.lock'
                    . ' && chmod 0660 /install/install.lock',
            ]);
            $this->mustRun([
                'docker', 'run', '-d', '--name', $redisContainer,
                '--network', $network, 'redis:7.2-alpine',
                'redis-server', '--save', '', '--appendonly', 'no',
            ]);
            $this->waitForRedis($redisContainer);
            [, $info] = $this->runProcess([
                'docker', 'exec', $redisContainer, 'redis-cli', '--raw', 'INFO', 'server',
            ]);
            self::assertMatchesRegularExpression('/^run_id:[0-9a-f]{40}\r?$/m', $info);
            preg_match('/^run_id:([0-9a-f]{40})\r?$/m', $info, $match);
            $redisIncarnation = $match[1];

            $this->mustRun(['docker', 'create', '--name', $sourceContainer, 'mallbase-backend:latest']);
            foreach (['app', 'config', 'route'] as $directory) {
                $this->mustRun([
                    'docker', 'cp',
                    $this->projectRoot . '/backend/' . $directory . '/.',
                    $sourceContainer . ':/app/' . $directory,
                ]);
            }
            $this->mustRun(['docker', 'commit', $sourceContainer, $candidateImage]);

            $awaiting = $this->gateDocument(
                'awaiting_deployment',
                19,
                $jobId,
                $deploymentId,
                $redisIncarnation,
            );
            $this->installGate($fixture, $upgradeVolume, $awaiting);
            $this->setRedisGate($redisContainer, $namespace, $awaiting);

            [$code, $output] = $this->runCandidate(
                $candidateImage,
                $network,
                $redisContainer,
                $upgradeVolume,
                $installVolume,
                $namespace,
                $jobId,
            );
            self::assertSame(0, $code, $output);
            preg_match_all('/^\{.*\}\r?$/m', $output, $jsonLines);
            self::assertNotEmpty($jsonLines[0], $output);
            $snapshot = json_decode((string) end($jsonLines[0]), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('storage_cutover_php_target_snapshot', $snapshot['purpose'] ?? null);
            self::assertSame('awaiting_deployment', $snapshot['gate_state'] ?? null);
            self::assertSame(19, $snapshot['gate_revision'] ?? null);
            self::assertSame($deploymentId, $snapshot['required_runtime']['deployment_id'] ?? null);
            self::assertTrue($snapshot['maintenance_fenced'] ?? false);

            $backingUp = $this->gateDocument(
                'backing_up',
                20,
                $jobId,
                $deploymentId,
                $redisIncarnation,
            );
            $this->installGate($fixture, $upgradeVolume, $backingUp);
            $this->setRedisGate($redisContainer, $namespace, $backingUp);
            [$code, $output] = $this->runCandidate(
                $candidateImage,
                $network,
                $redisContainer,
                $upgradeVolume,
                $installVolume,
                $namespace,
                $jobId,
            );
            self::assertSame(1, $code, $output);
            self::assertStringContainsString('STORAGE_CUTOVER_TARGET_SNAPSHOT_FAILED', $output);
        } finally {
            $this->runProcess(['docker', 'rm', '-f', $sourceContainer, $redisContainer]);
            $this->runProcess(['docker', 'image', 'rm', '-f', $candidateImage]);
            $this->runProcess(['docker', 'volume', 'rm', '-f', $upgradeVolume, $installVolume]);
            $this->runProcess(['docker', 'network', 'rm', $network]);
            $this->removeTree($fixture);
        }
    }

    /** @return array<string,mixed> */
    private function gateDocument(
        string $state,
        int $revision,
        string $jobId,
        string $deploymentId,
        string $redisIncarnation,
    ): array {
        return [
            'schema_version' => 2,
            'state' => $state,
            'revision' => $revision,
            'job_id' => $jobId,
            'required_runtime_version' => '1.3.0',
            'required_deployment_id' => $deploymentId,
            'required_storage_layout_version' => 2,
            'required_storage_layout_generation' => 2,
            'deployment_epoch' => 3,
            'activity_generation' => 4,
            'redis_incarnation' => $redisIncarnation,
            'uncertain' => false,
            'tainted_boots' => [],
            'platform_sync_pending' => false,
            'failure_code' => null,
            'updated_at' => 1_783_785_600,
            'uncertain_revision' => null,
            'replacement_barrier_revision' => null,
            'tainted_boots_overflow' => false,
        ];
    }

    /** @param array<string,mixed> $gate */
    private function installGate(string $fixture, string $upgradeVolume, array $gate): void
    {
        $gatePath = $fixture . '/upgrade-gate.json';
        if (is_file($gatePath)) {
            chmod($gatePath, 0600);
        }
        file_put_contents($gatePath, json_encode($gate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($gatePath, 0444);
        $this->mustRun([
            'docker', 'run', '--rm',
            '--mount', 'type=volume,src=' . $upgradeVolume . ',dst=/upgrade',
            '--mount', 'type=bind,src=' . $gatePath . ',dst=/input/upgrade-gate.json,readonly',
            'alpine:3.20', 'sh', '-c',
            'chown 12345:23456 /upgrade'
                . ' && chmod 0750 /upgrade'
                . ' && mkdir -p /upgrade/state'
                . ' && chown 12345:23456 /upgrade/state'
                . ' && chmod 2770 /upgrade/state'
                . ' && rm -f /upgrade/state/upgrade-gate.json /upgrade/state/upgrade-gate.lock'
                . ' && cp /input/upgrade-gate.json /upgrade/state/upgrade-gate.json'
                . ' && chown 10000:23456 /upgrade/state/upgrade-gate.json'
                . ' && chmod 0660 /upgrade/state/upgrade-gate.json',
        ]);
    }

    /** @param array<string,mixed> $gate */
    private function setRedisGate(string $container, string $namespace, array $gate): void
    {
        $this->mustRun([
            'docker', 'exec', $container, 'redis-cli', '--raw', 'SET',
            'mallbase:' . $namespace . ':upgrade:gate',
            json_encode($gate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array{int,string} */
    private function runCandidate(
        string $image,
        string $network,
        string $redisContainer,
        string $upgradeVolume,
        string $installVolume,
        string $namespace,
        string $jobId,
    ): array {
        return $this->runProcess([
            'docker', 'run', '--rm', '--network', $network,
            '--read-only', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges:true',
            '--user', '10000:23456',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=16m,mode=0700',
            '--mount', 'type=volume,src=' . $upgradeVolume . ',dst=/upgrade',
            '--mount', 'type=volume,src=' . $installVolume . ',dst=/app/runtime/install,readonly',
            '-e', 'MALLBASE_RUNTIME_ROLE=target-verify',
            '-e', 'MALLBASE_UPGRADE_RUNTIME_ENABLE=true',
            '-e', 'MALLBASE_UPGRADE_ROOT=/upgrade',
            '-e', 'MALLBASE_UPGRADE_NAMESPACE_ID=' . $namespace,
            '-e', 'MALLBASE_UPGRADE_SHARED_GID=23456',
            '-e', 'MALLBASE_AGENT_UID=12345',
            '-e', 'REDIS_HOST=' . $redisContainer,
            '-e', 'REDIS_PORT=6379',
            '-e', 'REDIS_CACHE_DB=0',
            '-e', 'CACHE_DRIVER=redis',
            '--entrypoint', 'php', $image,
            '-d', 'opcache.enable_cli=0', '-d', 'opcache.jit_buffer_size=0',
            'think', 'upgrade:storage-cutover-target-snapshot', '--job-id=' . $jobId,
        ]);
    }

    private function waitForRedis(string $container): void
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            [$code, $output] = $this->runProcess(['docker', 'exec', $container, 'redis-cli', 'ping']);
            if ($code === 0 && trim($output) === 'PONG') {
                return;
            }
            usleep(250_000);
        }
        self::fail('Redis did not become ready for the target gate integration test.');
    }

    private function dockerAvailable(): bool
    {
        [$code] = $this->runProcess(['docker', 'info']);
        if ($code !== 0) {
            return false;
        }
        [$code] = $this->runProcess(['docker', 'image', 'inspect', 'mallbase-backend:latest']);

        return $code === 0;
    }

    /** @param array<int,string> $command */
    private function mustRun(array $command): string
    {
        [$code, $output] = $this->runProcess($command);
        self::assertSame(0, $code, $output);

        return $output;
    }

    /** @param array<int,string> $command @return array{int,string} */
    private function runProcess(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
            $path = $root . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($root);
    }
}
