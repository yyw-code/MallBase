<?php

declare(strict_types=1);

namespace Tests\Integration\Install;

use PHPUnit\Framework\TestCase;

final class AgentStorageCutoverContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 4);
    }

    public function testCutoverTopologyIsAnExplicitOverlayWithJobScopedImporter(): void
    {
        $default = $this->read('docker-compose.yml');
        $overlay = $this->read('docker-compose.storage-cutover.yml');

        self::assertStringNotContainsString('/app/runtime/install', $default);
        self::assertStringNotContainsString('/app/runtime/storage', $default);
        self::assertStringNotContainsString('/app/runtime/backup', $default);
        self::assertStringNotContainsString('legacy-state-import', $default);

        self::assertStringContainsString('MALLBASE_UPGRADE_JOB_ID: ${MALLBASE_UPGRADE_JOB_ID:?', $overlay);
        self::assertStringContainsString('profiles: ["storage-cutover"]', $overlay);
        self::assertStringContainsString('network_mode: none', $overlay);
        self::assertStringContainsString('read_only: true', $overlay);
        self::assertStringContainsString('cap_drop:', $overlay);
        self::assertStringContainsString('- ALL', $overlay);
        self::assertStringContainsString('- DAC_READ_SEARCH', $overlay);
        self::assertStringContainsString('- CHOWN', $overlay);
        self::assertStringContainsString('- FOWNER', $overlay);
        self::assertStringNotContainsString('DAC_OVERRIDE', $overlay);
        self::assertStringNotContainsString('/var/run/docker.sock', $overlay);
        self::assertStringContainsString('target: /app/runtime/install', $overlay);
        self::assertStringContainsString('target: /app/runtime/storage', $overlay);
        self::assertStringContainsString('target: /app/runtime/backup', $overlay);
        self::assertStringContainsString('target: /app/public/storage', $overlay);
        self::assertStringContainsString('target: /app/public/uploads', $overlay);
        self::assertStringContainsString('target: /app/storage/cert', $overlay);
        self::assertStringContainsString('target: /app/public/static/demo', $overlay);
        self::assertStringContainsString('source: ./upgrade/legacy-import/${MALLBASE_UPGRADE_JOB_ID}', $overlay);
        self::assertStringContainsString('source: ./upgrade/legacy-results/${MALLBASE_UPGRADE_JOB_ID}', $overlay);
        self::assertStringContainsString('target-state-verify:', $overlay);
        self::assertStringContainsString('target-state-verify.sh', $overlay);
        self::assertStringContainsString('MALLBASE_RUNTIME_ROLE: target-verify', $overlay);
        self::assertStringContainsString('target: /app/upgrade/state', $overlay);
        self::assertStringContainsString('target: /app/runtime/install', $overlay);
        self::assertStringNotContainsString('target: /app/upgrade/agent-private', $overlay);
        self::assertStringContainsString('- SETUID', $overlay);
        self::assertStringContainsString('- SETGID', $overlay);
        self::assertStringContainsString('create_host_path: false', $overlay);
    }

    public function testSelectionValidatorRequiresCanonicalSignatureModeJobAndPhase(): void
    {
        $validator = $this->projectRoot . '/deploy/docker/validate-storage-cutover.php';
        self::assertFileExists($validator);
        $fixture = sys_get_temp_dir() . '/mallbase-storage-selection-' . bin2hex(random_bytes(8));
        mkdir($fixture, 0700, true);
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b1';
        [$selection, $trust] = $this->writeSignedSelection($fixture, $jobId, 'export_verified');

        try {
            [$code, $output] = $this->runProcess([
                'php', $validator, 'selection-plan', $selection, $trust, $jobId, 'export_verified',
            ]);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString("selection\t{$jobId}\texport_verified\t", $output);
            self::assertStringContainsString("artifact\tinstall\tlegacy_volume\tinstall\t", $output);

            [$wrongPhaseCode, $wrongPhaseOutput] = $this->runProcess([
                'php', $validator, 'selection-plan', $selection, $trust, $jobId, 'importing',
            ]);
            self::assertNotSame(0, $wrongPhaseCode, $wrongPhaseOutput);
            self::assertStringContainsString('CUTOVER_SELECTION_PHASE_INVALID', $wrongPhaseOutput);

            chmod($selection, 0644);
            [$modeCode, $modeOutput] = $this->runProcess([
                'php', $validator, 'selection-plan', $selection, $trust, $jobId, 'export_verified',
            ]);
            self::assertNotSame(0, $modeCode, $modeOutput);
            self::assertStringContainsString('CUTOVER_SELECTION_FILE_INVALID', $modeOutput);
            chmod($selection, 0444);

            $canonical = (string) file_get_contents($selection);
            chmod($selection, 0644);
            file_put_contents($selection, str_replace(',"key_id"', ', "key_id"', $canonical));
            chmod($selection, 0444);
            [$canonicalCode, $canonicalOutput] = $this->runProcess([
                'php', $validator, 'selection-plan', $selection, $trust, $jobId, 'export_verified',
            ]);
            self::assertNotSame(0, $canonicalCode, $canonicalOutput);
            self::assertStringContainsString('CUTOVER_SELECTION_CANONICAL_INVALID', $canonicalOutput);

            chmod($selection, 0644);
            file_put_contents($selection, str_replace(
                '"labels_sha256":"sha256:' . str_repeat('b', 64) . '"',
                '"labels_sha256":"sha256:' . str_repeat('e', 64) . '"',
                $canonical,
            ));
            chmod($selection, 0444);
            [$signatureCode, $signatureOutput] = $this->runProcess([
                'php', $validator, 'selection-plan', $selection, $trust, $jobId, 'export_verified',
            ]);
            self::assertNotSame(0, $signatureCode, $signatureOutput);
            self::assertStringContainsString('CUTOVER_SELECTION_SIGNATURE_INVALID', $signatureOutput);
        } finally {
            chmod($selection, 0600);
            chmod($trust, 0600);
            $this->removeTree($fixture);
        }
    }

    public function testExportReceiptMatchesAgentCrossLanguageVector(): void
    {
        $canonical = <<<'JSON'
{"schema_version":1,"purpose":"storage_cutover_export_receipt","job_id":"018f5d35-3f42-7a31-a731-9e45df3356d1","installation_storage_namespace":"mbs_vector","main_manifest_sha256":"sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","authority_revision":7,"candidate":{"app_version":"1.3.0","deployment_id":"018f5d35-3f42-7a31-a731-9e45df3356d2","release_inventory_sha256":"sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","storage_layout_version":2,"layout_generation":2},"source_plan_sha256":"sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","artifacts":{"cert":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}},"demo":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}},"install":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}},"local_storage":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}},"public_storage":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}},"runtime_backup":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}},"uploads":{"source":{"mode":"absent","relative_path":"","volume":null,"content":{"manifest_sha256":"sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","root_sha256":"sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e","entry_count":0}}}},"receipt_sha256":"sha256:512a4f7fd9b903df6f0692d4919e9e07166032150e551bbd5cb14c90f5f6a3a6","complete":true}
JSON;
        $receipt = json_decode($canonical, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(
            $canonical,
            json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $expected = $receipt['receipt_sha256'];
        unset($receipt['receipt_sha256']);
        self::assertSame(
            'sha256:512a4f7fd9b903df6f0692d4919e9e07166032150e551bbd5cb14c90f5f6a3a6',
            $expected,
        );
        self::assertSame(
            $expected,
            'sha256:' . hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
        $fileHash = str_repeat('a', 64);
        $localizedManifest = "d\tspace dir\n"
            . "f\t3\t{$fileHash}\tspace dir/a b.txt\n"
            . "d\t目录\n"
            . "f\t3\t{$fileHash}\t目录/中文 文件.txt\n";
        self::assertSame(
            'sha256:978318f1d777fa5a7db1d24944f0d32438de0a9ac7fac245f9478ba1c85e4690',
            'sha256:' . hash('sha256', $localizedManifest),
        );
        self::assertSame(
            'sha256:40c4fc96fe7f93ff1f94ca8abde760d71539dce633c5b806758dfa444705c356',
            'sha256:' . hash('sha256', "mallbase-content-root-v1\0" . $localizedManifest),
        );
        $authorizationVector = [
            'schema_version' => 1,
            'purpose' => 'storage_cutover_selection',
            'key_id' => 'sha256:' . str_repeat('1', 64),
            'job_id' => '018f5d35-3f42-7a31-a731-9e45df3356d1',
            'installation_storage_namespace' => 'mbs_vector',
            'required_bootstrap_version' => '1.2.0',
            'main_manifest_sha256' => 'sha256:' . str_repeat('a', 64),
            'authority_revision' => 11,
            'phase' => 'provisioned',
            'database_migration_started' => true,
            'database_migration_completed' => true,
            'source' => [
                'app_version' => '1.2.0',
                'deployment_id' => '018f5d35-3f42-7a31-a731-9e45df3356d2',
                'release_inventory_sha256' => 'sha256:' . str_repeat('b', 64),
                'storage_layout_version' => 1,
                'layout_generation' => 7,
                'finalize_receipt_sha256' => 'sha256:' . str_repeat('c', 64),
            ],
            'candidate' => [
                'app_version' => '1.3.0',
                'deployment_id' => '018f5d35-3f42-7a31-a731-9e45df3356d3',
                'release_inventory_sha256' => 'sha256:' . str_repeat('d', 64),
                'storage_layout_version' => 2,
                'layout_generation' => 8,
            ],
            'source_plan_sha256' => 'sha256:' . str_repeat('e', 64),
            'export_receipt_sha256' => 'sha256:' . str_repeat('2', 64),
            'import_receipt_sha256' => 'sha256:' . str_repeat('3', 64),
            'host_inspection_sha256' => 'sha256:' . str_repeat('4', 64),
            'target_authorization_sha256' => null,
            'target_confirmation_sha256' => null,
            'promote_receipt_sha256' => null,
            'artifacts' => (object) [],
            'issued_at' => 1_783_987_200,
        ];
        self::assertSame(
            'sha256:bf442dd92f884ea21267caede7f62326e104266c54e3c25bd18aa6e5ef5aad6d',
            'sha256:' . hash(
                'sha256',
                "mallbase-storage-cutover-target-authorization-v1\0"
                    . json_encode($authorizationVector, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    public function testRecoveryWrapperNeedsNoCandidateImageOrImageReceipt(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the storage cutover contract.');
        }
        $fixture = sys_get_temp_dir() . '/mallbase-storage-recovery-' . bin2hex(random_bytes(8));
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b7';
        mkdir($fixture . '/deploy/docker', 0700, true);
        mkdir($fixture . '/upgrade/bin', 0700, true);
        mkdir($fixture . '/upgrade/staging/storage-cutover/' . $jobId, 0700, true);
        copy($this->projectRoot . '/deploy/docker/storage-cutover.sh', $fixture . '/deploy/docker/storage-cutover.sh');
        copy($this->projectRoot . '/deploy/docker/validate-storage-cutover.php', $fixture . '/deploy/docker/validate-storage-cutover.php');
        chmod($fixture . '/deploy/docker/storage-cutover.sh', 0555);
        chmod($fixture . '/deploy/docker/validate-storage-cutover.php', 0444);
        [$selection, $trust, $privateKey] = $this->writeSignedSelection($fixture, $jobId, 'recovery_required');
        rename($selection, $fixture . '/upgrade/staging/storage-cutover/' . $jobId . '/selection.json');
        rename($trust, $fixture . '/upgrade/staging/storage-ready.pub');
        $selection = $fixture . '/upgrade/staging/storage-cutover/' . $jobId . '/selection.json';
        $trust = $fixture . '/upgrade/staging/storage-ready.pub';
        chmod($selection, 0444);
        chmod($trust, 0444);
        $rolledBack = json_decode((string) file_get_contents($selection), true, 64, JSON_THROW_ON_ERROR);
        unset($rolledBack['signature']);
        $rolledBack['authority_revision'] += 1;
        $rolledBack['phase'] = 'rolled_back';
        $rolledBack['signature'] = base64_encode(sodium_crypto_sign_detached(
            json_encode($rolledBack, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            base64_decode($privateKey, true),
        ));
        $nextSelection = dirname($selection) . '/selection.next.json';
        file_put_contents($nextSelection, json_encode(
            $rolledBack,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        chmod($nextSelection, 0444);
        $agentContents = '#!/bin/sh' . "\n" . <<<'SH'
set -eu
case "$*" in
  "storage cutover inspect") printf '%s\n' '{"phase":"recovery_required"}' ;;
  "storage cutover recover-source")
    job=019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b7
    chmod 0644 "upgrade/staging/storage-cutover/$job/selection.json"
    cp "upgrade/staging/storage-cutover/$job/selection.next.json" \
       "upgrade/staging/storage-cutover/$job/selection.json"
    chmod 0444 "upgrade/staging/storage-cutover/$job/selection.json"
    printf '%s\n' '{"phase":"rolled_back"}'
    ;;
  *) exit 9 ;;
esac
SH;
        foreach (['amd64', 'arm64'] as $architecture) {
            $agent = $fixture . '/upgrade/bin/mallbase-agent-linux-' . $architecture;
            file_put_contents($agent, $agentContents);
            chmod($agent, 0555);
        }
        $agentSha256 = hash('sha256', $agentContents);
        file_put_contents(
            $fixture . '/upgrade/bin/checksums.sha256',
            $agentSha256 . '  mallbase-agent-linux-amd64' . "\n"
                . $agentSha256 . '  mallbase-agent-linux-arm64' . "\n",
        );
        chmod($fixture . '/upgrade/bin/checksums.sha256', 0444);

        try {
            [$code, $output] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none',
                '--user', '0:0',
                '--mount', 'type=bind,src=' . $fixture . ',dst=/fixture,readonly',
                '--entrypoint', 'sh', 'mallbase-backend:latest',
                '-c', 'cp -R /fixture /work && exec /work/deploy/docker/storage-cutover.sh'
                    . ' --project-root /work recover-source "$1"',
                'storage-cutover-recovery', $jobId,
            ]);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('MALLBASE_STORAGE_CUTOVER_ACTION=recover-source', $output);
            self::assertStringNotContainsString('CUTOVER_IMAGE', $output);
        } finally {
            chmod($selection, 0600);
            chmod($trust, 0600);
            chmod($nextSelection, 0600);
            $this->removeTree($fixture);
        }
    }

    public function testWrapperTreatsCommittedMutationPhasesAsIdempotentSuccess(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the storage cutover contract.');
        }

        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3c1';
        $receiptId = str_repeat('1', 32);
        $cases = [
            ['verify-export', 'export_verified', $receiptId],
            ['import', 'provisioned', $receiptId],
            ['target-verify', 'target_confirmed', $receiptId],
            ['promote', 'promoted', null],
            ['rollback', 'rolled_back', null],
            ['recover-source', 'rolled_back', null],
        ];

        foreach ($cases as [$action, $phase, $receipt]) {
            $fixture = sys_get_temp_dir() . '/mallbase-storage-wrapper-' . $action . '-' . bin2hex(random_bytes(6));
            $this->createWrapperFixture($fixture, $jobId, $phase);
            try {
                [$code, $output] = $this->runWrapperFixture($fixture, $action, $jobId, $receipt);
                self::assertSame(0, $code, $action . "\n" . $output);
                self::assertStringContainsString('MALLBASE_STORAGE_CUTOVER_ACTION=' . $action, $output);
                self::assertStringNotContainsString('MUTATION:', $output, $action);
                self::assertStringNotContainsString('COMPOSE:', $output, $action);
                self::assertStringNotContainsString('DOCKER_RUN', $output, $action);
            } finally {
                $this->removeTree($fixture);
            }
        }
    }

    public function testImportWrapperResumesImportingWithoutRepeatingBeginMutation(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the storage cutover contract.');
        }

        $fixture = sys_get_temp_dir() . '/mallbase-storage-wrapper-importing-' . bin2hex(random_bytes(6));
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3c2';
        $this->createWrapperFixture($fixture, $jobId, 'importing');
        try {
            [$code, $output] = $this->runWrapperFixture(
                $fixture,
                'import',
                $jobId,
                str_repeat('2', 32),
            );
            self::assertNotSame(0, $code, $output);
            self::assertStringContainsString('COMPOSE:', $output);
            self::assertStringContainsString('legacy-state-import import', $output);
            self::assertStringNotContainsString('MUTATION:storage cutover begin-import', $output);
        } finally {
            $this->removeTree($fixture);
        }
    }

    public function testRealAgentWrapperAcceptsRolledBackCommitAfterProcessRestart(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the storage cutover contract.');
        }

        $fixture = sys_get_temp_dir() . '/mallbase-storage-real-agent-reentry-' . bin2hex(random_bytes(6));
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3c4';
        $this->createWrapperFixture($fixture, $jobId, 'rolled_back');
        $this->replaceFixtureWithRealAgentAuthority($fixture, $jobId);
        try {
            [$code, $output] = $this->runWrapperFixture($fixture, 'rollback', $jobId);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('MALLBASE_STORAGE_CUTOVER_ACTION=rollback', $output);
            self::assertStringNotContainsString('MUTATION:', $output);
        } finally {
            chmod($fixture . '/upgrade/bin', 0755);
            $this->removeTree($fixture);
        }
    }

    public function testWrapperRejectsCallerWhoDoesNotOwnAgentBinary(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the storage cutover contract.');
        }

        $fixture = sys_get_temp_dir() . '/mallbase-storage-wrapper-owner-' . bin2hex(random_bytes(6));
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3c3';
        $this->createWrapperFixture($fixture, $jobId, 'export_verified');
        try {
            [$code, $output] = $this->runWrapperFixture($fixture, 'inspect', $jobId, null, true);
            self::assertNotSame(0, $code, $output);
            self::assertStringContainsString('CUTOVER_CALLER_IDENTITY_INVALID', $output);
        } finally {
            $this->removeTree($fixture);
        }
    }

    public function testVerifyExportWritesPrivateReceiptWithoutTouchingNamedTargets(): void
    {
        if (!$this->dockerAvailable()) {
            self::markTestSkipped('Docker daemon is required for the storage cutover contract.');
        }

        $suffix = bin2hex(random_bytes(6));
        $fixture = sys_get_temp_dir() . '/mallbase-storage-export-' . $suffix;
        $jobId = '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b6';
        $sharedGid = 23456;
        $legacyVolume = 'mallbase-legacy-runtime-' . $suffix;
        $resultVolume = 'mallbase-cutover-result-' . $suffix;
        $targetVolumes = [];
        foreach (['cert', 'demo', 'install', 'local_storage', 'public_storage', 'runtime_backup', 'uploads'] as $artifact) {
            $targetVolumes[$artifact] = 'mallbase-target-' . str_replace('_', '-', $artifact) . '-' . $suffix;
        }
        mkdir($fixture . '/input/cert', 0700, true);
        mkdir($fixture . '/input/demo', 0700, true);
        mkdir($fixture . '/input/demo/space dir', 0700, true);
        mkdir($fixture . '/input/demo/目录', 0700, true);
        mkdir($fixture . '/input/public-storage', 0700, true);
        mkdir($fixture . '/input/uploads', 0700, true);
        file_put_contents($fixture . '/input/cert/cert.pem', 'certificate');
        file_put_contents($fixture . '/input/demo/demo.txt', 'demo');
        file_put_contents($fixture . '/input/demo/space dir/a b.txt', 'abc');
        file_put_contents($fixture . '/input/demo/目录/中文 文件.txt', 'abc');
        file_put_contents($fixture . '/input/public-storage/public.txt', 'public');
        file_put_contents($fixture . '/input/uploads/upload.txt', 'upload');
        chmod($fixture . '/input', 0700);
        [$selection, $trust, $privateKey] = $this->writeSignedSelection($fixture, $jobId, 'prepared');
        [$selectionCode, $selectionOutput] = $this->runProcess([
            'php', $this->projectRoot . '/deploy/docker/validate-storage-cutover.php',
            'selection-plan', $selection, $trust, $jobId, 'prepared',
        ]);
        self::assertSame(0, $selectionCode, $selectionOutput);

        $volumes = [$legacyVolume, $resultVolume, ...array_values($targetVolumes)];
        try {
            foreach ($volumes as $volume) {
                [$code, $output] = $this->runProcess(['docker', 'volume', 'create', $volume]);
                self::assertSame(0, $code, $output);
            }
            [$code, $output] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL', '--cap-add', 'CHOWN',
                '--mount', 'type=volume,src=' . $legacyVolume . ',dst=/legacy',
                'alpine:3.20', 'sh', '-c',
                'mkdir -p /legacy/install /legacy/storage /legacy/backup'
                . ' && printf installed > /legacy/install/install.lock'
                . ' && printf local > /legacy/storage/local.txt'
                . ' && printf backup > /legacy/backup/backup.txt'
                . ' && chmod -R 0700 /legacy',
            ]);
            self::assertSame(0, $code, $output);
            foreach ([$resultVolume, ...array_values($targetVolumes)] as $volume) {
                [$code, $output] = $this->runProcess([
                    'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'FOWNER',
                    '--mount', 'type=volume,src=' . $volume . ',dst=/target',
                    'alpine:3.20', 'sh', '-c', 'chown 0:' . $sharedGid . ' /target && chmod 3770 /target',
                ]);
                self::assertSame(0, $code, $output);
            }

            [$code, $output] = $this->runCutoverHelper(
                'verify-export',
                $jobId,
                $sharedGid,
                $selection,
                $trust,
                $fixture . '/input',
                $legacyVolume,
                $resultVolume,
                $targetVolumes,
            );
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('CUTOVER_EXPORT_VERIFIED', $output);

            [$code, $receiptOutput] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                '--user', '12345:' . $sharedGid,
                '--mount', 'type=volume,src=' . $resultVolume . ',dst=/result,readonly',
                'alpine:3.20', 'sh', '-c',
                'stat -c "%u:%g:%a" /result/export'
                . ' && stat -c "%u:%g:%a" /result/export/manifests'
                . ' && stat -c "%u:%g:%a" /result/export/receipt.json'
                . ' && cat /result/export/receipt.json',
            ]);
            self::assertSame(0, $code, $receiptOutput);
            [$exportMode, $manifestsMode, $receiptMode, $receiptJson] = explode("\n", $receiptOutput, 4);
            self::assertSame('12345:' . $sharedGid . ':2770', $exportMode);
            self::assertSame('12345:' . $sharedGid . ':2770', $manifestsMode);
            self::assertSame('12345:' . $sharedGid . ':640', $receiptMode);
            $receipt = json_decode($receiptJson, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame([
                'schema_version', 'purpose', 'job_id', 'installation_storage_namespace',
                'main_manifest_sha256', 'authority_revision', 'candidate', 'source_plan_sha256',
                'artifacts', 'receipt_sha256', 'complete',
            ], array_keys($receipt));
            self::assertSame('storage_cutover_export_receipt', $receipt['purpose'] ?? null);
            self::assertSame($jobId, $receipt['job_id'] ?? null);
            self::assertCount(7, $receipt['artifacts'] ?? []);
            $selfHash = $receipt['receipt_sha256'];
            unset($receipt['receipt_sha256']);
            self::assertSame(
                'sha256:' . hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $selfHash,
            );
            self::assertSame(
                ['source'],
                array_keys($receipt['artifacts']['install'] ?? []),
            );
            self::assertSame(
                ['mode', 'relative_path', 'volume', 'content'],
                array_keys($receipt['artifacts']['install']['source'] ?? []),
            );
            $installManifest = "f\t9\t" . hash('sha256', 'installed') . "\tinstall.lock\n";
            self::assertSame(
                'sha256:' . hash('sha256', "mallbase-content-root-v1\0" . $installManifest),
                $receipt['artifacts']['install']['source']['content']['root_sha256'] ?? null,
            );

            foreach ($targetVolumes as $volume) {
                [$code, $targetOutput] = $this->runProcess([
                    'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                    '--mount', 'type=volume,src=' . $volume . ',dst=/target,readonly',
                    'alpine:3.20', 'sh', '-c', 'test -z "$(find /target -mindepth 1 -print -quit)"',
                ]);
                self::assertSame(0, $code, $targetOutput);
            }

            $this->advanceSelectionToImporting($selection, $privateKey, $receipt, $selfHash);
            $copyShim = $fixture . '/cp';
            file_put_contents($copyShim, <<<'SH'
#!/bin/sh
case "${2-}" in
    */.mallbase-import-*.tmp.*)
        head -c 3 "$1" > "$2"
        kill -KILL "$PPID"
        exit 137
        ;;
    *) exec /bin/cp "$@" ;;
esac
SH);
            chmod($copyShim, 0555);
            [$crashCode, $crashOutput] = $this->runCutoverHelper(
                'import',
                $jobId,
                $sharedGid,
                $selection,
                $trust,
                $fixture . '/input',
                $legacyVolume,
                $resultVolume,
                $targetVolumes,
                $copyShim,
            );
            self::assertNotSame(0, $crashCode, $crashOutput);

            [$code, $output] = $this->runCutoverHelper(
                'import',
                $jobId,
                $sharedGid,
                $selection,
                $trust,
                $fixture . '/input',
                $legacyVolume,
                $resultVolume,
                $targetVolumes,
            );
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('CUTOVER_IMPORT_COMPLETE', $output);

            [$code, $legacyOutput] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                '--mount', 'type=volume,src=' . $legacyVolume . ',dst=/legacy,readonly',
                'alpine:3.20', 'cat', '/legacy/install/install.lock',
            ]);
            self::assertSame(0, $code, $legacyOutput);
            self::assertSame('installed', $legacyOutput);

            [$code, $targetOutput] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                '--user', '10000:' . $sharedGid,
                '--mount', 'type=volume,src=' . $targetVolumes['install'] . ',dst=/target,readonly',
                'alpine:3.20', 'sh', '-c',
                'stat -c "%u:%g:%a" /target'
                . ' && stat -c "%u:%g:%a" /target/install.lock'
                . ' && stat -c "%u:%g:%a" /target/.mallbase-layout-marker.json'
                . ' && cat /target/install.lock',
            ]);
            self::assertSame(0, $code, $targetOutput);
            self::assertSame(
                "0:{$sharedGid}:3770\n10000:{$sharedGid}:660\n0:{$sharedGid}:444\ninstalled",
                $targetOutput,
            );

            [$code, $importOutput] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                '--user', '12345:' . $sharedGid,
                '--mount', 'type=volume,src=' . $resultVolume . ',dst=/result,readonly',
                'alpine:3.20', 'sh', '-c',
                'stat -c "%u:%g:%a" /result/import'
                . ' && stat -c "%u:%g:%a" /result/import/progress'
                . ' && stat -c "%u:%g:%a" /result/import/done'
                . ' && stat -c "%u:%g:%a" /result/import/receipt.json'
                . ' && cat /result/import/receipt.json',
            ]);
            self::assertSame(0, $code, $importOutput);
            [$importDirMode, $progressMode, $doneMode, $importMode, $importJson] = explode("\n", $importOutput, 5);
            self::assertSame('12345:' . $sharedGid . ':2770', $importDirMode);
            self::assertSame('12345:' . $sharedGid . ':2770', $progressMode);
            self::assertSame('12345:' . $sharedGid . ':2770', $doneMode);
            self::assertSame('12345:' . $sharedGid . ':640', $importMode);
            $importReceipt = json_decode($importJson, true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('storage_cutover_import_receipt', $importReceipt['purpose'] ?? null);
            self::assertSame($selfHash, $importReceipt['export_receipt_sha256'] ?? null);
            self::assertCount(7, $importReceipt['artifacts'] ?? []);
            $importSelfHash = $importReceipt['receipt_sha256'];
            unset($importReceipt['receipt_sha256']);
            self::assertSame(
                'sha256:' . hash('sha256', json_encode($importReceipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $importSelfHash,
            );

            [$code, $inspectJson] = $this->runProcess(['docker', 'volume', 'inspect', $targetVolumes['cert']]);
            self::assertSame(0, $code, $inspectJson);
            $dockerIdentity = $this->dockerVolumeIdentity($inspectJson);
            $dockerInspectPath = $fixture . '/docker-inspect.json';
            file_put_contents($dockerInspectPath, $inspectJson);
            [$code, $identityOutput] = $this->runProcess([
                'php', $this->projectRoot . '/deploy/docker/validate-storage-cutover.php',
                'docker-volume-identity', $dockerInspectPath,
                $dockerIdentity['volume_name'], $dockerIdentity['docker_volume_id'], $dockerIdentity['labels_sha256'],
            ]);
            self::assertSame(0, $code, $identityOutput);
            self::assertSame(implode("\t", $dockerIdentity) . "\n", $identityOutput);

            $observations = $fixture . '/host-observations.tsv';
            $this->writeHostObservationsFromSelection($selection, $observations);
            $importReceiptPath = $fixture . '/import-receipt.json';
            file_put_contents($importReceiptPath, $importJson);
            $hostInspection = $fixture . '/host-inspection.json';
            [$code, $hostOutput] = $this->runProcess([
                'php', $this->projectRoot . '/deploy/docker/validate-storage-cutover.php',
                'write-host-inspection', $selection, $trust, $jobId,
                $importReceiptPath,
                $observations, $hostInspection,
            ]);
            self::assertSame(0, $code, $hostOutput);
            $inspection = json_decode((string) file_get_contents($hostInspection), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('storage_cutover_host_inspection', $inspection['purpose'] ?? null);
            self::assertSame($importSelfHash, $inspection['import_receipt_sha256'] ?? null);
            $inspectionHash = $inspection['inspection_sha256'];
            unset($inspection['inspection_sha256']);
            self::assertSame(
                'sha256:' . hash('sha256', json_encode($inspection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $inspectionHash,
            );

            [$targetSelection, $targetAuthorization] = $this->advanceSelectionToProvisioned(
                $selection,
                $privateKey,
                $importReceipt,
                $importSelfHash,
                $inspectionHash,
            );
            $tamperedSelection = $fixture . '/selection-provisioned-tampered.json';
            $tampered = json_decode((string) file_get_contents($targetSelection), true, 64, JSON_THROW_ON_ERROR);
            unset($tampered['signature']);
            ++$tampered['issued_at'];
            $tampered['signature'] = base64_encode(sodium_crypto_sign_detached(
                json_encode($tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                base64_decode($privateKey, true),
            ));
            file_put_contents($tamperedSelection, json_encode(
                $tampered,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            chmod($tamperedSelection, 0444);
            [$tamperedCode, $tamperedOutput] = $this->runProcess([
                'php', $this->projectRoot . '/deploy/docker/validate-storage-cutover.php',
                'selection-plan', $tamperedSelection, $trust, $jobId, 'provisioned',
            ]);
            self::assertNotSame(0, $tamperedCode, $tamperedOutput);
            self::assertStringContainsString('CUTOVER_TARGET_AUTHORIZATION_INVALID', $tamperedOutput);
            $versionPath = $fixture . '/candidate.version';
            file_put_contents($versionPath, json_encode([
                'version' => '1.3.0',
                'released_at' => '2026-07-14 00:00:00',
                'notes' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            chmod($versionPath, 0444);
            $deploymentPath = $fixture . '/candidate.deployment.json';
            file_put_contents($deploymentPath, json_encode([
                'schema_version' => 1,
                'provenance_kind' => 'upgrade',
                'job_id' => $jobId,
                'main_manifest_sha256' => 'sha256:' . str_repeat('a', 64),
                'app_version' => '1.3.0',
                'deployment_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b3',
                'release_inventory_sha256' => 'sha256:' . str_repeat('a', 64),
                'storage_layout_version' => 2,
                'storage_layout_generation' => 2,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            chmod($deploymentPath, 0444);
            $snapshot = $fixture . '/target-snapshot.json';
            file_put_contents($snapshot, json_encode([
                'schema_version' => 1,
                'purpose' => 'storage_cutover_php_target_snapshot',
                'job_id' => $jobId,
                'gate_state' => 'awaiting_deployment',
                'gate_revision' => 11,
                'required_runtime' => [
                    'app_version' => '1.3.0',
                    'deployment_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b3',
                    'storage_layout_version' => 2,
                    'layout_generation' => 2,
                ],
                'maintenance_fenced' => true,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
            chmod($snapshot, 0444);
            $thinkStub = $fixture . '/think';
            file_put_contents($thinkStub, <<<'PHP'
<?php
if ($argc !== 3 || $argv[1] !== 'upgrade:storage-cutover-target-snapshot'
    || !str_starts_with($argv[2], '--job-id=')) {
    exit(2);
}
fwrite(STDOUT, (string) file_get_contents('/cutover/target-snapshot-fixture.json'));
PHP);
            chmod($thinkStub, 0555);
            [$code, $targetVerifyOutput] = $this->runTargetVerifier(
                $jobId,
                $sharedGid,
                $targetSelection,
                $trust,
                $resultVolume,
                $targetVolumes,
                $versionPath,
                $deploymentPath,
                $snapshot,
                $thinkStub,
            );
            self::assertSame(0, $code, $targetVerifyOutput);
            self::assertStringContainsString('CUTOVER_TARGET_VERIFIED', $targetVerifyOutput);
            [$code, $targetReceiptOutput] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                '--user', '12345:' . $sharedGid,
                '--mount', 'type=volume,src=' . $resultVolume . ',dst=/result,readonly',
                'alpine:3.20', 'sh', '-c',
                'stat -c "%u:%g:%a" /result/target'
                . ' && stat -c "%u:%g:%a" /result/target/verification.json'
                . ' && cat /result/target/verification.json',
            ]);
            self::assertSame(0, $code, $targetReceiptOutput);
            [$targetDirMode, $targetReceiptMode, $targetReceiptJson] = explode("\n", $targetReceiptOutput, 3);
            self::assertSame('12345:' . $sharedGid . ':2770', $targetDirMode);
            self::assertSame('12345:' . $sharedGid . ':640', $targetReceiptMode);
            $targetReceipt = json_decode($targetReceiptJson, true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('storage_cutover_target_verification', $targetReceipt['purpose'] ?? null);
            self::assertSame($targetAuthorization, $targetReceipt['target_authorization_sha256'] ?? null);
            self::assertTrue($targetReceipt['target_only_gate'] ?? false);
            self::assertTrue($targetReceipt['maintenance_fenced'] ?? false);
            $verificationHash = $targetReceipt['verification_sha256'];
            unset($targetReceipt['verification_sha256']);
            self::assertSame(
                'sha256:' . hash('sha256', json_encode($targetReceipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $verificationHash,
            );

            [$code, $output] = $this->runCutoverHelper(
                'import',
                $jobId,
                $sharedGid,
                $selection,
                $trust,
                $fixture . '/input',
                $legacyVolume,
                $resultVolume,
                $targetVolumes,
            );
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('CUTOVER_IMPORT_COMPLETE', $output);

            [$code, $output] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none', '--cap-drop', 'ALL',
                '--user', '10000:' . $sharedGid,
                '--mount', 'type=volume,src=' . $targetVolumes['install'] . ',dst=/target',
                'alpine:3.20', 'sh', '-c', 'printf conflict > /target/install.lock',
            ]);
            self::assertSame(0, $code, $output);
            [$conflictCode, $conflictOutput] = $this->runCutoverHelper(
                'import',
                $jobId,
                $sharedGid,
                $selection,
                $trust,
                $fixture . '/input',
                $legacyVolume,
                $resultVolume,
                $targetVolumes,
            );
            self::assertNotSame(0, $conflictCode, $conflictOutput);
            self::assertStringContainsString('CUTOVER_TARGET_CONFLICT', $conflictOutput);

            [$code, $output] = $this->runProcess([
                'docker', 'run', '--rm', '--network', 'none',
                '--mount', 'type=volume,src=' . $resultVolume . ',dst=/result',
                'alpine:3.20', 'sh', '-c',
                'rm /result/import/progress/cert.json'
                . ' && ln -s /input/missing-canary /result/import/progress/cert.json',
            ]);
            self::assertSame(0, $code, $output);
            [$symlinkCode, $symlinkOutput] = $this->runCutoverHelper(
                'import',
                $jobId,
                $sharedGid,
                $selection,
                $trust,
                $fixture . '/input',
                $legacyVolume,
                $resultVolume,
                $targetVolumes,
            );
            self::assertNotSame(0, $symlinkCode, $symlinkOutput);
            self::assertStringContainsString('CUTOVER_RESULT_CONFLICT', $symlinkOutput);
            self::assertFileDoesNotExist($fixture . '/input/missing-canary');
        } finally {
            foreach ($volumes as $volume) {
                $this->runProcess(['docker', 'volume', 'rm', '--force', $volume]);
            }
            chmod($selection, 0600);
            chmod($trust, 0600);
            $this->removeTree($fixture);
        }
    }

    private function dockerAvailable(): bool
    {
        [$code] = $this->runProcess(['docker', 'info', '--format', '{{.ServerVersion}}']);

        return $code === 0;
    }

    private function createWrapperFixture(string $fixture, string $jobId, string $phase): void
    {
        mkdir($fixture . '/deploy/docker', 0700, true);
        mkdir($fixture . '/upgrade/bin', 0700, true);
        mkdir($fixture . '/upgrade/staging/storage-cutover/' . $jobId, 0700, true);
        mkdir($fixture . '/tools', 0700, true);
        copy($this->projectRoot . '/deploy/docker/storage-cutover.sh', $fixture . '/deploy/docker/storage-cutover.sh');
        copy($this->projectRoot . '/deploy/docker/validate-storage-cutover.php', $fixture . '/deploy/docker/validate-storage-cutover.php');
        chmod($fixture . '/deploy/docker/storage-cutover.sh', 0555);
        chmod($fixture . '/deploy/docker/validate-storage-cutover.php', 0444);
        file_put_contents($fixture . '/docker-compose.yml', "services: {}\n");
        file_put_contents($fixture . '/docker-compose.storage-cutover.yml', "services: {}\n");
        chmod($fixture . '/docker-compose.yml', 0444);
        chmod($fixture . '/docker-compose.storage-cutover.yml', 0444);

        [$selection, $trust, $privateKey] = $this->writeSignedSelection($fixture, $jobId, 'export_verified');
        $this->rewriteWrapperSelectionPhase($selection, $privateKey, $phase);
        rename($selection, $fixture . '/upgrade/staging/storage-cutover/' . $jobId . '/selection.json');
        rename($trust, $fixture . '/upgrade/staging/storage-ready.pub');

        $agentContents = <<<'SH'
#!/bin/sh
set -eu
case "$*" in
  "seal-build-context verify-image-receipt")
    cat >/dev/null
    printf '%s\n' '{}'
    ;;
  "storage cutover inspect")
    printf '%s\n' '{"phase":"fixture"}'
    ;;
  "storage cutover "*)
    printf 'MUTATION:%s\n' "$*" >> "$PWD/agent.log"
    exit 91
    ;;
  *) exit 92 ;;
esac
SH;
        foreach (['amd64', 'arm64'] as $architecture) {
            $agent = $fixture . '/upgrade/bin/mallbase-agent-linux-' . $architecture;
            file_put_contents($agent, $agentContents);
            chmod($agent, 0555);
        }
        $agentSha256 = hash('sha256', $agentContents);
        file_put_contents(
            $fixture . '/upgrade/bin/checksums.sha256',
            $agentSha256 . '  mallbase-agent-linux-amd64' . "\n"
                . $agentSha256 . '  mallbase-agent-linux-arm64' . "\n",
        );
        chmod($fixture . '/upgrade/bin/checksums.sha256', 0444);

        file_put_contents($fixture . '/deploy/docker/host-preflight.sh', "#!/bin/sh\nexit 0\n");
        chmod($fixture . '/deploy/docker/host-preflight.sh', 0555);
        file_put_contents($fixture . '/deploy/docker/validate-sealed-attestation.php', <<<'PHP'
<?php
$image = 'sha256:' . str_repeat('a', 64);
if (($argv[1] ?? '') === 'image-receipt-field' || ($argv[1] ?? '') === 'oci-id') {
    fwrite(STDOUT, $image . PHP_EOL);
    exit(0);
}
fwrite(STDERR, "ATTESTATION_FIXTURE_COMMAND_INVALID\n");
exit(1);
PHP);
        chmod($fixture . '/deploy/docker/validate-sealed-attestation.php', 0444);

        file_put_contents($fixture . '/tools/docker', <<<'SH'
#!/bin/sh
set -eu
if [ "${1:-}" = image ] && [ "${2:-}" = inspect ]; then
    printf '%s\n' 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    exit 0
fi
if [ "${1:-}" = run ]; then
    printf '%s\n' 'DOCKER_RUN' >> /work/docker.log
    exit 0
fi
if [ "${1:-}" = compose ]; then
    printf 'COMPOSE:%s\n' "$*" >> /work/docker.log
    exit 93
fi
exit 94
SH);
        chmod($fixture . '/tools/docker', 0555);
    }

    private function replaceFixtureWithRealAgentAuthority(string $fixture, string $jobId): void
    {
        foreach (['amd64', 'arm64'] as $architecture) {
            $target = $fixture . '/upgrade/bin/mallbase-agent-linux-' . $architecture;
            chmod($target, 0644);
            copy($this->projectRoot . '/upgrade/bin/mallbase-agent-linux-' . $architecture, $target);
            chmod($target, 0555);
        }
        $manifest = $fixture . '/upgrade/bin/checksums.sha256';
        chmod($manifest, 0644);
        copy($this->projectRoot . '/upgrade/bin/checksums.sha256', $manifest);
        chmod($manifest, 0444);

        chmod($fixture, 0755);
        chmod($fixture . '/upgrade', 0750);
        chmod($fixture . '/upgrade/bin', 0555);
        chmod($fixture . '/upgrade/staging', 0750);
        foreach (['agent-private', 'bootstrap-retention', 'storage-init-results', 'legacy-import', 'legacy-results'] as $directory) {
            $path = $fixture . '/upgrade/' . $directory;
            if (!is_dir($path)) {
                mkdir($path, 0700);
            }
            chmod($path, 0700);
        }

        $selectionPath = $fixture . '/upgrade/staging/storage-cutover/' . $jobId . '/selection.json';
        $selection = json_decode((string) file_get_contents($selectionPath), true, 64, JSON_THROW_ON_ERROR);
        $hashA = 'sha256:' . str_repeat('a', 64);
        $hashB = 'sha256:' . str_repeat('b', 64);
        $sourceVolumes = [];
        $candidateVolumes = [];
        $artifacts = [];
        $index = 0;
        foreach ($selection['artifacts'] as $artifact => $plan) {
            $sourceVolume = [
                'artifact' => $artifact,
                'source_mode' => 'fresh',
                'volume_name' => 'mbs_source_' . $artifact,
                'docker_volume_id' => 'docker:sha256:' . str_repeat(dechex(8 + $index), 64),
                'labels_sha256' => $hashA,
                'marker_id' => sprintf('019f5b62-c6f0-7f1d-9b50-%012x', 32 + $index),
                'marker_sha256' => $hashB,
                'empty_at_prepare' => true,
            ];
            $targetVolume = $plan['target']['volume'];
            $candidateVolumes[$artifact] = [
                'artifact' => $artifact,
                'source_mode' => 'candidate',
                'volume_name' => $targetVolume['volume_name'],
                'docker_volume_id' => $targetVolume['docker_volume_id'],
                'labels_sha256' => $targetVolume['labels_sha256'],
                'marker_id' => $targetVolume['marker_id'],
                'marker_sha256' => $targetVolume['marker_sha256'],
                'empty_at_prepare' => true,
            ];
            $sourceVolumes[$artifact] = $sourceVolume;
            $cutoverSourceVolume = null;
            if (in_array($plan['source']['mode'], ['legacy_volume', 'already_namespaced'], true)) {
                $cutoverSourceVolume = [
                    'volume_name' => $sourceVolume['volume_name'],
                    'docker_volume_id' => $sourceVolume['docker_volume_id'],
                    'labels_sha256' => $sourceVolume['labels_sha256'],
                    'marker_id' => $sourceVolume['marker_id'],
                    'marker_sha256' => $sourceVolume['marker_sha256'],
                ];
            }
            $artifacts[$artifact] = [
                'source' => [
                    'mode' => $plan['source']['mode'],
                    'relative_path' => $plan['source']['relative_path'],
                    'volume' => $cutoverSourceVolume,
                    'content' => $plan['source']['content'],
                ],
                'target' => [
                    'volume' => $targetVolume,
                    'policy' => $plan['target']['policy'],
                    'content' => null,
                ],
            ];
            ++$index;
        }

        $source = [
            'layout_version' => 1,
            'layout_generation' => 1,
            'app_version' => $selection['source']['app_version'],
            'deployment_id' => $selection['source']['deployment_id'],
            'release_inventory_sha256' => $selection['source']['release_inventory_sha256'],
            'finalize_receipt_sha256' => $selection['source']['finalize_receipt_sha256'],
            'ready_projection_issued_at' => 1_783_785_600,
            'boot_eligible' => true,
            'volumes' => $sourceVolumes,
        ];
        $candidate = [
            'layout_version' => 2,
            'layout_generation' => 2,
            'app_version' => $selection['candidate']['app_version'],
            'deployment_id' => $selection['candidate']['deployment_id'],
            'release_inventory_sha256' => $selection['candidate']['release_inventory_sha256'],
            'boot_eligible' => false,
            'volumes' => $candidateVolumes,
        ];
        $fresh = [
            'operation_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3d1',
            'layout_generation' => 1,
            'frozen_prepare_sha256' => $hashA,
            'init_operation_receipt_sha256' => $hashB,
            'finalize_evidence_sha256' => $hashA,
        ];
        $bootstrapAuthoritySha256 = $this->goCanonicalHash([
            'fresh' => $fresh,
            'source' => $source,
        ]);
        $staticArtifacts = [];
        foreach ($artifacts as $artifact => $plan) {
            $staticArtifacts[$artifact] = [
                'source' => [
                    'mode' => $plan['source']['mode'],
                    'relative_path' => $plan['source']['relative_path'],
                    'volume' => $plan['source']['volume'],
                ],
                'target' => [
                    'volume' => $plan['target']['volume'],
                    'policy' => $plan['target']['policy'],
                ],
            ];
        }
        $sourcePlanSha256 = $this->goCanonicalHash([
            'job_id' => $jobId,
            'required_bootstrap_version' => $selection['required_bootstrap_version'],
            'main_manifest_sha256' => $selection['main_manifest_sha256'],
            'source' => $source,
            'candidate' => [
                'app_version' => $candidate['app_version'],
                'deployment_id' => $candidate['deployment_id'],
                'release_inventory_sha256' => $candidate['release_inventory_sha256'],
                'storage_layout_version' => $candidate['layout_version'],
                'layout_generation' => $candidate['layout_generation'],
            ],
            'artifacts' => $staticArtifacts,
        ]);
        $cutover = [
            'job_id' => $jobId,
            'required_bootstrap_version' => $selection['required_bootstrap_version'],
            'main_manifest_sha256' => $selection['main_manifest_sha256'],
            'prepared_authority_revision' => 8,
            'phase' => 'rolled_back',
            'source' => $source,
            'candidate' => $candidate,
            'bootstrap_authority_sha256' => $bootstrapAuthoritySha256,
            'source_plan_sha256' => $sourcePlanSha256,
            'export_receipt_sha256' => $selection['export_receipt_sha256'],
            'database_migration_started' => false,
            'database_migration_completed' => false,
            'projection_issued_at' => 1_783_785_600,
            'artifacts' => $artifacts,
        ];
        $layout = [
            'schema_version' => 1,
            'installation_storage_namespace' => $selection['installation_storage_namespace'],
            'authority_revision' => 8,
            'next_layout_generation' => 3,
            'state' => 'ready',
            'active' => $source,
            'fresh' => $fresh,
            'cutover' => $cutover,
        ];
        $authority = $fixture . '/upgrade/agent-private/storage-layout.json';
        file_put_contents($authority, json_encode($layout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($authority, 0600);
    }

    /** @param array<string,mixed> $value */
    private function goCanonicalHash(array $value): string
    {
        return 'sha256:' . hash(
            'sha256',
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function rewriteWrapperSelectionPhase(string $selectionPath, string $privateKey, string $phase): void
    {
        $selection = json_decode((string) file_get_contents($selectionPath), true, 64, JSON_THROW_ON_ERROR);
        unset($selection['signature']);
        $selection['phase'] = $phase;
        if (in_array($phase, ['importing', 'provisioned', 'target_confirmed', 'promoted'], true)) {
            $selection['database_migration_started'] = true;
            $selection['database_migration_completed'] = true;
        }
        if (in_array($phase, ['provisioned', 'target_confirmed', 'promoted'], true)) {
            $selection['import_receipt_sha256'] = 'sha256:' . str_repeat('c', 64);
            $selection['host_inspection_sha256'] = 'sha256:' . str_repeat('d', 64);
            foreach (array_keys($selection['artifacts']) as $artifact) {
                $selection['artifacts'][$artifact]['target']['content'] =
                    $selection['artifacts'][$artifact]['source']['content'];
            }
            $selection['target_authorization_sha256'] = null;
            $selection['target_authorization_sha256'] = 'sha256:' . hash(
                'sha256',
                "mallbase-storage-cutover-target-authorization-v1\0"
                    . json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        }
        if (in_array($phase, ['target_confirmed', 'promoted'], true)) {
            $selection['target_confirmation_sha256'] = 'sha256:' . str_repeat('e', 64);
        }
        if ($phase === 'promoted') {
            $selection['promote_receipt_sha256'] = 'sha256:' . str_repeat('f', 64);
        }
        $selection['signature'] = base64_encode(sodium_crypto_sign_detached(
            json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            base64_decode($privateKey, true),
        ));
        chmod($selectionPath, 0644);
        file_put_contents(
            $selectionPath,
            json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        chmod($selectionPath, 0444);
    }

    /** @return array{int,string} */
    private function runWrapperFixture(
        string $fixture,
        string $action,
        string $jobId,
        ?string $receiptId = null,
        bool $mismatchOwner = false,
    ): array {
        $command = [
            'docker', 'run', '--rm', '--network', 'none', '--user', '0:0',
            '--mount', 'type=bind,src=' . $fixture . ',dst=/fixture,readonly',
        ];
        if ($mismatchOwner) {
            $command = [...$command, '-e', 'MISMATCH_OWNER=1'];
        }
        $command = [
            ...$command,
            '--entrypoint', 'sh', 'mallbase-backend:latest', '-c',
            'cp -R /fixture /work'
                . ' && if [ "${MISMATCH_OWNER:-0}" = 1 ]; then '
                . 'case "$(uname -m)" in x86_64|amd64) arch=amd64 ;; *) arch=arm64 ;; esac; '
                . 'chown 12345:0 "/work/upgrade/bin/mallbase-agent-linux-$arch"; fi; '
                . 'PATH=/work/tools:$PATH; export PATH; set +e; '
                . '/work/deploy/docker/storage-cutover.sh "$@"; code=$?; '
                . '[ ! -f /work/agent.log ] || cat /work/agent.log; '
                . '[ ! -f /work/docker.log ] || cat /work/docker.log; exit "$code"',
            'storage-cutover-wrapper', '--project-root', '/work', $action, $jobId,
        ];
        if ($receiptId !== null) {
            $command[] = $receiptId;
        }

        return $this->runProcess($command);
    }

    /**
     * @param array<string,string> $targetVolumes
     * @return array{int,string}
     */
    private function runCutoverHelper(
        string $action,
        string $jobId,
        int $sharedGid,
        string $selection,
        string $trust,
        string $input,
        string $legacyVolume,
        string $resultVolume,
        array $targetVolumes,
        ?string $copyShim = null,
    ): array {
        $command = [
            'docker', 'run', '--rm', '--network', 'none', '--read-only',
            '--security-opt', 'no-new-privileges=true', '--cap-drop', 'ALL',
            '--cap-add', 'DAC_READ_SEARCH', '--cap-add', 'CHOWN', '--cap-add', 'FOWNER',
            '--user', '0:' . $sharedGid,
            '-e', 'MALLBASE_UPGRADE_JOB_ID=' . $jobId,
            '-e', 'MALLBASE_AGENT_UID=12345',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=32m,mode=1777',
            '--mount', 'type=bind,src=' . $this->projectRoot . '/deploy/docker/legacy-state-import.sh,dst=/usr/local/bin/legacy-state-import.sh,readonly',
            '--mount', 'type=bind,src=' . $this->projectRoot . '/deploy/docker/legacy-state-export-verify.sh,dst=/usr/local/bin/legacy-state-export-verify.sh,readonly',
            '--mount', 'type=bind,src=' . $this->projectRoot . '/deploy/docker/validate-storage-cutover.php,dst=/usr/local/bin/validate-storage-cutover.php,readonly',
            '--mount', 'type=bind,src=' . $selection . ',dst=/cutover/selection.json,readonly',
            '--mount', 'type=bind,src=' . $trust . ',dst=/cutover/storage-ready.pub,readonly',
            '--mount', 'type=bind,src=' . $input . ',dst=/input,readonly',
            '--mount', 'type=volume,src=' . $legacyVolume . ',dst=/source/runtime,readonly',
            '--mount', 'type=volume,src=' . $resultVolume . ',dst=/result',
        ];
        if ($copyShim !== null) {
            $command = [
                ...$command,
                '--init',
                '--mount', 'type=bind,src=' . $copyShim . ',dst=/usr/local/bin/cp,readonly',
            ];
        }
        foreach ($targetVolumes as $artifact => $volume) {
            $command = [...$command, '--mount', 'type=volume,src=' . $volume . ',dst=/target/' . $artifact . ',volume-nocopy'];
        }
        $command = [...$command, '--entrypoint', 'sh', 'mallbase-backend:latest', '/usr/local/bin/legacy-state-import.sh', $action];

        return $this->runProcess($command);
    }

    /** @return array{string,string,string} */
    private function writeSignedSelection(string $fixture, string $jobId, string $phase): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair);
        $private = sodium_crypto_sign_secretkey($keypair);
        $keyId = 'sha256:' . hash('sha256', $public);
        $hashA = 'sha256:' . str_repeat('a', 64);
        $hashB = 'sha256:' . str_repeat('b', 64);
        $volumeIdA = 'docker:sha256:' . str_repeat('c', 64);
        $volumeIdB = 'docker:sha256:' . str_repeat('d', 64);
        $selection = [
            'schema_version' => 1,
            'purpose' => 'storage_cutover_selection',
            'key_id' => $keyId,
            'job_id' => $jobId,
            'installation_storage_namespace' => 'mbs_selection_contract',
            'required_bootstrap_version' => '1.2.0',
            'main_manifest_sha256' => $hashA,
            'authority_revision' => 7,
            'phase' => $phase,
            'database_migration_started' => false,
            'database_migration_completed' => false,
            'source' => [
                'app_version' => '1.2.0',
                'deployment_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b2',
                'release_inventory_sha256' => $hashB,
                'storage_layout_version' => 1,
                'layout_generation' => 1,
                'finalize_receipt_sha256' => $hashA,
            ],
            'candidate' => [
                'app_version' => '1.3.0',
                'deployment_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b3',
                'release_inventory_sha256' => $hashA,
                'storage_layout_version' => 2,
                'layout_generation' => 2,
            ],
            'source_plan_sha256' => $hashB,
            'export_receipt_sha256' => $phase === 'prepared' ? null : $hashA,
            'import_receipt_sha256' => null,
            'host_inspection_sha256' => null,
            'target_authorization_sha256' => null,
            'target_confirmation_sha256' => null,
            'promote_receipt_sha256' => null,
            'artifacts' => [
                'install' => [
                    'source' => [
                        'mode' => 'legacy_volume',
                        'relative_path' => 'install',
                        'volume' => [
                            'volume_name' => 'mbs_selection_contract_runtime',
                            'docker_volume_id' => $volumeIdA,
                            'labels_sha256' => $hashA,
                            'marker_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b4',
                            'marker_sha256' => $hashB,
                        ],
                        'content' => $phase === 'prepared' ? null : [
                            'manifest_sha256' => $hashA,
                            'root_sha256' => $hashB,
                            'entry_count' => 1,
                        ],
                    ],
                    'target' => [
                        'volume' => [
                            'volume_name' => 'mbs_selection_contract_install_state',
                            'docker_volume_id' => $volumeIdB,
                            'labels_sha256' => $hashB,
                            'marker_id' => '019f5b62-c6f0-7f1d-9b50-7cf79f3ec3b5',
                            'marker_sha256' => $hashA,
                        ],
                        'policy' => [
                            'root_uid' => 0,
                            'root_gid' => 23456,
                            'root_mode' => '03770',
                            'marker_uid' => 0,
                            'marker_gid' => 23456,
                            'marker_mode' => '0444',
                            'directory_uid' => 10000,
                            'directory_gid' => 23456,
                            'directory_mode' => '02770',
                            'file_uid' => 10000,
                            'file_gid' => 23456,
                            'file_mode' => '0660',
                        ],
                        'content' => null,
                    ],
                ],
            ],
            'issued_at' => 1_783_785_600,
        ];
        $template = $selection['artifacts']['install'];
        $definitions = [
            'cert' => ['container_export', 'cert'],
            'demo' => ['container_export', 'demo'],
            'install' => ['legacy_volume', 'install'],
            'local_storage' => ['legacy_volume', 'storage'],
            'public_storage' => ['container_export', 'public-storage'],
            'runtime_backup' => ['legacy_volume', 'backup'],
            'uploads' => ['container_export', 'uploads'],
        ];
        $artifacts = [];
        $index = 1;
        foreach ($definitions as $name => [$mode, $relative]) {
            $artifact = $template;
            $artifact['source']['mode'] = $mode;
            $artifact['source']['relative_path'] = $relative;
            if ($mode === 'container_export') {
                $artifact['source']['volume'] = null;
            }
            $targetRole = $name === 'install' ? 'install_state' : $name;
            $artifact['target']['volume']['volume_name'] = 'mbs_selection_contract_' . $targetRole;
            $artifact['target']['volume']['docker_volume_id'] = 'docker:sha256:' . str_repeat(dechex($index), 64);
            $artifact['target']['volume']['marker_id'] = sprintf('019f5b62-c6f0-7f1d-9b50-%012x', $index);
            $marker = [
                'schema_version' => 1,
                'installation_storage_namespace' => 'mbs_selection_contract',
                'artifact' => $name,
                'storage_layout_version' => 2,
                'layout_generation' => 2,
                'marker_id' => $artifact['target']['volume']['marker_id'],
            ];
            $artifact['target']['volume']['marker_sha256'] = 'sha256:' . hash(
                'sha256',
                json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $artifacts[$name] = $artifact;
            ++$index;
        }
        $selection['artifacts'] = $artifacts;
        $unsigned = json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $selection['signature'] = base64_encode(sodium_crypto_sign_detached($unsigned, $private));
        $selectionPath = $fixture . '/selection.json';
        file_put_contents($selectionPath, json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($selectionPath, 0444);

        $trustPath = $fixture . '/storage-ready.pub';
        file_put_contents($trustPath, json_encode([
            'schema_version' => 1,
            'key_id' => $keyId,
            'public_key' => base64_encode($public),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($trustPath, 0444);

        return [$selectionPath, $trustPath, base64_encode($private)];
    }

    /** @param array<string,mixed> $exportReceipt */
    private function advanceSelectionToImporting(
        string $selectionPath,
        string $privateKey,
        array $exportReceipt,
        string $exportReceiptSha256,
    ): void {
        $selection = json_decode((string) file_get_contents($selectionPath), true, 64, JSON_THROW_ON_ERROR);
        unset($selection['signature']);
        $selection['authority_revision'] += 2;
        $selection['phase'] = 'importing';
        $selection['database_migration_started'] = true;
        $selection['database_migration_completed'] = true;
        $selection['export_receipt_sha256'] = $exportReceiptSha256;
        foreach (array_keys($selection['artifacts']) as $artifact) {
            $selection['artifacts'][$artifact]['source']['content'] =
                $exportReceipt['artifacts'][$artifact]['source']['content'];
        }
        $unsigned = json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $selection['signature'] = base64_encode(sodium_crypto_sign_detached(
            $unsigned,
            base64_decode($privateKey, true),
        ));
        chmod($selectionPath, 0644);
        file_put_contents(
            $selectionPath,
            json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        chmod($selectionPath, 0444);
    }

    /**
     * @param array<string,mixed> $importReceipt
     * @return array{string,string}
     */
    private function advanceSelectionToProvisioned(
        string $selectionPath,
        string $privateKey,
        array $importReceipt,
        string $importReceiptSha256,
        string $hostInspectionSha256,
    ): array {
        $selection = json_decode((string) file_get_contents($selectionPath), true, 64, JSON_THROW_ON_ERROR);
        unset($selection['signature']);
        $selection['authority_revision'] += 1;
        $selection['phase'] = 'provisioned';
        $selection['import_receipt_sha256'] = $importReceiptSha256;
        $selection['host_inspection_sha256'] = $hostInspectionSha256;
        $selection['target_authorization_sha256'] = null;
        foreach (array_keys($selection['artifacts']) as $artifact) {
            $selection['artifacts'][$artifact]['target']['content'] =
                $importReceipt['artifacts'][$artifact]['target']['content'];
        }
        $selection['target_authorization_sha256'] = 'sha256:' . hash(
            'sha256',
            "mallbase-storage-cutover-target-authorization-v1\0"
                . json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $targetAuthorizationSha256 = $selection['target_authorization_sha256'];
        $unsigned = json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $selection['signature'] = base64_encode(sodium_crypto_sign_detached(
            $unsigned,
            base64_decode($privateKey, true),
        ));
        $targetPath = dirname($selectionPath) . '/selection-provisioned.json';
        file_put_contents(
            $targetPath,
            json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        chmod($targetPath, 0444);

        return [$targetPath, $targetAuthorizationSha256];
    }

    /**
     * @param array<string,string> $targetVolumes
     * @return array{int,string}
     */
    private function runTargetVerifier(
        string $jobId,
        int $sharedGid,
        string $selection,
        string $trust,
        string $resultVolume,
        array $targetVolumes,
        string $versionPath,
        string $deploymentPath,
        string $snapshot,
        string $thinkStub,
    ): array {
        $command = [
            'docker', 'run', '--rm', '--network', 'none', '--read-only',
            '--security-opt', 'no-new-privileges=true', '--cap-drop', 'ALL',
            '--cap-add', 'CHOWN', '--cap-add', 'FOWNER', '--cap-add', 'SETUID', '--cap-add', 'SETGID',
            '--user', '0:' . $sharedGid,
            '-e', 'MALLBASE_UPGRADE_JOB_ID=' . $jobId,
            '-e', 'MALLBASE_AGENT_UID=12345',
            '-e', 'MALLBASE_RUNTIME_ROLE=target-verify',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=32m,mode=1777',
            '--mount', 'type=bind,src=' . $this->projectRoot . '/deploy/docker/target-state-verify.sh,dst=/usr/local/bin/target-state-verify.sh,readonly',
            '--mount', 'type=bind,src=' . $this->projectRoot . '/deploy/docker/run-target-php.php,dst=/usr/local/bin/run-target-php.php,readonly',
            '--mount', 'type=bind,src=' . $this->projectRoot . '/deploy/docker/validate-storage-cutover.php,dst=/usr/local/bin/validate-storage-cutover.php,readonly',
            '--mount', 'type=bind,src=' . $selection . ',dst=/cutover/selection.json,readonly',
            '--mount', 'type=bind,src=' . $trust . ',dst=/cutover/storage-ready.pub,readonly',
            '--mount', 'type=bind,src=' . $snapshot . ',dst=/cutover/target-snapshot-fixture.json,readonly',
            '--mount', 'type=bind,src=' . $thinkStub . ',dst=/app/think,readonly',
            '--mount', 'type=bind,src=' . $versionPath . ',dst=/.version,readonly',
            '--mount', 'type=bind,src=' . $deploymentPath . ',dst=/.mallbase-deployment.json,readonly',
            '--mount', 'type=volume,src=' . $resultVolume . ',dst=/result',
        ];
        foreach ($targetVolumes as $artifact => $volume) {
            $command = [...$command, '--mount', 'type=volume,src=' . $volume . ',dst=/target/' . $artifact . ',readonly,volume-nocopy'];
        }
        $command = [...$command, '--entrypoint', 'sh', 'mallbase-backend:latest', '/usr/local/bin/target-state-verify.sh'];

        return $this->runProcess($command);
    }

    /** @return array{volume_name:string,docker_volume_id:string,labels_sha256:string} */
    private function dockerVolumeIdentity(string $inspectJson): array
    {
        $values = json_decode($inspectJson, true, 32, JSON_THROW_ON_ERROR);
        $volume = $values[0];
        $labels = $volume['Labels'] ?? [];
        ksort($labels, SORT_STRING);
        $labelsSha = 'sha256:' . hash(
            'sha256',
            json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        );
        $identity = [
            'driver' => 'local',
            'labels_sha256' => $labelsSha,
            'name' => $volume['Name'],
            'scope' => 'local',
        ];

        return [
            'volume_name' => $volume['Name'],
            'docker_volume_id' => 'docker:sha256:' . hash(
                'sha256',
                json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            ),
            'labels_sha256' => $labelsSha,
        ];
    }

    private function writeHostObservationsFromSelection(string $selectionPath, string $output): void
    {
        $selection = json_decode((string) file_get_contents($selectionPath), true, 64, JSON_THROW_ON_ERROR);
        $lines = [];
        foreach ($selection['artifacts'] as $name => $artifact) {
            $source = $artifact['source']['volume'];
            $target = $artifact['target']['volume'];
            $lines[] = implode("\t", [
                $name,
                $source['volume_name'] ?? '-',
                $source['docker_volume_id'] ?? '-',
                $source['labels_sha256'] ?? '-',
                $target['volume_name'],
                $target['docker_volume_id'],
                $target['labels_sha256'],
            ]);
        }
        file_put_contents($output, implode("\n", $lines) . "\n");
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
