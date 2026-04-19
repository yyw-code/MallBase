<?php

declare(strict_types=1);

namespace Tests\Smoke;

use PHPUnit\Framework\TestCase;

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

        foreach (['DB_HOST', 'DB_PORT', 'REDIS_PORT', 'SWOOLE_HTTP_PORT'] as $key) {
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

        $required = ['SWOOLE_HTTP_PORT', 'MYSQL_PORT', 'MYSQL_ROOT_PASSWORD', 'REDIS_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $parsed, "deploy/docker/.example.env missing required key: {$key}");
        }
    }
}
