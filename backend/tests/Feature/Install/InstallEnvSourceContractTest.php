<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallEnvSourceContractTest extends TestCase
{
    public function testDockerBackendUsesRootEnvFileWithoutWorkspaceAlias(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.dev.yml');
        $entrypoint = (string) file_get_contents($root . '/deploy/docker/docker-entrypoint.sh');
        $docs = (string) file_get_contents($root . '/docs/install/docker-backend-only.md');

        $this->assertStringNotContainsString('- .:/workspace:ro', $compose);
        $this->assertSame(
            2,
            substr_count($compose, "env_file:\n      - path: .env\n        required: false"),
        );
        $this->assertStringContainsString('"${REDIS_HOST_PORT:-6379}:6379"', $compose);
        $this->assertStringNotContainsString('"${REDIS_PORT:-6379}:6379"', $compose);
        $this->assertStringContainsString('ROOT_ENV="/workspace/.env"', $entrypoint);
        $this->assertStringContainsString('derive_backend_env', $entrypoint);
        $this->assertStringContainsString('apply_root_env_to_backend', $entrypoint);
        $this->assertStringContainsString('默认端口、单套本地环境下，可以不准备根 `.env`', $docs);
        $this->assertStringContainsString('MALLBASE_COMPOSE_PROJECT_NAME', $docs);
        $this->assertStringContainsString('MALLBASE_CONTAINER_PREFIX', $docs);
        $this->assertStringContainsString('`MYSQL_PORT` / `REDIS_HOST_PORT` 是方式三 MySQL / Redis 容器给宿主机暴露端口时用的变量', $docs);
        $this->assertStringContainsString('`DB_HOST` 和 `REDIS_HOST` 不是启动后端容器的必填项', $docs);
        $this->assertStringNotContainsString('cp backend/.example.env backend/.env', $docs);
        $this->assertStringContainsString('不要手动复制或编辑 `backend/.env`', $docs);
    }

    public function testInstallServiceLetsRootEnvOverrideDerivedRuntimeEnvForInstallMeta(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . '.env'", $source);
        $this->assertStringContainsString('return array_merge($this->readBackendEnvFile(), $this->readRootEnvFile());', $source);
    }

    public function testInstallServiceRefreshesCacheDriverAfterRuntimeEnvChange(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("\$cacheManager = app()->make('cache');", $source);
        $this->assertStringContainsString("\$cacheManager->forgetDriver(['redis', 'file']);", $source);
    }

    public function testDockerProductionUsesRootEnvFileWithoutWorkspaceMount(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.yml');
        $dockerfile = (string) file_get_contents($root . '/deploy/docker/Dockerfile');
        $entrypoint = (string) file_get_contents($root . '/deploy/docker/docker-entrypoint.sh');
        $docs = (string) file_get_contents($root . '/docs/install/docker-production.md');

        $this->assertMatchesRegularExpression('/env_file:\s+- \.env/s', $compose);
        $this->assertStringNotContainsString('/workspace', $compose);
        $this->assertStringNotContainsString('backend/.env', $compose);
        $this->assertStringContainsString('COPY .version /.version', $dockerfile);
        $this->assertStringContainsString('apply_runtime_env_to_backend', $entrypoint);
        $this->assertStringContainsString('RUNTIME_TO_BACKEND_KEYS', $entrypoint);
        $this->assertStringContainsString('cp deploy/docker/.example.env .env', $docs);
        $this->assertStringContainsString('不等于安装前必须把数据库和 Redis 全部填完', $docs);
        $this->assertStringContainsString('可以先在 Web 安装向导里填写', $docs);
        $this->assertStringContainsString('安装完成后请把最终生效值同步回项目根目录 `.env`', $docs);
        $this->assertStringContainsString('不要手动复制或编辑 `backend/.env`', $docs);
        $this->assertStringContainsString('生产 compose 不挂载 `/workspace`', $docs);
        $this->assertStringContainsString('生产的三个业务角色不启动 MySQL / Redis 容器，所以不需要配置 `MYSQL_PORT` / `REDIS_HOST_PORT`', $docs);
        $this->assertStringContainsString('`backend_runtime` volume', $docs);
        $this->assertStringContainsString('`backend_uploads` volume', $docs);
    }
}
