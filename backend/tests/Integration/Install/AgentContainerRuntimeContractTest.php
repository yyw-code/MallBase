<?php

declare(strict_types=1);

namespace Tests\Integration\Install;

use PHPUnit\Framework\TestCase;

final class AgentContainerRuntimeContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 4);
    }

    public function testGranularRuntimeUsesAnonymousCopyUpAndRunsFixedInitBeforeReadyGate(): void
    {
        $overlay = $this->read('docker-compose.storage-cutover.yml');
        $entrypoint = $this->read('deploy/docker/docker-entrypoint.sh');
        $dockerfile = $this->read('deploy/docker/Dockerfile');

        self::assertMatchesRegularExpression(
            '/target:\s*\/app\/runtime\R(?!\s+source:)(?!\s+volume:\R\s+nocopy:\s*true)/',
            $overlay,
            'The per-replica runtime must remain anonymous and allow image ownership copy-up.',
        );
        self::assertStringContainsString('COPY deploy/docker/runtime-init.sh /usr/local/bin/', $dockerfile);
        self::assertStringContainsString('/usr/local/bin/runtime-init.sh', $entrypoint);

        $initPosition = strpos($entrypoint, '/usr/local/bin/runtime-init.sh');
        $readyPosition = strrpos($entrypoint, '    verify_storage_ready');
        self::assertIsInt($initPosition);
        self::assertIsInt($readyPosition);
        self::assertLessThan($readyPosition, $initPosition, 'Runtime init must finish before the ready gate is checked.');
    }

    public function testUid10000ReplicasUseIndependentAnonymousRuntimeAndSharedPersistentChildren(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the container runtime contract.');
        }

        $runtimeInit = $this->projectRoot . '/deploy/docker/runtime-init.sh';
        self::assertFileExists($runtimeInit);
        $suffix = bin2hex(random_bytes(6));
        $fixture = sys_get_temp_dir() . '/mallbase-runtime-contract-' . $suffix;
        $image = 'mallbase-runtime-contract:' . $suffix;
        $sharedGid = 23456;
        $containers = ['mallbase-runtime-a-' . $suffix, 'mallbase-runtime-b-' . $suffix];
        $volumes = [
            'mallbase-install-' . $suffix,
            'mallbase-storage-' . $suffix,
            'mallbase-backup-' . $suffix,
        ];
        mkdir($fixture, 0700, true);
        copy($runtimeInit, $fixture . '/runtime-init.sh');
        file_put_contents($fixture . '/Dockerfile', <<<'DOCKERFILE'
FROM alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc
RUN addgroup -g 10000 -S mallbase \
    && adduser -u 10000 -S -D -H -G mallbase mallbase \
    && mkdir -p /app/runtime \
    && chown 10000:10000 /app/runtime \
    && chmod 0770 /app/runtime
COPY runtime-init.sh /usr/local/bin/runtime-init.sh
RUN chmod 0555 /usr/local/bin/runtime-init.sh
USER 10000:10000
DOCKERFILE);

        try {
            [$buildCode, $buildOutput] = $this->runProcess(['docker', 'build', '--tag', $image, $fixture]);
            self::assertSame(0, $buildCode, $buildOutput);
            foreach ($volumes as $volume) {
                [$code, $output] = $this->runProcess(['docker', 'volume', 'create', $volume]);
                self::assertSame(0, $code, $output);
                [$code, $output] = $this->runProcess([
                    'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL', '--cap-add', 'CHOWN',
                    '--mount', 'type=volume,src=' . $volume . ',dst=/target',
                    'alpine:3.20', 'sh', '-c',
                    'chown 0:' . $sharedGid . ' /target && chmod 3770 /target && printf marker > /target/.mallbase-layout-marker.json && chmod 0444 /target/.mallbase-layout-marker.json',
                ]);
                self::assertSame(0, $code, $output);
            }

            foreach ($containers as $container) {
                [$code, $output] = $this->runProcess($this->runtimeContainerCreateCommand(
                    $container,
                    $image,
                    $sharedGid,
                    $volumes,
                ));
                self::assertSame(0, $code, $output);
                [$code, $output] = $this->runProcess(['docker', 'start', $container]);
                self::assertSame(0, $code, $output);
                $this->waitForContainerPath($container, '/app/runtime/log');
            }

            [$code, $runtimeAName] = $this->runProcess([
                'docker', 'inspect', '--format',
                '{{range .Mounts}}{{if eq .Destination "/app/runtime"}}{{.Name}}{{end}}{{end}}',
                $containers[0],
            ]);
            self::assertSame(0, $code, $runtimeAName);
            [$code, $runtimeBName] = $this->runProcess([
                'docker', 'inspect', '--format',
                '{{range .Mounts}}{{if eq .Destination "/app/runtime"}}{{.Name}}{{end}}{{end}}',
                $containers[1],
            ]);
            self::assertSame(0, $code, $runtimeBName);
            self::assertNotSame(trim($runtimeAName), trim($runtimeBName));

            [$code, $output] = $this->runProcess([
                'docker', 'exec', '--user', '10000:' . $sharedGid, $containers[0], 'sh', '-c',
                'printf ephemeral-a > /app/runtime/log/replica-a && printf persistent > /app/runtime/storage/shared',
            ]);
            self::assertSame(0, $code, $output);
            [$code, $output] = $this->runProcess([
                'docker', 'exec', '--user', '10000:' . $sharedGid, $containers[1], 'sh', '-c',
                'test ! -e /app/runtime/log/replica-a && test "$(cat /app/runtime/storage/shared)" = persistent',
            ]);
            self::assertSame(0, $code, $output);

            [$code, $output] = $this->runProcess(['docker', 'rm', '--force', '--volumes', $containers[0]]);
            self::assertSame(0, $code, $output);
            $containers[0] .= '-recreated';
            [$code, $output] = $this->runProcess($this->runtimeContainerCreateCommand(
                $containers[0],
                $image,
                $sharedGid,
                $volumes,
            ));
            self::assertSame(0, $code, $output);
            [$code, $output] = $this->runProcess(['docker', 'start', $containers[0]]);
            self::assertSame(0, $code, $output);
            $this->waitForContainerPath($containers[0], '/app/runtime/log');
            [$code, $output] = $this->runProcess([
                'docker', 'exec', '--user', '10000:' . $sharedGid, $containers[0], 'sh', '-c',
                'test ! -e /app/runtime/log/replica-a && test "$(cat /app/runtime/storage/shared)" = persistent',
            ]);
            self::assertSame(0, $code, $output);
        } finally {
            foreach ($containers as $container) {
                $this->runProcess(['docker', 'rm', '--force', '--volumes', $container]);
            }
            foreach ($volumes as $volume) {
                $this->runProcess(['docker', 'volume', 'rm', '--force', $volume]);
            }
            $this->runProcess(['docker', 'image', 'rm', '--force', $image]);
            $this->removeTree($fixture);
        }
    }

    /** @param array<int, string> $volumes @return array<int, string> */
    private function runtimeContainerCreateCommand(
        string $container,
        string $image,
        int $sharedGid,
        array $volumes,
    ): array {
        return [
            'docker', 'create', '--name', $container, '--network', 'none', '--read-only',
            '--security-opt', 'no-new-privileges=true', '--cap-drop', 'ALL', '--user', '10000:' . $sharedGid,
            '-e', 'MALLBASE_TARGET_UID=10000', '-e', 'MALLBASE_TARGET_GID=' . $sharedGid,
            '--mount', 'type=volume,dst=/app/runtime',
            '--mount', 'type=volume,src=' . $volumes[0] . ',dst=/app/runtime/install,volume-nocopy',
            '--mount', 'type=volume,src=' . $volumes[1] . ',dst=/app/runtime/storage,volume-nocopy',
            '--mount', 'type=volume,src=' . $volumes[2] . ',dst=/app/runtime/backup,volume-nocopy',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=8m,mode=1777',
            '--entrypoint', 'sh', $image, '-c', '/usr/local/bin/runtime-init.sh && exec sleep 300',
        ];
    }

    private function waitForContainerPath(string $container, string $path): void
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            [$code] = $this->runProcess(['docker', 'exec', $container, 'test', '-d', $path]);
            if ($code === 0) {
                return;
            }
            usleep(100_000);
        }
        [, $logs] = $this->runProcess(['docker', 'logs', $container]);
        self::fail('Container runtime init did not complete: ' . $logs);
    }

    private function dockerAvailable(): bool
    {
        [$code] = $this->runProcess(['docker', 'info', '--format', '{{.ServerVersion}}']);

        return $code === 0;
    }

    /** @param array<int, string> $command @return array{int,string} */
    private function runProcess(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot, null, ['bypass_shell' => true]);
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
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($root);
    }

    private function read(string $relative): string
    {
        $content = file_get_contents($this->projectRoot . '/' . $relative);
        self::assertIsString($content, $relative . ' must exist');

        return $content;
    }
}
