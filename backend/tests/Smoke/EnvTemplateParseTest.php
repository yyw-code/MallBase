<?php

declare(strict_types=1);

namespace Tests\Smoke;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTemplateParseTest extends TestCase
{
    public function testBackendExampleEnvIsParseIniCompatible(): void
    {
        $path = dirname(__DIR__, 2) . '/.example.env';

        $this->assertFileExists($path);

        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);

        $this->assertNotFalse(
            $parsed,
            'backend/.example.env must be parse_ini_file-compatible; '
            . 'ThinkPHP loads backend/.env via parse_ini_file. '
            . 'Avoid ( ) & | $ " inside # comments — use full-width or reword.'
        );

        foreach (['DB_HOST', 'DB_PORT', 'REDIS_PORT', 'SWOOLE_HTTP_PORT', 'SWOOLE_WORKER_NUM'] as $key) {
            $this->assertArrayHasKey($key, $parsed, "missing required key: {$key}");
        }
    }

    public function testDockerExampleEnvIsParseIniCompatible(): void
    {
        $path = dirname(__DIR__, 3) . '/deploy/docker/.example.env';

        $this->assertFileExists($path);

        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);

        $this->assertNotFalse(
            $parsed,
            'deploy/docker/.example.env must be parse_ini_file-compatible; '
            . 'ensure-env.sh parses this template to materialize root .env. '
            . 'Avoid ( ) & | $ " inside # comments — use full-width or reword.'
        );

        $required = [
            'SWOOLE_HTTP_PORT',
            'SWOOLE_WORKER_NUM',
            'APP_DEBUG',
            'JWT_SECRET',
            'JWT_EXPIRE',
            'JWT_REFRESH_EXPIRE',
            'MYSQL_PORT',
            'MYSQL_ROOT_PASSWORD',
            'REDIS_HOST_PORT',
            'REDIS_PORT',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'REDIS_HOST',
            'REDIS_CACHE_DB',
            'REDIS_PASSWORD',
            'CACHE_DRIVER',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $parsed, "deploy/docker/.example.env missing required key: {$key}");
        }

        $this->assertSame('false', $parsed['APP_DEBUG'] ?? null);
        $this->assertSame('6379', $parsed['REDIS_HOST_PORT'] ?? null);
        $this->assertSame('6379', $parsed['REDIS_PORT'] ?? null);
        $this->assertArrayNotHasKey('CRON_ENABLE', $parsed, 'Cron should be configured by install wizard or explicit production override, not root template default');
        $this->assertArrayNotHasKey('SWOOLE_QUEUE_ENABLE', $parsed, 'Queue worker should be configured by install wizard or explicit production override, not root template default');
    }

    public function testEnsureEnvDerivesBackendEnvAndSyncsSwooleWorkerNum(): void
    {
        $root = sys_get_temp_dir() . '/mallbase-env-smoke-' . bin2hex(random_bytes(6));

        mkdir($root . '/backend', 0777, true);
        mkdir($root . '/deploy/docker', 0777, true);

        copy(dirname(__DIR__, 2) . '/.example.env', $root . '/backend/.example.env');
        copy(dirname(__DIR__, 3) . '/deploy/docker/.example.env', $root . '/deploy/docker/.example.env');
        copy(dirname(__DIR__, 3) . '/deploy/docker/ensure-env.sh', $root . '/deploy/docker/ensure-env.sh');
        $mockBin = $this->installChownRecorder($root);
        $projectUid = fileowner($root);
        $projectGid = filegroup($root);
        $this->assertIsInt($projectUid);
        $this->assertIsInt($projectGid);

        try {
            exec(
                'PATH=' . escapeshellarg($mockBin . ':' . (string) getenv('PATH'))
                . ' MALLBASE_ENV_CHOWN_LOG=' . escapeshellarg($root . '/chown.log')
                . ' MALLBASE_DEV_UID=' . $projectUid
                . ' MALLBASE_DEV_GID=' . $projectGid
                . ' WORKDIR=' . escapeshellarg($root)
                . ' sh ' . escapeshellarg($root . '/deploy/docker/ensure-env.sh') . ' 2>&1',
                $output,
                $exitCode
            );

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $rootParsed = @parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
            $backendParsed = @parse_ini_file($root . '/backend/.env', true, INI_SCANNER_RAW);

            $this->assertNotFalse($rootParsed, 'derived root .env should remain parse_ini_file-compatible');
            $this->assertNotFalse($backendParsed, 'derived backend/.env should remain parse_ini_file-compatible');
            $this->assertSame(0600, fileperms($root . '/.env') & 0777);
            $this->assertSame($projectUid, fileowner($root . '/.env'));
            $this->assertSame($projectGid, filegroup($root . '/.env'));
            $this->assertSame(0600, fileperms($root . '/backend/.env') & 0777);
            $this->assertSame($projectUid, fileowner($root . '/backend/.env'));
            $this->assertSame($projectGid, filegroup($root . '/backend/.env'));
            $chownLog = (string) file_get_contents($root . '/chown.log');
            $this->assertStringContainsString(
                $projectUid . ':' . $projectGid . ' ' . $root . '/.env',
                $chownLog,
            );
            $this->assertStringContainsString(
                $projectUid . ':' . $projectGid . ' ' . $root . '/backend/.env',
                $chownLog,
            );
            $this->assertSame('1', $rootParsed['SWOOLE_WORKER_NUM'] ?? null);
            $this->assertSame('1', $backendParsed['SWOOLE_WORKER_NUM'] ?? null);
            $this->assertSame($rootParsed['DB_HOST'] ?? null, $backendParsed['DB_HOST'] ?? null);
            $this->assertSame($rootParsed['DB_PORT'] ?? null, $backendParsed['DB_PORT'] ?? null);
            $this->assertSame($rootParsed['REDIS_HOST'] ?? null, $backendParsed['REDIS_HOST'] ?? null);
            $this->assertSame($rootParsed['REDIS_PORT'] ?? null, $backendParsed['REDIS_PORT'] ?? null);
            $this->assertSame($rootParsed['REDIS_CACHE_DB'] ?? null, $backendParsed['REDIS_CACHE_DB'] ?? null);
            $this->assertSame($rootParsed['CACHE_DRIVER'] ?? null, $backendParsed['CACHE_DRIVER'] ?? null);
            $this->assertNotSame('please-change-or-leave-for-random', $rootParsed['JWT_SECRET'] ?? null);
            $this->assertSame($rootParsed['JWT_SECRET'] ?? null, $backendParsed['JWT_SECRET'] ?? null);
            $this->assertSame($rootParsed['JWT_EXPIRE'] ?? null, $backendParsed['JWT_EXPIRE'] ?? null);
            $this->assertSame($rootParsed['JWT_REFRESH_EXPIRE'] ?? null, $backendParsed['JWT_REFRESH_EXPIRE'] ?? null);
            $this->assertArrayNotHasKey('REDIS_HOST_PORT', $backendParsed);
            $this->assertArrayNotHasKey('CRON_ENABLE', $rootParsed);
            $this->assertArrayNotHasKey('SWOOLE_QUEUE_ENABLE', $rootParsed);
            $this->assertSame('false', $backendParsed['CRON_ENABLE'] ?? null);
            $this->assertSame('false', $backendParsed['SWOOLE_QUEUE_ENABLE'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testEnsureEnvMigratesLegacyRedisPortToHostMapping(): void
    {
        $root = sys_get_temp_dir() . '/mallbase-env-legacy-' . bin2hex(random_bytes(6));

        mkdir($root . '/backend', 0777, true);
        mkdir($root . '/deploy/docker', 0777, true);

        copy(dirname(__DIR__, 2) . '/.example.env', $root . '/backend/.example.env');
        copy(dirname(__DIR__, 3) . '/deploy/docker/.example.env', $root . '/deploy/docker/.example.env');
        copy(dirname(__DIR__, 3) . '/deploy/docker/ensure-env.sh', $root . '/deploy/docker/ensure-env.sh');
        $mockBin = $this->installChownRecorder($root);
        $projectUid = fileowner($root);
        $projectGid = filegroup($root);
        $this->assertIsInt($projectUid);
        $this->assertIsInt($projectGid);

        file_put_contents($root . '/.env', implode("\n", [
            'MALLBASE_COMPOSE_PROJECT_NAME=mallbase',
            'MALLBASE_CONTAINER_PREFIX=mallbase',
            'SWOOLE_HTTP_PORT=8080',
            'MYSQL_PORT=3306',
            'REDIS_PORT=16379',
            'MYSQL_ROOT_PASSWORD=root-pass',
            'DB_HOST=mysql',
            'DB_PORT=3306',
            'DB_NAME=mallbase',
            'DB_USER=mallbase',
            'DB_PASS=db-pass',
            'REDIS_HOST=redis',
            'REDIS_CACHE_DB=0',
            'REDIS_PASSWORD=',
            'CACHE_DRIVER=redis',
            'SITE_URL=http://localhost:8080',
            '',
        ]));

        try {
            exec(
                'PATH=' . escapeshellarg($mockBin . ':' . (string) getenv('PATH'))
                . ' MALLBASE_ENV_CHOWN_LOG=' . escapeshellarg($root . '/chown.log')
                . ' MALLBASE_DEV_UID=' . $projectUid
                . ' MALLBASE_DEV_GID=' . $projectGid
                . ' WORKDIR=' . escapeshellarg($root)
                . ' sh ' . escapeshellarg($root . '/deploy/docker/ensure-env.sh') . ' 2>&1',
                $output,
                $exitCode
            );

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $rootParsed = @parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
            $backendParsed = @parse_ini_file($root . '/backend/.env', true, INI_SCANNER_RAW);

            $this->assertNotFalse($rootParsed, 'migrated root .env should remain parse_ini_file-compatible');
            $this->assertNotFalse($backendParsed, 'migrated backend/.env should remain parse_ini_file-compatible');
            $this->assertSame(0600, fileperms($root . '/.env') & 0777);
            $this->assertSame($projectUid, fileowner($root . '/.env'));
            $this->assertSame($projectGid, filegroup($root . '/.env'));
            $this->assertSame(0600, fileperms($root . '/backend/.env') & 0777);
            $this->assertSame($projectUid, fileowner($root . '/backend/.env'));
            $this->assertSame($projectGid, filegroup($root . '/backend/.env'));
            $this->assertSame('16379', $rootParsed['REDIS_HOST_PORT'] ?? null);
            $this->assertSame('6379', $rootParsed['REDIS_PORT'] ?? null);
            $this->assertSame('6379', $backendParsed['REDIS_PORT'] ?? null);
            $this->assertSame($rootParsed['JWT_SECRET'] ?? null, $backendParsed['JWT_SECRET'] ?? null);
            $this->assertArrayNotHasKey('REDIS_HOST_PORT', $backendParsed);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testEnsureEnvRejectsEnvSymlinksBeforeWritingTargets(): void
    {
        foreach (['.env', 'backend/.env'] as $relativePath) {
            $root = sys_get_temp_dir() . '/mallbase-env-symlink-' . bin2hex(random_bytes(6));

            mkdir($root . '/backend', 0777, true);
            mkdir($root . '/deploy/docker', 0777, true);
            copy(dirname(__DIR__, 2) . '/.example.env', $root . '/backend/.example.env');
            copy(dirname(__DIR__, 3) . '/deploy/docker/.example.env', $root . '/deploy/docker/.example.env');
            copy(dirname(__DIR__, 3) . '/deploy/docker/ensure-env.sh', $root . '/deploy/docker/ensure-env.sh');

            if ($relativePath === 'backend/.env') {
                copy($root . '/deploy/docker/.example.env', $root . '/.env');
            }

            $outsideTarget = $root . '/outside-target';
            $outsideState = "outside-state\n";
            file_put_contents($outsideTarget, $outsideState);
            $outsideMode = fileperms($outsideTarget) & 0777;
            $outsideUid = fileowner($outsideTarget);
            $outsideGid = filegroup($outsideTarget);
            symlink($outsideTarget, $root . '/' . $relativePath);

            try {
                exec(
                    'MALLBASE_DEV_UID=' . (int) fileowner($root)
                    . ' MALLBASE_DEV_GID=' . (int) filegroup($root)
                    . ' WORKDIR=' . escapeshellarg($root)
                    . ' sh ' . escapeshellarg($root . '/deploy/docker/ensure-env.sh') . ' 2>&1',
                    $output,
                    $exitCode,
                );

                $this->assertNotSame(0, $exitCode, $relativePath . ' symlink must be rejected');
                $this->assertSame($outsideState, file_get_contents($outsideTarget));
                $this->assertSame($outsideMode, fileperms($outsideTarget) & 0777);
                $this->assertSame($outsideUid, fileowner($outsideTarget));
                $this->assertSame($outsideGid, filegroup($outsideTarget));
            } finally {
                $this->removeDirectory($root);
            }
        }
    }

    private function installChownRecorder(string $root): string
    {
        $mockBin = $root . '/mock-bin';
        mkdir($mockBin, 0777, true);
        file_put_contents($mockBin . '/chown', <<<'SH'
#!/bin/sh
set -eu
: "${MALLBASE_ENV_CHOWN_LOG:?}"
printf '%s\n' "$*" >> "$MALLBASE_ENV_CHOWN_LOG"
SH);
        chmod($mockBin . '/chown', 0755);
        file_put_contents($root . '/chown.log', '');

        return $mockBin;
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException("failed to scan temporary directory: {$path}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
