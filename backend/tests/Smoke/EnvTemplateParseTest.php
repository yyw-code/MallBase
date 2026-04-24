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

        $required = ['SWOOLE_HTTP_PORT', 'SWOOLE_WORKER_NUM', 'MYSQL_PORT', 'MYSQL_ROOT_PASSWORD', 'REDIS_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $parsed, "deploy/docker/.example.env missing required key: {$key}");
        }
    }

    public function testEnsureEnvDerivesBackendEnvAndSyncsSwooleWorkerNum(): void
    {
        $root = sys_get_temp_dir() . '/mallbase-env-smoke-' . bin2hex(random_bytes(6));

        mkdir($root . '/backend', 0777, true);
        mkdir($root . '/deploy/docker', 0777, true);

        copy(dirname(__DIR__, 2) . '/.example.env', $root . '/backend/.example.env');
        copy(dirname(__DIR__, 3) . '/deploy/docker/.example.env', $root . '/deploy/docker/.example.env');
        copy(dirname(__DIR__, 3) . '/deploy/docker/ensure-env.sh', $root . '/deploy/docker/ensure-env.sh');

        try {
            exec(
                'WORKDIR=' . escapeshellarg($root) . ' sh ' . escapeshellarg($root . '/deploy/docker/ensure-env.sh') . ' 2>&1',
                $output,
                $exitCode
            );

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $rootParsed = @parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
            $backendParsed = @parse_ini_file($root . '/backend/.env', true, INI_SCANNER_RAW);

            $this->assertNotFalse($rootParsed, 'derived root .env should remain parse_ini_file-compatible');
            $this->assertNotFalse($backendParsed, 'derived backend/.env should remain parse_ini_file-compatible');
            $this->assertSame('1', $rootParsed['SWOOLE_WORKER_NUM'] ?? null);
            $this->assertSame('1', $backendParsed['SWOOLE_WORKER_NUM'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
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
