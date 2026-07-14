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

    public function testDefaultComposeKeepsBootstrapBroadStorageAndExactAgentMounts(): void
    {
        $production = $this->read('docker-compose.yml');
        $development = $this->read('docker-compose.dev.yml');

        foreach ([$production, $development] as $compose) {
            self::assertStringNotContainsString('./upgrade:/app/upgrade', $compose);
            foreach ($this->upgradeMounts() as [$source, $target, $readOnly]) {
                self::assertStringContainsString('source: ' . $source, $compose);
                self::assertStringContainsString('target: ' . $target, $compose);
                if ($readOnly) {
                    self::assertMatchesRegularExpression(
                        '/source:\s*' . preg_quote($source, '/') . '\s+target:\s*'
                            . preg_quote($target, '/') . '\s+read_only:\s*true\s+bind:\s+create_host_path:\s*false/s',
                        $compose,
                    );
                }
            }
        }

        self::assertStringNotContainsString('- .:/workspace:ro', $development);
        self::assertGreaterThanOrEqual(2, substr_count($development, "env_file:\n      - .env"));

        self::assertStringContainsString('source: backend_runtime', $production);
        self::assertStringContainsString('target: /app/runtime', $production);
        self::assertStringContainsString('source: backend_uploads', $production);
        self::assertStringContainsString('target: /app/public/uploads', $production);
        self::assertStringNotContainsString('target: /app/runtime/install', $production);
        self::assertStringNotContainsString('target: /app/runtime/storage', $production);
        self::assertStringNotContainsString('target: /app/runtime/backup', $production);
        self::assertStringNotContainsString('docker-compose.storage-cutover.yml', $production);

        foreach ($this->retentionBridges() as [$source, $target]) {
            self::assertStringContainsString('source: ' . $source, $production);
            self::assertStringContainsString('target: ' . $target, $production);
        }
    }

    public function testProductionRuntimeUsesPrebuiltImageAndRunsLockedDownAsNonRoot(): void
    {
        $production = $this->read('docker-compose.yml');
        $dockerfile = $this->read('deploy/docker/Dockerfile');
        $entrypoint = $this->read('deploy/docker/docker-entrypoint.sh');

        self::assertStringNotContainsString("    build:\n", $production);
        self::assertStringContainsString(
            'image: ${MALLBASE_BACKEND_IMAGE_ID:?set MALLBASE_BACKEND_IMAGE_ID}',
            $production,
        );
        self::assertStringContainsString('pull_policy: never', $production);
        self::assertStringContainsString(
            'user: "10000:${MALLBASE_UPGRADE_SHARED_GID:?run host-preflight.sh first}"',
            $production,
        );
        self::assertStringContainsString('read_only: true', $production);
        self::assertStringContainsString('no-new-privileges:true', $production);
        self::assertMatchesRegularExpression('/cap_drop:\s+- ALL/s', $production);
        self::assertStringContainsString('/tmp:rw,nosuid,nodev,noexec,size=64m,mode=1777', $production);

        self::assertStringContainsString('addgroup -g "$MALLBASE_APP_GID"', $dockerfile);
        self::assertStringContainsString('adduser -u "$MALLBASE_APP_UID"', $dockerfile);
        self::assertMatchesRegularExpression(
            '/FROM phpswoole\/swoole:php8\.2-alpine@sha256:[0-9a-f]{64}/',
            $dockerfile,
        );
        self::assertMatchesRegularExpression(
            '/COPY --from=composer:2@sha256:[0-9a-f]{64} \/usr\/bin\/composer/',
            $dockerfile,
        );
        self::assertStringContainsString('USER mallbase', $dockerfile);
        self::assertStringContainsString('/app/.mallbase-env/backend.env', $dockerfile);
        self::assertStringContainsString('opcache.jit_buffer_size=0', $dockerfile);
        self::assertStringNotContainsString('opcache.jit=1255', $dockerfile);
        self::assertStringNotContainsString('.mallbase-deployment.json.example', $dockerfile);
        self::assertStringNotContainsString('chmod -R 777', $dockerfile);

        $deploymentExample = json_decode(
            $this->read('.mallbase-deployment.json.example'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame([
            'schema_version', 'provenance_kind', 'app_version', 'deployment_id', 'release_id',
            'release_inventory_sha256', 'storage_layout_version', 'storage_layout_generation',
        ], array_keys($deploymentExample));
        self::assertSame('initial', $deploymentExample['provenance_kind']);
        self::assertArrayNotHasKey('secret', $deploymentExample);
        self::assertArrayNotHasKey('authority_revision', $deploymentExample);

        self::assertStringContainsString('MALLBASE_RUNTIME_MODE', $entrypoint);
        self::assertStringContainsString('RUNTIME_DEPENDENCIES_MISSING', $entrypoint);
        self::assertStringContainsString('RUNTIME_WRITABLE_PATH_INVALID', $entrypoint);
        self::assertStringContainsString('storage verify-ready-projection', $entrypoint);
        self::assertStringContainsString('STORAGE_LAYOUT_NOT_READY', $entrypoint);
        self::assertStringContainsString('restore_runtime_role_env', $entrypoint);
        self::assertStringContainsString('ensure_backend_env', $entrypoint);
        self::assertStringContainsString('flock -w 30 -x 9', $entrypoint);
        self::assertStringContainsString('ROLE_CRON_ENABLE', $entrypoint);
        self::assertStringContainsString('ROLE_SWOOLE_QUEUE_ENABLE', $entrypoint);
        self::assertStringContainsString('ROLE_SWOOLE_WORKER_NUM', $entrypoint);
        self::assertStringContainsString('mktemp "$(dirname "$file")/.backend.env.XXXXXX"', $entrypoint);
        self::assertStringContainsString('chmod 0600 "$source_file"', $entrypoint);
        self::assertStringContainsString('fsync($handle)', $entrypoint);
        self::assertStringContainsString('RUNTIME_ENV_OWNER_INVALID', $entrypoint);
        self::assertStringNotContainsString('>> "$file"', $entrypoint);
        self::assertStringNotContainsString('chmod -R 777', $entrypoint);
    }

    public function testDevelopmentImageDoesNotRequireProductionSealAuthority(): void
    {
        $development = $this->read('docker-compose.dev.yml');
        $developmentDockerfile = $this->read('deploy/docker/Dockerfile.dev');
        $productionDockerfile = $this->read('deploy/docker/Dockerfile');

        self::assertSame(2, substr_count($development, 'dockerfile: deploy/docker/Dockerfile.dev'));
        self::assertDoesNotMatchRegularExpression(
            '/^\s*dockerfile:\s+deploy\/docker\/Dockerfile\s*$/m',
            $development,
        );
        foreach ([
            '.mallbase-sealed-context.json', '.mallbase-deployment.json',
            'mallbase_context_seal', 'MALLBASE_EXPECTED_RECEIPT_ID', 'MALLBASE_EXPECTED_SEAL_ID',
        ] as $productionAuthority) {
            self::assertStringNotContainsString($productionAuthority, $developmentDockerfile);
        }
        self::assertStringContainsString('MALLBASE_RUNTIME_MODE: development', $development);
        self::assertStringContainsString('ENTRYPOINT ["docker-entrypoint.sh"]', $developmentDockerfile);
        self::assertStringContainsString(
            'COPY deploy/docker/export-backend-env.php /usr/local/bin/export-backend-env.php',
            $developmentDockerfile,
        );
        self::assertStringContainsString('opcache.jit_buffer_size=0', $developmentDockerfile);
        self::assertStringContainsString('display_errors=stderr', $this->read('deploy/docker/docker-entrypoint.sh'));

        self::assertStringContainsString('COPY .mallbase-sealed-context.json', $productionDockerfile);
        self::assertStringContainsString('COPY .mallbase-deployment.json', $productionDockerfile);
        self::assertStringContainsString(
            '--mount=type=secret,id=mallbase_context_seal,required=true',
            $productionDockerfile,
        );
        self::assertStringContainsString('from=sealed-context-validation', $productionDockerfile);
    }

    public function testProductionShellSignalTrapsKeepExitCleanupSeparate(): void
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
                basename($path) . ' must not overwrite EXIT cleanup with a signal trap',
            );
        }
    }

    public function testProductionSeparatesHttpQueueAndCronWithOneExactMountContract(): void
    {
        $production = $this->read('docker-compose.yml');
        $cutover = $this->read('docker-compose.storage-cutover.yml');

        self::assertStringContainsString('x-mallbase-runtime: &mallbase-runtime', $production);
        self::assertSame(3, substr_count($production, '<<: *mallbase-runtime'));
        self::assertMatchesRegularExpression('/backend:\s+<<: \*mallbase-runtime/s', $production);
        self::assertMatchesRegularExpression('/queue:\s+<<: \*mallbase-runtime/s', $production);
        self::assertMatchesRegularExpression('/cron:\s+<<: \*mallbase-runtime/s', $production);
        self::assertStringContainsString('MALLBASE_RUNTIME_ROLE: http', $production);
        self::assertStringContainsString('MALLBASE_RUNTIME_ROLE: queue', $production);
        self::assertStringContainsString('MALLBASE_RUNTIME_ROLE: cron', $production);
        self::assertStringContainsString('command: ["php", "think", "queue:work", "redis", "--queue=default", "--tries=3"]', $production);
        self::assertStringContainsString('command: ["php", "think", "swoole"]', $production);

        foreach (['backend', 'queue', 'cron'] as $role) {
            self::assertMatchesRegularExpression('/' . $role . ':\s+<<: \*mallbase-storage-cutover/s', $cutover);
        }
    }

    public function testPersistentDemoRootMatchesTheProductionMount(): void
    {
        $config = $this->read('backend/config/upgrade.php');

        self::assertMatchesRegularExpression(
            "/'demo'\\s*=>\\s*rtrim\\(public_path\\(\\), DIRECTORY_SEPARATOR\\)\\s*\\.\\s*DIRECTORY_SEPARATOR\\s*\\.\\s*'static'\\s*\\.\\s*DIRECTORY_SEPARATOR\\s*\\.\\s*'demo'/s",
            $config,
        );
    }

    public function testReleasedAgentBinariesAndChecksumManifestAreSelfConsistent(): void
    {
        $bin = $this->projectRoot . '/upgrade/bin';
        $checksums = $bin . '/checksums.sha256';
        self::assertFileExists($checksums);
        self::assertFalse(is_link($checksums));
        self::assertSame(0444, fileperms($checksums) & 0777);

        $expected = [];
        foreach (file($checksums, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}  mallbase-agent-linux-(?:amd64|arm64)$/D', $line);
            [$digest, $name] = explode('  ', $line, 2);
            self::assertArrayNotHasKey($name, $expected);
            $expected[$name] = $digest;
        }
        self::assertSame(['mallbase-agent-linux-amd64', 'mallbase-agent-linux-arm64'], array_keys($expected));

        foreach ($expected as $name => $digest) {
            $path = $bin . '/' . $name;
            self::assertFileExists($path);
            self::assertFalse(is_link($path));
            self::assertSame(0555, fileperms($path) & 0777);
            self::assertSame($digest, hash_file('sha256', $path));
        }
    }

    public function testSealedImageWrappersEnforceImmutableReceiptChain(): void
    {
        $dockerfile = $this->read('deploy/docker/Dockerfile');
        $production = $this->read('docker-compose.yml');
        $build = $this->read('deploy/docker/build-sealed-image.sh');
        $start = $this->read('deploy/docker/start-sealed-image.sh');
        $validator = $this->read('deploy/docker/validate-sealed-attestation.php');
        $dockerignore = $this->read('.dockerignore');

        self::assertStringContainsString('AS sealed-context-validation', $dockerfile);
        self::assertStringContainsString('--mount=type=secret,id=mallbase_context_seal,required=true', $dockerfile);
        self::assertStringContainsString('from=sealed-context-validation', $dockerfile);
        self::assertStringContainsString('COPY .mallbase-deployment.json /.mallbase-deployment.json', $dockerfile);
        self::assertSame(1, substr_count($dockerfile, 'COPY .mallbase-sealed-context.json'));
        self::assertStringNotContainsString('COPY .mallbase-release-inventory.json', $dockerfile);
        self::assertStringNotContainsString('COPY upgrade/', $dockerfile);
        $runtimeStage = explode('AS mallbase-runtime', $dockerfile, 2)[1] ?? '';
        self::assertNotSame('', $runtimeStage);
        self::assertStringNotContainsString('COPY .mallbase-sealed-context.json', $runtimeStage);
        self::assertStringNotContainsString('COPY .mallbase-release-inventory.json', $runtimeStage);
        self::assertStringNotContainsString('COPY upgrade/', $runtimeStage);
        self::assertStringNotContainsString('COPY backend/runtime', $runtimeStage);
        self::assertStringNotContainsString('COPY backend/storage/cert', $runtimeStage);
        self::assertStringNotContainsString('COPY backend/public/storage', $runtimeStage);
        self::assertStringNotContainsString('COPY backend/public/uploads', $runtimeStage);

        foreach ([
            'backend/.env', 'backend/.env.*', 'backend/runtime', 'backend/storage/cert',
            'backend/public/storage', 'backend/public/uploads', 'upgrade', '.env', '.env.*',
            '**/*.key', '**/*.pem', '**/*.p12', '**/*.pfx', '**/*.jks', '**/*.keystore',
        ] as $excluded) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($excluded, '/') . '$/m',
                $dockerignore,
                'raw Docker context must exclude ' . $excluded,
            );
        }
        self::assertStringContainsString('build-sealed-image.sh', $production);
        self::assertStringContainsString('start-sealed-image.sh', $production);

        self::assertStringContainsString('seal-build-context create', $build);
        self::assertStringContainsString('id=mallbase_context_seal,src=', $build);
        self::assertStringContainsString('seal-build-context record-image', $build);
        self::assertStringContainsString("umask 077", $build);
        self::assertStringContainsString('seal-build-context verify-image-receipt', $start);
        self::assertStringContainsString('up -d --pull never --no-build backend queue cron', $start);
        self::assertStringContainsString("grep -Eq '^[0-9a-f]{32}$'", $start);
        self::assertStringContainsString('hash_hmac', $validator);
        self::assertStringContainsString('hash_equals', $validator);

        foreach (['build-sealed-image_test.sh', 'start-sealed-image_test.sh'] as $script) {
            [$code, $output] = $this->runProcess(['sh', $this->projectRoot . '/deploy/docker/' . $script]);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('tests passed', $output);
        }
    }

    public function testFreshStorageBootstrapUsesFixedNoNetworkHelpersAndNamespacedBroadVolumes(): void
    {
        $production = $this->read('docker-compose.yml');
        $bootstrap = $this->read('docker-compose.storage-bootstrap.yml');
        $adoption = $this->read('docker-compose.storage-adoption.yml');
        $wrapper = $this->read('deploy/docker/fresh-storage-bootstrap.sh');
        $retentionExport = $this->read('deploy/docker/bootstrap-retention-export.sh');
        $retentionProbe = $this->read('deploy/docker/bootstrap-retention-probe.php');
        $inspection = $this->read('deploy/docker/fresh-storage-inspect.sh');
        $stamp = $this->read('deploy/docker/fresh-storage-stamp.sh');

        foreach (['backend_runtime', 'backend_uploads'] as $volume) {
            self::assertMatchesRegularExpression(
                '/' . $volume . ':\s+name:\s+"\$\{MALLBASE_STORAGE_NAMESPACE:\?[^}]+\}_(?:runtime|uploads)"/s',
                $production,
            );
        }
        self::assertStringContainsString('com.mallbase.storage.namespace:', $production);
        self::assertStringContainsString('com.mallbase.storage.managed: "true"', $production);
        self::assertGreaterThanOrEqual(2, substr_count($production, "volume:\n        nocopy: true"));

        self::assertStringContainsString(
            'alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc',
            $bootstrap,
        );
        [$composeCode, $rendered] = $this->runProcess([
            'docker', 'compose', '--file', $this->projectRoot . '/docker-compose.storage-bootstrap.yml',
            'config', '--no-interpolate',
        ]);
        self::assertSame(0, $composeCode, $rendered);
        foreach (['fresh-storage-inspect', 'fresh-storage-stamp'] as $service) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($service, '/') . ':.*?network_mode:\s+none.*?read_only:\s+true/s',
                $rendered,
            );
            self::assertMatchesRegularExpression(
                '/' . preg_quote($service, '/') . ':.*?cap_add:\s+- DAC_READ_SEARCH\s+- CHOWN\s+- FOWNER.*?cap_drop:\s+- ALL/s',
                $rendered,
            );
        }
        [$adoptionCode, $renderedAdoption] = $this->runProcess([
            'docker', 'compose', '--file', $this->projectRoot . '/docker-compose.storage-adoption.yml',
            'config', '--no-interpolate',
        ]);
        self::assertSame(0, $adoptionCode, $renderedAdoption);
        [$mergedAdoptionCode, $mergedAdoption] = $this->runProcess([
            'env',
            'MALLBASE_BACKEND_IMAGE_ID=sha256:' . str_repeat('c', 64),
            'MALLBASE_UPGRADE_SHARED_GID=3000',
            'MALLBASE_STORAGE_NAMESPACE=mbs_adoption_contract',
            'MALLBASE_BOOTSTRAP_OPERATION_ID=018f5d35-3f42-7a31-a731-9e45df3356c2',
            'MALLBASE_BOOTSTRAP_RUNTIME_VOLUME_NAME=legacy_runtime',
            'MALLBASE_BOOTSTRAP_UPLOADS_VOLUME_NAME=legacy_uploads',
            'MALLBASE_AGENT_UID=2000',
            'MALLBASE_BOOTSTRAP_DATA_NETWORK=mallbase_data',
            'docker', 'compose',
            '--file', $this->projectRoot . '/docker-compose.yml',
            '--file', $this->projectRoot . '/docker-compose.storage-adoption.yml',
            'config',
        ]);
        self::assertSame(0, $mergedAdoptionCode, $mergedAdoption);
        self::assertMatchesRegularExpression(
            '/backend_runtime:\s+name:\s+legacy_runtime\s+external:\s+true/s',
            $mergedAdoption,
        );
        self::assertMatchesRegularExpression(
            '/backend_uploads:\s+name:\s+legacy_uploads\s+external:\s+true/s',
            $mergedAdoption,
        );
        foreach (['bootstrap-permission-normalize', 'bootstrap-retention-import'] as $service) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($service, '/') . ':.*?network_mode:\s+none.*?read_only:\s+true/s',
                $renderedAdoption,
            );
            self::assertMatchesRegularExpression(
                '/' . preg_quote($service, '/') . ':.*?cap_add:\s+- DAC_READ_SEARCH\s+- CHOWN\s+- FOWNER.*?cap_drop:\s+- ALL/s',
                $renderedAdoption,
            );
        }
        self::assertMatchesRegularExpression(
            '/bootstrap-target-confirm:.*?entrypoint:\s+- php\s+- think\s+- upgrade:bootstrap-retention-finalize.*?cap_drop:\s+- ALL/s',
            $renderedAdoption,
        );
        self::assertMatchesRegularExpression(
            '/bootstrap-target-db:\s+external:\s+true\s+name:\s+\$\{MALLBASE_BOOTSTRAP_DATA_NETWORK:/s',
            $renderedAdoption,
        );
        self::assertStringContainsString('source: ./upgrade/staging/bootstrap-target-authority.json', $adoption);
        self::assertStringContainsString('target: /bootstrap-input/request.json', $adoption);
        self::assertStringContainsString('target: /bootstrap-input/import.json', $adoption);
        self::assertMatchesRegularExpression(
            '/source:\s+\.\/upgrade\/bootstrap-retention\/operations\/\$\{MALLBASE_BOOTSTRAP_OPERATION_ID:[^}]+\}\/import\.json\s+target:\s+\/bootstrap-input\/import\.json\s+read_only:\s+true\s+bind:\s+create_host_path:\s+false/s',
            $adoption,
        );
        self::assertStringContainsString(
            'source: ./upgrade/bootstrap-retention/operations/${MALLBASE_BOOTSTRAP_OPERATION_ID:?bootstrap operation id is required}/target-output',
            $adoption,
        );
        self::assertStringContainsString('target: /bootstrap-results/target', $adoption);
        self::assertStringContainsString('host.docker.internal:host-gateway', $adoption);
        self::assertMatchesRegularExpression(
            '/bootstrap-target-confirm:.*?source:\s+\.\/upgrade\/bootstrap-retention\/operations\/.*?target-output.*?target:\s+\/bootstrap-results\/target/s',
            $adoption,
        );
        self::assertGreaterThanOrEqual(8, substr_count($renderedAdoption, 'read_only: true'));
        self::assertStringNotContainsString('agent-private', $adoption);
        self::assertStringNotContainsString('source: .\n', $adoption);
        self::assertStringNotContainsString('/var/run/docker.sock', $adoption);
        foreach (['cert', 'demo', 'install', 'local_storage', 'public_storage', 'runtime_backup', 'uploads'] as $artifact) {
            self::assertStringContainsString('/markers/' . $artifact . '.json', $bootstrap);
        }

        self::assertStringNotContainsString('MALLBASE_BACKEND_IMAGE_ID', $bootstrap);
        self::assertStringNotContainsString('MALLBASE_BOOTSTRAP_OPERATION_ID', $bootstrap);
        self::assertStringNotContainsString('MALLBASE_BOOTSTRAP_DATA_NETWORK', $bootstrap);
        [$freshOnlyCode, $freshOnlyOutput] = $this->runProcess([
            'env', '-u', 'MALLBASE_BACKEND_IMAGE_ID', '-u', 'MALLBASE_BOOTSTRAP_OPERATION_ID',
            '-u', 'MALLBASE_BOOTSTRAP_DATA_NETWORK',
            'MALLBASE_UPGRADE_SHARED_GID=3000',
            'MALLBASE_STORAGE_OPERATION_ID=018f5d35-3f42-7a31-a731-9e45df3356c2',
            'MALLBASE_STORAGE_NAMESPACE=mbs_fresh_only', 'MALLBASE_AGENT_UID=2000',
            'MALLBASE_RUNTIME_VOLUME_NAME=mbs_fresh_only_runtime',
            'MALLBASE_RUNTIME_MOUNT_IDENTITY=docker-runtime',
            'MALLBASE_RUNTIME_POLICY_SHA256=sha256:' . str_repeat('a', 64),
            'MALLBASE_UPLOADS_VOLUME_NAME=mbs_fresh_only_uploads',
            'MALLBASE_UPLOADS_MOUNT_IDENTITY=docker-uploads',
            'MALLBASE_UPLOADS_POLICY_SHA256=sha256:' . str_repeat('b', 64),
            'docker', 'compose', '--file', $this->projectRoot . '/docker-compose.storage-bootstrap.yml',
            'config',
        ]);
        self::assertSame(0, $freshOnlyCode, $freshOnlyOutput);

        self::assertStringContainsString('storage bootstrap-id', $wrapper);
        self::assertStringContainsString('storage inspect', $wrapper);
        self::assertStringContainsString('storage prepare', $wrapper);
        self::assertStringContainsString('storage finalize', $wrapper);
        self::assertStringContainsString('fresh-inspection.json', $inspection);
        self::assertStringContainsString('FRESH_STORAGE_ENV_POLICY_INVALID', $inspection);
        self::assertStringContainsString('.mallbase-layout-marker.json', $stamp);
        self::assertStringNotContainsString('eval ', $wrapper . $inspection . $stamp);
        self::assertStringNotContainsString('chmod -R', $wrapper . $inspection . $stamp);
        self::assertStringNotContainsString('chown -R', $wrapper . $inspection . $stamp);
        self::assertStringContainsString('fresh-storage-bootstrap.sh" --project-root "$PROJECT_ROOT" prepare', $this->read('deploy/docker/build-sealed-image.sh'));
        self::assertStringContainsString('provenance initialize', $this->read('deploy/docker/build-sealed-image.sh'));
        $start = $this->read('deploy/docker/start-sealed-image.sh');
        self::assertStringContainsString('fresh-storage-bootstrap.sh" --project-root "$PROJECT_ROOT" finalize', $start);
        self::assertStringContainsString('storage bootstrap-adopt finalize', $start);
        self::assertStringContainsString('SEALED_STORAGE_NOT_READY', $start);
        self::assertLessThan(
            strpos($start, 'compose up -d --pull never --no-build backend queue cron'),
            strpos($start, 'fresh-storage-bootstrap.sh" --project-root "$PROJECT_ROOT" finalize'),
        );
        self::assertStringContainsString('FRESH_STORAGE_HOST_OS_UNSUPPORTED', $wrapper);
        self::assertStringContainsString('storage bootstrap-id', $retentionExport);
        self::assertStringContainsString('agent_mutation stage-authority', $retentionExport);
        self::assertStringContainsString('bootstrap-retention-probe.php', $retentionExport);
        self::assertStringContainsString('BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED', $retentionProbe);
        self::assertStringContainsString("['uploads', 'storage', 'static/demo']", $retentionProbe);
        self::assertLessThan(
            strpos($retentionExport, 'storage bootstrap-id'),
            strpos($retentionExport, 'docker exec -i'),
            'The read-only probe must finish before bootstrap-id can write trust state.',
        );
        self::assertLessThan(
            strpos($retentionExport, 'write-source'),
            strpos($retentionExport, 'fsync-retention'),
            'Retained evidence must be durable before source authority is published.',
        );
        foreach (['docker stop', 'docker rm', 'compose down', 'prune', '--remove-orphans', 'eval '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $retentionExport);
        }
        self::assertStringContainsString('SEALED_HOST_OS_UNSUPPORTED', $this->read('deploy/docker/build-sealed-image.sh'));
        self::assertStringContainsString('SEALED_HOST_OS_UNSUPPORTED', $start);

        [$code, $output] = $this->runProcess([
            'sh', $this->projectRoot . '/deploy/docker/fresh-storage-bootstrap_test.sh',
        ]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('tests passed', $output);

        $targetPublisher = $this->read('deploy/docker/bootstrap-retention-verify.sh');
        self::assertStringContainsString('publish-target', $targetPublisher);
        self::assertStringContainsString('bootstrap-target-authority.json', $targetPublisher);
        self::assertStringContainsString('storage-ready.pub', $targetPublisher);
        self::assertStringNotContainsString('--source', $targetPublisher);
        foreach ([
            'bootstrap-permission-normalize_test.sh',
            'bootstrap-retention-export_test.sh',
            'bootstrap-retention-import_test.sh',
            'bootstrap-retention-verify_test.sh',
        ] as $script) {
            [$helperCode, $helperOutput] = $this->runProcess([
                'sh', $this->projectRoot . '/deploy/docker/' . $script,
            ]);
            self::assertSame(0, $helperCode, $helperOutput);
            self::assertStringContainsString('tests passed', $helperOutput);
        }
    }

    public function testStorageOverlaysMergeWithProductionComposeWithoutVolumeConflicts(): void
    {
        $baseEnvironment = [
            'env',
            'MALLBASE_BACKEND_IMAGE_ID=sha256:' . str_repeat('c', 64),
            'MALLBASE_STORAGE_NAMESPACE=mbs_overlay_contract',
            'MALLBASE_UPGRADE_SHARED_GID=3000',
        ];
        $operationId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        $policyHash = 'sha256:' . str_repeat('d', 64);

        [$bootstrapCode, $bootstrap] = $this->runProcess(array_merge(
            $baseEnvironment,
            [
                'MALLBASE_AGENT_UID=2000',
                'MALLBASE_APP_UID=10000',
                'MALLBASE_RUNTIME_MOUNT_IDENTITY=runtime-contract',
                'MALLBASE_RUNTIME_POLICY_SHA256=' . $policyHash,
                'MALLBASE_RUNTIME_VOLUME_NAME=mbs_overlay_contract_runtime',
                'MALLBASE_STORAGE_OPERATION_ID=' . $operationId,
                'MALLBASE_UPLOADS_MOUNT_IDENTITY=uploads-contract',
                'MALLBASE_UPLOADS_POLICY_SHA256=' . $policyHash,
                'MALLBASE_UPLOADS_VOLUME_NAME=mbs_overlay_contract_uploads',
                'docker', 'compose',
                '--file', $this->projectRoot . '/docker-compose.yml',
                '--file', $this->projectRoot . '/docker-compose.storage-bootstrap.yml',
                'config',
            ],
        ));
        self::assertSame(0, $bootstrapCode, $bootstrap);
        self::assertMatchesRegularExpression(
            '/backend_runtime:\s+name:\s+mbs_overlay_contract_runtime\s+external:\s+true/s',
            $bootstrap,
        );
        self::assertMatchesRegularExpression(
            '/backend_uploads:\s+name:\s+mbs_overlay_contract_uploads\s+external:\s+true/s',
            $bootstrap,
        );

        [$adoptionCode, $adoption] = $this->runProcess(array_merge(
            $baseEnvironment,
            [
                'MALLBASE_AGENT_UID=2000',
                'MALLBASE_BOOTSTRAP_DATA_NETWORK=mallbase_data',
                'MALLBASE_BOOTSTRAP_OPERATION_ID=' . $operationId,
                'MALLBASE_BOOTSTRAP_RUNTIME_VOLUME_NAME=legacy_runtime',
                'MALLBASE_BOOTSTRAP_UPLOADS_VOLUME_NAME=legacy_uploads',
                'docker', 'compose',
                '--file', $this->projectRoot . '/docker-compose.yml',
                '--file', $this->projectRoot . '/docker-compose.storage-adoption.yml',
                'config',
            ],
        ));
        self::assertSame(0, $adoptionCode, $adoption);
        self::assertMatchesRegularExpression('/backend_runtime:\s+name:\s+legacy_runtime\s+external:\s+true/s', $adoption);
        self::assertMatchesRegularExpression('/backend_uploads:\s+name:\s+legacy_uploads\s+external:\s+true/s', $adoption);

        $cutoverVolumes = [
            'SOURCE_RUNTIME' => 'cutover_source_runtime',
            'CERT' => 'cutover_cert',
            'DEMO' => 'cutover_demo',
            'INSTALL' => 'cutover_install',
            'LOCAL_STORAGE' => 'cutover_local_storage',
            'PUBLIC_STORAGE' => 'cutover_public_storage',
            'RUNTIME_BACKUP' => 'cutover_runtime_backup',
            'UPLOADS' => 'cutover_uploads',
        ];
        $cutoverEnvironment = ['MALLBASE_AGENT_UID=2000', 'MALLBASE_UPGRADE_JOB_ID=' . $operationId];
        foreach ($cutoverVolumes as $role => $volume) {
            $cutoverEnvironment[] = 'MALLBASE_CUTOVER_' . $role . '_VOLUME_NAME=' . $volume;
        }
        [$cutoverCode, $cutover] = $this->runProcess(array_merge(
            $baseEnvironment,
            $cutoverEnvironment,
            [
                'docker', 'compose',
                '--file', $this->projectRoot . '/docker-compose.yml',
                '--file', $this->projectRoot . '/docker-compose.storage-cutover.yml',
                '--profile', 'storage-cutover',
                'config',
            ],
        ));
        self::assertSame(0, $cutoverCode, $cutover);
        foreach ($cutoverVolumes as $volume) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($volume, '/') . ':\s+name:\s+' . preg_quote($volume, '/') . '\s+external:\s+true/s',
                $cutover,
            );
        }
    }

    public function testHostPreflightCreatesOnlyRuntimeRootsAndFailsClosedOnTamper(): void
    {
        $fixture = sys_get_temp_dir() . '/mallbase-host-preflight-' . bin2hex(random_bytes(8));
        mkdir($fixture . '/upgrade/bin', 0755, true);
        try {
            $artifacts = [
                'mallbase-agent-linux-amd64' => 'amd64-agent',
                'mallbase-agent-linux-arm64' => 'arm64-agent',
            ];
            $manifest = '';
            foreach ($artifacts as $name => $content) {
                file_put_contents($fixture . '/upgrade/bin/' . $name, $content);
                $manifest .= hash('sha256', $content) . '  ' . $name . "\n";
            }
            file_put_contents($fixture . '/upgrade/bin/checksums.sha256', $manifest);
            file_put_contents($fixture . '/.env', "DB_PASS=canary\n");

            $script = $this->projectRoot . '/deploy/docker/host-preflight.sh';
            [$code, $output] = $this->runProcess(['sh', $script, '--project-root', $fixture]);
            self::assertSame(0, $code, $output);
            foreach (['config', 'run', 'state', 'jobs', 'backups', 'lifetime-locks', 'staging',
                         'packages', 'logs', 'agent-private', 'storage-init-results',
                         'bootstrap-retention/env', 'bootstrap-retention/cert', 'bootstrap-retention/demo',
                         'bootstrap-retention/public-storage', 'bootstrap-retention/operations',
                         'agent-private/bootstrap-retention/receipts', 'legacy-import',
                         'legacy-import/bootstrap-adopt', 'legacy-results', 'legacy-results/bootstrap-adopt'] as $relative) {
                self::assertDirectoryExists($fixture . '/upgrade/' . $relative);
            }
            self::assertSame(0750, fileperms($fixture . '/upgrade') & 07777);
            foreach (['config', 'run', 'state', 'jobs', 'backups'] as $relative) {
                self::assertSame(02770, fileperms($fixture . '/upgrade/' . $relative) & 07777);
            }
            foreach (['packages', 'logs', 'agent-private', 'bootstrap-retention', 'storage-init-results',
                         'legacy-import', 'legacy-results'] as $relative) {
                self::assertSame(0700, fileperms($fixture . '/upgrade/' . $relative) & 07777);
            }
            self::assertFileExists($fixture . '/upgrade/bootstrap-retention/env/backend.env');
            self::assertSame(0600, fileperms($fixture . '/upgrade/bootstrap-retention/env/backend.env') & 0777);
            self::assertFileExists($fixture . '/upgrade/lifetime-locks/serve.lock');
            self::assertSame(0440, fileperms($fixture . '/upgrade/lifetime-locks/serve.lock') & 0777);
            self::assertFileExists($fixture . '/.env');
            $rootEnv = file_get_contents($fixture . '/.env');
            self::assertIsString($rootEnv);
            self::assertStringContainsString('MALLBASE_AGENT_UID=' . posix_geteuid(), $rootEnv);
            self::assertStringContainsString('MALLBASE_UPGRADE_SHARED_GID=' . posix_getegid(), $rootEnv);
            self::assertStringContainsString('MALLBASE_DEV_UID=' . posix_geteuid(), $rootEnv);
            self::assertStringContainsString('MALLBASE_DEV_GID=' . posix_getegid(), $rootEnv);
            self::assertStringContainsString('DB_PASS=canary', $rootEnv);
            self::assertSame(0555, fileperms($fixture . '/upgrade/bin') & 0777);
            self::assertFileDoesNotExist($fixture . '/upgrade/staging/storage-ready.json');

            [$checkCode, $checkOutput] = $this->runProcess(['sh', $script, '--check', '--project-root', $fixture]);
            self::assertSame(0, $checkCode, $checkOutput);

            $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3a3';
            [$cutoverCode, $cutoverOutput] = $this->runProcess([
                'sh', $script, '--project-root', $fixture, '--prepare-cutover', $jobId,
            ]);
            self::assertSame(0, $cutoverCode, $cutoverOutput);
            self::assertDirectoryExists($fixture . '/upgrade/legacy-import/' . $jobId);
            self::assertDirectoryExists($fixture . '/upgrade/legacy-results/' . $jobId);
            [$cutoverCheckCode, $cutoverCheckOutput] = $this->runProcess([
                'sh', $script, '--check', '--project-root', $fixture, '--prepare-cutover', $jobId,
            ]);
            self::assertSame(0, $cutoverCheckCode, $cutoverCheckOutput);

            $operationId = '029f5b62-c6f0-7f1d-9b50-7cf79f3ec3a4';
            [$adoptCode, $adoptOutput] = $this->runProcess([
                'sh', $script, '--project-root', $fixture, '--prepare-bootstrap-adopt', $operationId,
            ]);
            self::assertSame(0, $adoptCode, $adoptOutput);
            foreach (['normalization', 'import', 'target', 'host', 'recovery', 'finalize'] as $child) {
                self::assertDirectoryExists(
                    $fixture . '/upgrade/legacy-results/bootstrap-adopt/' . $operationId . '/' . $child,
                );
            }
            self::assertSame(
                02770,
                fileperms($fixture . '/upgrade/legacy-results/bootstrap-adopt/' . $operationId) & 07777,
            );
            self::assertSame(
                02770,
                fileperms($fixture . '/upgrade/legacy-results/bootstrap-adopt') & 07777,
            );
            self::assertDirectoryExists(
                $fixture . '/upgrade/bootstrap-retention/operations/' . $operationId . '/target-output',
            );
            self::assertSame(
                02770,
                fileperms($fixture . '/upgrade/bootstrap-retention/operations/' . $operationId . '/target-output') & 07777,
            );
            self::assertSame(
                fileowner($fixture . '/upgrade/bin/mallbase-agent-linux-amd64'),
                fileowner($fixture . '/upgrade/legacy-results/bootstrap-adopt/' . $operationId),
            );

            chmod($fixture . '/upgrade/bin/mallbase-agent-linux-amd64', 0755);
            file_put_contents($fixture . '/upgrade/bin/mallbase-agent-linux-amd64', 'tampered');
            [$tamperCode, $tamperOutput] = $this->runProcess(['sh', $script, '--check', '--project-root', $fixture]);
            self::assertNotSame(0, $tamperCode, $tamperOutput);
            self::assertStringContainsString('AGENT_BINARY_CHECKSUM_INVALID', $tamperOutput);
        } finally {
            $this->removeTree($fixture);
        }
    }

    public function testBusinessComposeCreateRequiresBothExactReadyAuthorityFiles(): void
    {
        $fixture = sys_get_temp_dir() . '/mallbase-ready-compose-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($fixture, 0700));
        $project = 'mallbase-ready-' . bin2hex(random_bytes(4));
        $namespace = 'mbs_ready_' . bin2hex(random_bytes(4));
        $environment = [
            'env',
            'MALLBASE_COMPOSE_PROJECT_NAME=' . $project,
            'MALLBASE_BACKEND_IMAGE_ID=alpine:3.20',
            'MALLBASE_UPGRADE_SHARED_GID=3000',
            'MALLBASE_STORAGE_NAMESPACE=' . $namespace,
        ];
        $compose = array_merge($environment, [
            'docker', 'compose', '--project-directory', $fixture,
            '--file', $fixture . '/docker-compose.yml',
        ]);

        try {
            self::assertTrue(copy($this->projectRoot . '/docker-compose.yml', $fixture . '/docker-compose.yml'));
            self::assertNotFalse(file_put_contents($fixture . '/.env', "\n"));
            foreach ([
                'upgrade/bin', 'upgrade/lifetime-locks', 'upgrade/staging', 'upgrade/config',
                'upgrade/run', 'upgrade/state', 'upgrade/jobs', 'upgrade/backups',
                'upgrade/bootstrap-retention/env', 'upgrade/bootstrap-retention/cert',
                'upgrade/bootstrap-retention/demo', 'upgrade/bootstrap-retention/public-storage',
            ] as $directory) {
                self::assertTrue(mkdir($fixture . '/' . $directory, 0770, true));
            }
            foreach (['storage-ready.pub', 'storage-ready.json'] as $file) {
                self::assertNotFalse(file_put_contents($fixture . '/upgrade/staging/' . $file, "{}\n"));
            }

            [$successCode, $successOutput] = $this->runProcess(array_merge(
                $compose,
                ['create', '--no-build', 'backend'],
            ));
            self::assertSame(0, $successCode, $successOutput);
            $this->runProcess(array_merge($compose, ['rm', '--stop', '--force', 'backend']));

            foreach (['storage-ready.pub', 'storage-ready.json'] as $missing) {
                foreach (['storage-ready.pub', 'storage-ready.json'] as $file) {
                    self::assertNotFalse(file_put_contents($fixture . '/upgrade/staging/' . $file, "{}\n"));
                }
                self::assertTrue(unlink($fixture . '/upgrade/staging/' . $missing));
                [$failureCode, $failureOutput] = $this->runProcess(array_merge(
                    $compose,
                    ['create', '--no-build', 'backend'],
                ));
                self::assertNotSame(0, $failureCode, $failureOutput);
                self::assertStringContainsString($missing, $failureOutput);
            }
        } finally {
            $this->runProcess(array_merge($compose, ['down', '--volumes', '--remove-orphans']));
            $this->removeTree($fixture);
        }
    }

    /** @return array<int, array{string,string,bool}> */
    private function upgradeMounts(): array
    {
        return [
            ['./upgrade/bin', '/app/upgrade/bin', true],
            ['./upgrade/lifetime-locks', '/app/upgrade/lifetime-locks', true],
            ['./upgrade/staging', '/app/upgrade/staging', true],
            ['./upgrade/staging/storage-ready.pub', '/app/upgrade/staging/storage-ready.pub', true],
            ['./upgrade/staging/storage-ready.json', '/app/upgrade/staging/storage-ready.json', true],
            ['./upgrade/config', '/app/upgrade/config', false],
            ['./upgrade/run', '/app/upgrade/run', false],
            ['./upgrade/state', '/app/upgrade/state', false],
            ['./upgrade/jobs', '/app/upgrade/jobs', false],
            ['./upgrade/backups', '/app/upgrade/backups', false],
        ];
    }

    /** @return array<int, array{string,string}> */
    private function retentionBridges(): array
    {
        return [
            ['./upgrade/bootstrap-retention/env', '/app/.mallbase-env'],
            ['./upgrade/bootstrap-retention/cert', '/app/storage/cert'],
            ['./upgrade/bootstrap-retention/demo', '/app/public/static/demo'],
            ['./upgrade/bootstrap-retention/public-storage', '/app/public/storage'],
        ];
    }

    private function read(string $relative): string
    {
        $content = file_get_contents($this->projectRoot . '/' . $relative);
        self::assertIsString($content);

        return $content;
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
            chmod(dirname($path), 0777);
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($path, 0777);
                rmdir($path);
            } else {
                chmod($path, 0666);
                unlink($path);
            }
        }
        chmod($root, 0777);
        rmdir($root);
    }
}
