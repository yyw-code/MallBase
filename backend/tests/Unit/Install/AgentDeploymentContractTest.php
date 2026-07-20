<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

final class AgentDeploymentContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 4);
    }

    public function testProductionUsesOrdinaryDockerBuildAndKeepsThreeBusinessRoles(): void
    {
        $compose = $this->read('docker-compose.yml');

        self::assertStringContainsString('dockerfile: deploy/docker/Dockerfile', $compose);
        self::assertStringContainsString('image: ${MALLBASE_BACKEND_IMAGE:-mallbase-backend:latest}', $compose);
        self::assertStringContainsString('x-mallbase-runtime: &mallbase-runtime', $compose);
        self::assertStringContainsString('sh deploy/docker/host-preflight.sh', $compose);
        self::assertStringContainsString('docker compose up -d --build backend queue cron', $compose);
        self::assertSame(3, substr_count($compose, '<<: *mallbase-runtime'));
        foreach (['http', 'queue', 'cron'] as $role) {
            self::assertStringContainsString('MALLBASE_RUNTIME_ROLE: ' . $role, $compose);
        }
        self::assertStringContainsString(
            'command: ["php", "think", "queue:work", "redis", "--queue=default", "--tries=3"]',
            $compose,
        );
        self::assertStringContainsString('read_only: true', $compose);
        self::assertStringContainsString('no-new-privileges:true', $compose);
        self::assertMatchesRegularExpression('/cap_drop:\s+- ALL/s', $compose);
    }

    public function testComposeMountsOnlyTheSimpleUpgradeSharedSurface(): void
    {
        $production = $this->read('docker-compose.yml');
        $development = $this->read('docker-compose.dev.yml');

        foreach ([$production, $development] as $compose) {
            foreach ([
                ['./upgrade/bin', '/app/upgrade/bin'],
                ['./upgrade/config', '/app/upgrade/config'],
                ['./upgrade/run', '/app/upgrade/run'],
                ['./upgrade/jobs', '/app/upgrade/jobs'],
                ['./upgrade/backups', '/app/upgrade/backups'],
            ] as [$source, $target]) {
                self::assertStringContainsString('source: ' . $source, $compose);
                self::assertStringContainsString('target: ' . $target, $compose);
            }
        }

        foreach ([$production, $development] as $compose) {
            foreach (['storage-ready', 'storage-cutover', 'layout-generation', 'sealed'] as $legacy) {
                self::assertStringNotContainsString($legacy, $compose);
            }
        }
        foreach ([$production, $development] as $compose) {
            self::assertStringNotContainsString('bootstrap-retention', $compose);
        }
        foreach (['env', 'cert', 'demo', 'public-storage'] as $directory) {
            self::assertStringContainsString(
                'source: ./data/backend/' . $directory,
                $production,
                'persistent backend data must be mounted from data/backend',
            );
        }
    }

    public function testProductionKeepsBusinessDataInPlainNamedVolumes(): void
    {
        $compose = $this->read('docker-compose.yml');

        foreach ([
            ['backend_runtime', '/app/runtime'],
            ['backend_uploads', '/app/public/uploads'],
        ] as [$source, $target]) {
            self::assertStringContainsString('source: ' . $source, $compose);
            self::assertStringContainsString('target: ' . $target, $compose);
        }

        self::assertStringNotContainsString('com.mallbase.storage.', $compose);
        self::assertStringNotContainsString('nocopy: true', $compose);
        self::assertStringNotContainsString('MALLBASE_STORAGE_NAMESPACE', $compose);
        self::assertStringContainsString('MALLBASE_RUNTIME_VOLUME_NAME', $compose);
        self::assertStringContainsString('MALLBASE_UPLOADS_VOLUME_NAME', $compose);
        self::assertStringContainsString('name: "${MALLBASE_RUNTIME_VOLUME_NAME:-mallbase_runtime}"', $compose);
        self::assertStringContainsString('name: "${MALLBASE_UPLOADS_VOLUME_NAME:-mallbase_uploads}"', $compose);
    }

    public function testProductionDockerfileHasNoSealedOrCutoverRuntime(): void
    {
        $dockerfile = $this->read('deploy/docker/Dockerfile');

        self::assertMatchesRegularExpression(
            '/^FROM phpswoole\/swoole:php8\.2-alpine@sha256:[0-9a-f]{64} AS mallbase-runtime/m',
            $dockerfile,
        );
        self::assertStringContainsString('USER mallbase', $dockerfile);
        self::assertStringContainsString('ENTRYPOINT ["docker-entrypoint.sh"]', $dockerfile);
        foreach ([
            'sealed-context-validation', '.mallbase-sealed-context.json',
            '.mallbase-deployment.json', 'validate-sealed-attestation',
            'legacy-state-', 'target-state-verify', 'validate-storage-cutover',
            'runtime-init.sh',
        ] as $legacy) {
            self::assertStringNotContainsString($legacy, $dockerfile);
        }

        $dockerignore = $this->read('.dockerignore');
        self::assertMatchesRegularExpression('/^\/data\/$/m', $dockerignore);
    }

    public function testHostPreflightOnlyPreparesTheSimpleUpgradeWorkspace(): void
    {
        $preflight = $this->read('deploy/docker/host-preflight.sh');

        foreach ([
            'config', 'run', 'jobs', 'backups', 'packages', 'agent-private', 'staging',
        ] as $directory) {
            self::assertStringContainsString('"$UPGRADE_ROOT/' . $directory . '"', $preflight);
        }
        foreach (['env', 'cert', 'demo', 'public-storage'] as $directory) {
            self::assertStringContainsString('"$BACKEND_DATA_ROOT/' . $directory . '"', $preflight);
        }
        foreach ([
            'storage-init-results', 'legacy-import', 'legacy-results',
            'bootstrap-retention', 'prepare-cutover', 'prepare-bootstrap-adopt',
            'MALLBASE_STORAGE_NAMESPACE',
        ] as $legacy) {
            self::assertStringNotContainsString($legacy, $preflight);
        }
        self::assertStringContainsString('"$UPGRADE_ROOT/run/requests"', $preflight);
        self::assertStringContainsString('mallbase-agent-linux-$AGENT_ARCHITECTURE', $preflight);
        self::assertStringContainsString('MALLBASE_AGENT_USER', $preflight);
    }

    public function testHostPreflightAssignsOnlyTheReleaseInventoryToTheAgentUser(): void
    {
        $preflight = $this->read('deploy/docker/host-preflight.sh');

        self::assertStringContainsString(
            'MANAGED_RELEASE_FILES=$PROJECT_ROOT/release-files.sha256',
            $preflight,
        );
        self::assertStringContainsString('prepare_managed_release_tree', $preflight);
        self::assertStringContainsString('HOST_PREFLIGHT_RELEASE_INVENTORY_MISSING', $preflight);
        self::assertStringContainsString('HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID', $preflight);
        self::assertStringNotContainsString('HOST_UID=$(id -u)', $preflight);
        self::assertStringNotContainsString('chown "$HOST_UID:', $preflight);
        self::assertDoesNotMatchRegularExpression(
            '/\bchown\s+(?:-[^\s]+\s+)*-R\b|\bchown\s+--recursive\b/',
            $preflight,
        );
        foreach (['PROJECT_ROOT', 'UPGRADE_ROOT', 'BIN_ROOT', 'MANIFEST'] as $variable) {
            self::assertStringContainsString(
                '"$(uid_of "$' . $variable . '")" = "$AGENT_UID"',
                $preflight,
            );
        }
        self::assertStringContainsString('chmod 0755 "$PROJECT_ROOT"', $preflight);
        self::assertStringNotContainsString('chmod u+rwx "$path"', $preflight);
        self::assertStringContainsString('chmod 0750 "$UPGRADE_ROOT"', $preflight);
        self::assertStringContainsString('chmod 0750 "$BIN_ROOT"', $preflight);
        self::assertStringContainsString('"$(mode_of "$BIN_ROOT")" = 750', $preflight);
        self::assertStringContainsString('"$(uid_of "$binary")" = "$AGENT_UID"', $preflight);

        $documentation = $this->read('docs/install/upgrade-agent.md');
        self::assertStringContainsString('`release-files.sha256`', $documentation);
        self::assertStringContainsString('完整发布包', $documentation);
        self::assertStringContainsString('刚完整解压、尚未在线升级', $documentation);
        self::assertStringContainsString('不能作为已运行实例的重复 health check', $documentation);
        self::assertStringContainsString('bootstrap-only', $documentation);
        self::assertStringContainsString('完整解压到空的新目录', $documentation);
        self::assertStringContainsString('不允许运行时刷新这张表或维护第二套 inventory', $documentation);
        self::assertStringContainsString('Agent 和 systemd', $documentation);
        self::assertStringContainsString('TOCTOU', $documentation);
    }

    public function testSystemdStartsOneAgentProcessForEachQueuedJob(): void
    {
        $pathUnit = $this->read('deploy/systemd/mallbase-agent@.path');
        $serviceUnit = $this->read('deploy/systemd/mallbase-agent@.service');

        self::assertStringContainsString('PathExistsGlob=%f/upgrade/run/requests/*.json', $pathUnit);
        self::assertStringContainsString('Unit=mallbase-agent@%i.service', $pathUnit);
        self::assertStringContainsString('ExecStart=%f/upgrade/bin/active/mallbase-agent run-job', $serviceUnit);
        self::assertStringContainsString('User=mallbase-agent', $serviceUnit);
        self::assertStringContainsString('Group=mallbase-upgrade', $serviceUnit);
        self::assertStringNotContainsString('MALLBASE_PHP_BASE_URL', $serviceUnit);
        self::assertStringNotContainsString("ReadWritePaths=/\n", $serviceUnit);
        $pathPolicy = array_values(array_filter(
            preg_split('/\R/', trim($serviceUnit)) ?: [],
            static fn(string $line): bool => str_starts_with($line, 'ReadWritePaths=')
                || str_starts_with($line, 'ReadOnlyPaths='),
        ));
        self::assertSame([
            'ReadWritePaths=%f',
            'ReadOnlyPaths=%f/upgrade/bin',
            'ReadWritePaths=%f/upgrade/bin/active',
        ], $pathPolicy);
        self::assertStringNotContainsString(' serve', $pathUnit . $serviceUnit);
    }

    public function testProductionBackendPortIsLoopbackOnly(): void
    {
        $compose = $this->read('docker-compose.yml');

        self::assertStringContainsString(
            '127.0.0.1:${SWOOLE_HTTP_PORT:-8080}:${SWOOLE_HTTP_PORT:-8080}',
            $compose,
        );
    }

    public function testLegacyDeploymentFilesAndCommandsAreRemoved(): void
    {
        foreach ([
            'docker-compose.storage-adoption.yml',
            'docker-compose.storage-bootstrap.yml',
            'docker-compose.storage-cutover.yml',
            '.mallbase-deployment.json.example',
            'deploy/docker/bootstrap-permission-normalize.sh',
            'deploy/docker/bootstrap-retention-export.sh',
            'deploy/docker/bootstrap-retention-import.sh',
            'deploy/docker/bootstrap-retention-probe.php',
            'deploy/docker/bootstrap-retention-verify.sh',
            'deploy/docker/build-sealed-image.sh',
            'deploy/docker/fresh-storage-bootstrap.sh',
            'deploy/docker/fresh-storage-inspect.sh',
            'deploy/docker/fresh-storage-stamp.sh',
            'deploy/docker/legacy-state-export-verify.sh',
            'deploy/docker/legacy-state-import.sh',
            'deploy/docker/run-target-php.php',
            'deploy/docker/runtime-init.sh',
            'deploy/docker/start-sealed-image.sh',
            'deploy/docker/storage-cutover.sh',
            'deploy/docker/target-state-verify.sh',
            'deploy/docker/validate-bootstrap-adoption.php',
            'deploy/docker/validate-fresh-storage.php',
            'deploy/docker/validate-sealed-attestation.php',
            'deploy/docker/validate-storage-cutover.php',
            'backend/app/command/StorageCutoverTargetSnapshot.php',
            'backend/app/command/UpgradeBootstrapRetentionFinalize.php',
            'backend/app/command/UpgradeAdminSchema.php',
            'backend/app/command/UpgradeClientDecorationCustomMenu.php',
            'backend/app/command/UpgradeClientSearchSchema.php',
            'backend/app/command/UpgradeUserRegisterType.php',
            'backend/app/command/UpgradeUserWechatSchema.php',
            'backend/app/validate/admin/upgrade/UpgradeRequest.php',
            'backend/bin/upgrade-process-guard.php',
        ] as $file) {
            self::assertFileDoesNotExist($this->projectRoot . '/' . $file, $file);
        }

        $console = $this->read('backend/config/console.php');
        self::assertStringNotContainsString('storage-cutover', $console);
        self::assertStringNotContainsString('bootstrap-retention', $console);
        self::assertStringNotContainsString('upgrade:admin-schema', $console);
        self::assertStringNotContainsString('upgrade:client-decoration-custom-menu', $console);
        self::assertStringNotContainsString('upgrade:client-search-schema', $console);
        self::assertStringNotContainsString('upgrade:user-register-type', $console);
        self::assertStringNotContainsString('upgrade:user-wechat-schema', $console);
    }

    public function testReleasedAgentBinariesAndChecksumManifestRemainConsistent(): void
    {
        $bin = $this->projectRoot . '/upgrade/bin';
        $checksums = $bin . '/checksums.sha256';
        self::assertFileExists($checksums);
        self::assertSame(0644, fileperms($checksums) & 0777);

        $expected = [];
        foreach (file($checksums, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}  mallbase-agent-linux-(?:amd64|arm64)$/D', $line);
            [$digest, $name] = explode('  ', $line, 2);
            $expected[$name] = $digest;
        }
        self::assertSame(['mallbase-agent-linux-amd64', 'mallbase-agent-linux-arm64'], array_keys($expected));

        foreach ($expected as $name => $digest) {
            $path = $bin . '/' . $name;
            self::assertFileExists($path);
            self::assertSame(0755, fileperms($path) & 0777);
            self::assertSame($digest, hash_file('sha256', $path));
        }
    }

    public function testActiveAgentIsTheOnlySelfWritableBinarySurface(): void
    {
        $ignore = $this->read('upgrade/.gitignore');
        $preflight = $this->read('deploy/docker/host-preflight.sh');

        self::assertStringContainsString('/bin/active/', $ignore);
        self::assertStringNotContainsString('agent-manifest.json', $ignore . $preflight);
        self::assertStringContainsString('ACTIVE_BIN_ROOT=$BIN_ROOT/active', $preflight);
        self::assertStringContainsString('AGENT_LAUNCHER=$ACTIVE_BIN_ROOT/mallbase-agent', $preflight);
        self::assertStringNotContainsString('ln -s', $preflight);
        self::assertStringContainsString('prepare_active_binary', $preflight);
        self::assertStringContainsString('chmod 0750 "$BIN_ROOT"', $preflight);
        self::assertStringContainsString('chmod 0750 "$ACTIVE_BIN_ROOT"', $preflight);
        self::assertStringContainsString('chmod 0755 "$temporary"', $preflight);
        self::assertStringContainsString('"$(mode_of "$AGENT_LAUNCHER")" = 755', $preflight);

        $documentation = $this->read('docs/install/upgrade-agent.md');
        self::assertStringContainsString('runtime.GOARCH', $documentation);
        self::assertStringContainsString('`manifest.files[]`', $documentation);
        self::assertStringContainsString('`path`、`sha256`、`size`、`mode`', $documentation);
        self::assertStringContainsString('upgrade/bin/mallbase-agent-linux-amd64', $documentation);
        self::assertStringContainsString('upgrade/bin/mallbase-agent-linux-arm64', $documentation);
        self::assertStringContainsString('Agent 运行时不读取 `agent.artifacts[]`', $documentation);
        self::assertStringContainsString('不包含 `upgrade/bin/active/`', $documentation);
        self::assertStringContainsString('一次性离线引导', $documentation);
        self::assertStringContainsString('旧 Agent', $documentation);
        self::assertStringContainsString('拒绝 `upgrade/**`', $documentation);
        self::assertStringContainsString('没有 Agent 自更新步骤', $documentation);
        self::assertStringContainsString('不能作为 bridge', $documentation);
        self::assertStringContainsString('当前进程继续完成任务并退出', $documentation);
        self::assertStringContainsString('下一次 systemd 任务', $documentation);
        self::assertStringContainsString(
            '完整 ZIP 归档态中，两个 Agent 候选固定为 `0755`，`checksums.sha256` 固定为 `0644`',
            $documentation,
        );
        self::assertStringContainsString(
            'host-preflight 在宿主机离线安装阶段将候选收紧为 `0555`，将 `checksums.sha256` 收紧为 `0444`',
            $documentation,
        );
        self::assertStringContainsString('Platform 不在 MallBase 宿主机执行 `chmod`', $documentation);
        foreach ([
            '`VERSION`', '`SOURCE_COMMIT`', 'release key', '`build-release.sh`',
            '`dist/agent-manifest.json`', '不进入 Agent 运行时包',
        ] as $releaseContract) {
            self::assertStringContainsString($releaseContract, $documentation);
        }
    }

    public function testProductionShellSignalTrapsDoNotOverwriteExitCleanup(): void
    {
        foreach (glob($this->projectRoot . '/deploy/docker/*.sh') ?: [] as $path) {
            if (str_ends_with($path, '_test.sh')) {
                continue;
            }
            $script = file_get_contents($path);
            self::assertIsString($script);
            self::assertDoesNotMatchRegularExpression(
                '/^trap[ \t]+(?!-[ \t])[^\r\n]*[ \t]+(?:0|EXIT)[ \t]+(?:HUP|INT|TERM)(?:[ \t]|$)/m',
                $script,
                basename($path) . ' must keep signal and exit cleanup separate',
            );
        }
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->projectRoot . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }
}
