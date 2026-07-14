<?php

declare(strict_types=1);

const CUTOVER_ARTIFACTS = [
    'cert',
    'demo',
    'install',
    'local_storage',
    'public_storage',
    'runtime_backup',
    'uploads',
];
const CUTOVER_PHASES = [
    'prepared',
    'export_verified',
    'importing',
    'provisioned',
    'target_confirmed',
    'promoted',
    'recovery_required',
    'rolled_back',
];
const CUTOVER_SOURCE_MODES = ['legacy_volume', 'container_export', 'already_namespaced', 'absent'];
const CUTOVER_EMPTY_MANIFEST_SHA256 = 'sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
const CUTOVER_EMPTY_ROOT_SHA256 = 'sha256:f2cf28ff05c840fe71972a5b7868fe1a4fc163dcc3a63a81390814773a078a6e';
const CUTOVER_MAX_SELECTION_BYTES = 2_097_152;

function fail(string $code): never
{
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}

function exactKeys(array $value, array $keys, string $code): void
{
    if (array_keys($value) !== $keys) {
        fail($code);
    }
}

function validHash(mixed $value): bool
{
    return is_string($value) && preg_match('/^sha256:[0-9a-f]{64}$/D', $value) === 1;
}

function validVolumeID(mixed $value): bool
{
    return is_string($value) && preg_match('/^(?:docker|bind):sha256:[0-9a-f]{64}$/D', $value) === 1;
}

function validUuid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

function validVersion(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $value) === 1;
}

function readCanonicalFile(string $path, string $fileCode, string $canonicalCode): array
{
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || (($stat['mode'] ?? 0) & 0777) !== 0444 || ($stat['size'] ?? 0) < 2
        || ($stat['size'] ?? 0) > CUTOVER_MAX_SELECTION_BYTES) {
        fail($fileCode);
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || strlen($raw) !== (int) $stat['size'] || !mb_check_encoding($raw, 'UTF-8')) {
        fail($fileCode);
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $encoded = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (JsonException) {
        fail($canonicalCode);
    }
    if (!is_array($decoded) || $encoded !== $raw) {
        fail($canonicalCode);
    }

    return $decoded;
}

function readCanonicalTrust(string $path): array
{
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || (($stat['mode'] ?? 0) & 0777) !== 0444 || ($stat['size'] ?? 0) < 3
        || ($stat['size'] ?? 0) > CUTOVER_MAX_SELECTION_BYTES) {
        fail('CUTOVER_TRUST_FILE_INVALID');
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || strlen($raw) !== (int) $stat['size'] || !str_ends_with($raw, "\n")
        || str_ends_with($raw, "\n\n") || !mb_check_encoding($raw, 'UTF-8')) {
        fail('CUTOVER_TRUST_FILE_INVALID');
    }
    $canonical = substr($raw, 0, -1);
    try {
        $decoded = json_decode($canonical, true, 16, JSON_THROW_ON_ERROR);
        $encoded = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (JsonException) {
        fail('CUTOVER_TRUST_CANONICAL_INVALID');
    }
    if (!is_array($decoded) || $encoded !== $canonical) {
        fail('CUTOVER_TRUST_CANONICAL_INVALID');
    }

    return $decoded;
}

function validateTrust(array $trust): array
{
    exactKeys($trust, ['schema_version', 'key_id', 'public_key'], 'CUTOVER_TRUST_INVALID');
    $public = is_string($trust['public_key']) ? base64_decode($trust['public_key'], true) : false;
    if ($trust['schema_version'] !== 1 || !validHash($trust['key_id']) || !is_string($public)
        || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || $trust['key_id'] !== 'sha256:' . hash('sha256', $public)) {
        fail('CUTOVER_TRUST_INVALID');
    }

    return [$trust['key_id'], $public];
}

function validateRuntimeTuple(array $tuple, bool $source): void
{
    $keys = ['app_version', 'deployment_id', 'release_inventory_sha256', 'storage_layout_version', 'layout_generation'];
    if ($source) {
        $keys[] = 'finalize_receipt_sha256';
    }
    exactKeys($tuple, $keys, 'CUTOVER_SELECTION_RUNTIME_INVALID');
    if (!validVersion($tuple['app_version']) || !validUuid($tuple['deployment_id'])
        || !validHash($tuple['release_inventory_sha256']) || !is_int($tuple['storage_layout_version'])
        || $tuple['storage_layout_version'] < 1 || $tuple['storage_layout_version'] > 1_000_000
        || !is_int($tuple['layout_generation']) || $tuple['layout_generation'] < 1) {
        fail('CUTOVER_SELECTION_RUNTIME_INVALID');
    }
    if ($source && !validHash($tuple['finalize_receipt_sha256'])) {
        fail('CUTOVER_SELECTION_RUNTIME_INVALID');
    }
}

function validateVolume(mixed $value): array
{
    if (!is_array($value)) {
        fail('CUTOVER_SELECTION_VOLUME_INVALID');
    }
    exactKeys(
        $value,
        ['volume_name', 'docker_volume_id', 'labels_sha256', 'marker_id', 'marker_sha256'],
        'CUTOVER_SELECTION_VOLUME_INVALID',
    );
    if (!is_string($value['volume_name']) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/D', $value['volume_name']) !== 1
        || !validVolumeID($value['docker_volume_id']) || !validHash($value['labels_sha256'])
        || !validUuid($value['marker_id']) || !validHash($value['marker_sha256'])) {
        fail('CUTOVER_SELECTION_VOLUME_INVALID');
    }

    return $value;
}

function validateContent(mixed $value, bool $nullable): ?array
{
    if ($value === null && $nullable) {
        return null;
    }
    if (!is_array($value)) {
        fail('CUTOVER_SELECTION_CONTENT_INVALID');
    }
    exactKeys($value, ['manifest_sha256', 'root_sha256', 'entry_count'], 'CUTOVER_SELECTION_CONTENT_INVALID');
    if (!validHash($value['manifest_sha256']) || !validHash($value['root_sha256'])
        || !is_int($value['entry_count']) || $value['entry_count'] < 0 || $value['entry_count'] > 10_000_000) {
        fail('CUTOVER_SELECTION_CONTENT_INVALID');
    }

    return $value;
}

function validatePolicy(mixed $value): array
{
    if (!is_array($value)) {
        fail('CUTOVER_SELECTION_POLICY_INVALID');
    }
    exactKeys($value, [
        'root_uid', 'root_gid', 'root_mode', 'marker_uid', 'marker_gid', 'marker_mode',
        'directory_uid', 'directory_gid', 'directory_mode', 'file_uid', 'file_gid', 'file_mode',
    ], 'CUTOVER_SELECTION_POLICY_INVALID');
    foreach (['root_uid', 'root_gid', 'marker_uid', 'marker_gid', 'directory_uid', 'directory_gid', 'file_uid', 'file_gid'] as $key) {
        if (!is_int($value[$key]) || $value[$key] < 0 || $value[$key] > 2_147_483_647) {
            fail('CUTOVER_SELECTION_POLICY_INVALID');
        }
    }
    if ($value['root_uid'] !== 0 || $value['marker_uid'] !== 0 || $value['directory_uid'] !== 10000
        || $value['file_uid'] !== 10000 || $value['root_gid'] < 1
        || $value['root_gid'] !== $value['marker_gid'] || $value['root_gid'] !== $value['directory_gid']
        || $value['root_gid'] !== $value['file_gid'] || $value['root_mode'] !== '03770'
        || $value['marker_mode'] !== '0444' || $value['directory_mode'] !== '02770'
        || $value['file_mode'] !== '0660') {
        fail('CUTOVER_SELECTION_POLICY_INVALID');
    }

    return $value;
}

function cleanRelativePath(mixed $value, string $mode): bool
{
    if (!is_string($value) || str_contains($value, "\0") || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
        return false;
    }
    if ($mode === 'absent') {
        return $value === '';
    }
    if ($value === '.') {
        return true;
    }
    if ($value === '' || str_starts_with($value, '/') || str_ends_with($value, '/') || str_contains($value, '\\')) {
        return false;
    }
    foreach (explode('/', $value) as $component) {
        if ($component === '' || $component === '.' || $component === '..'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $component) !== 1) {
            return false;
        }
    }

    return strlen($value) <= 512;
}

function phaseNeedsSourceContent(string $phase): bool
{
    return $phase !== 'prepared';
}

function selectionNeedsTargetContent(array $selection): bool
{
    return in_array($selection['phase'], ['provisioned', 'target_confirmed', 'promoted'], true)
        || (in_array($selection['phase'], ['recovery_required', 'rolled_back'], true)
            && validHash($selection['import_receipt_sha256'])
            && validHash($selection['host_inspection_sha256']));
}

function validateArtifactRouting(string $artifact, string $mode, string $relative): void
{
    if ($mode === 'absent') {
        return;
    }
    if ($mode === 'already_namespaced') {
        if ($relative !== '.') {
            fail('CUTOVER_SELECTION_SOURCE_INVALID');
        }
        return;
    }
    $expected = [
        'cert' => ['container_export', 'cert'],
        'demo' => ['container_export', 'demo'],
        'install' => ['legacy_volume', 'install'],
        'local_storage' => ['legacy_volume', 'storage'],
        'public_storage' => ['container_export', 'public-storage'],
        'runtime_backup' => ['legacy_volume', 'backup'],
        'uploads' => ['container_export', 'uploads'],
    ][$artifact] ?? null;
    if ($expected === null || [$mode, $relative] !== $expected) {
        fail('CUTOVER_SELECTION_SOURCE_INVALID');
    }
}

function validateTargetRole(string $namespace, string $artifact, array $volume): void
{
    $role = $artifact === 'install' ? 'install_state' : $artifact;
    $prefix = $namespace . '_' . $role;
    if ($volume['volume_name'] !== $prefix
        && preg_match('/^' . preg_quote($prefix, '/') . '_[a-z0-9][a-z0-9_.-]{0,63}$/D', $volume['volume_name']) !== 1) {
        fail('CUTOVER_SELECTION_TARGET_INVALID');
    }
}

function validateEvidenceFields(array $selection): void
{
    $phase = $selection['phase'];
    $requirements = match ($phase) {
        'prepared' => [false, false, false, false, false, false],
        'export_verified', 'importing' => [true, false, false, false, false, false],
        'provisioned' => [true, true, true, true, false, false],
        'target_confirmed' => [true, true, true, true, true, false],
        'promoted' => [true, true, true, true, true, true],
        default => null,
    };
    if ($requirements === null) {
        $values = [];
        foreach ([
            'export_receipt_sha256', 'import_receipt_sha256', 'host_inspection_sha256',
            'target_authorization_sha256', 'target_confirmation_sha256', 'promote_receipt_sha256',
        ] as $key) {
            if ($selection[$key] !== null && !validHash($selection[$key])) {
                fail('CUTOVER_SELECTION_EVIDENCE_INVALID');
            }
            $values[$key] = $selection[$key] !== null;
        }
        if ($values['import_receipt_sha256'] !== $values['host_inspection_sha256']
            || ($values['import_receipt_sha256'] && !$values['export_receipt_sha256'])
            || ($values['target_authorization_sha256'] && !$values['import_receipt_sha256'])
            || ($values['target_confirmation_sha256'] && !$values['target_authorization_sha256'])
            || ($values['promote_receipt_sha256'] && !$values['target_confirmation_sha256'])) {
            fail('CUTOVER_SELECTION_EVIDENCE_INVALID');
        }
        return;
    }
    foreach (['export_receipt_sha256', 'import_receipt_sha256', 'host_inspection_sha256', 'target_authorization_sha256', 'target_confirmation_sha256', 'promote_receipt_sha256'] as $index => $key) {
        if (($requirements[$index] && !validHash($selection[$key]))
            || (!$requirements[$index] && $selection[$key] !== null)) {
            fail('CUTOVER_SELECTION_EVIDENCE_INVALID');
        }
    }
}

function validateSelection(array $selection, string $expectedJob, string $expectedPhase, string $keyID, string $public): void
{
    exactKeys($selection, [
        'schema_version', 'purpose', 'key_id', 'job_id', 'installation_storage_namespace',
        'required_bootstrap_version', 'main_manifest_sha256', 'authority_revision', 'phase',
        'database_migration_started', 'database_migration_completed', 'source', 'candidate',
        'source_plan_sha256', 'export_receipt_sha256', 'import_receipt_sha256',
        'host_inspection_sha256', 'target_authorization_sha256', 'target_confirmation_sha256',
        'promote_receipt_sha256', 'artifacts',
        'issued_at', 'signature',
    ], 'CUTOVER_SELECTION_SCHEMA_INVALID');
    if ($selection['schema_version'] !== 1 || $selection['purpose'] !== 'storage_cutover_selection'
        || $selection['key_id'] !== $keyID || !validUuid($selection['job_id'])
        || $selection['job_id'] !== $expectedJob || !is_string($selection['installation_storage_namespace'])
        || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $selection['installation_storage_namespace']) !== 1
        || !validVersion($selection['required_bootstrap_version']) || !validHash($selection['main_manifest_sha256'])
        || !is_int($selection['authority_revision']) || $selection['authority_revision'] < 1
        || !in_array($selection['phase'], CUTOVER_PHASES, true) || $selection['phase'] !== $expectedPhase
        || !is_bool($selection['database_migration_started'])
        || !is_bool($selection['database_migration_completed'])
        || ($selection['database_migration_completed'] && !$selection['database_migration_started'])
        || ($selection['phase'] === 'prepared'
            && ($selection['database_migration_started'] || $selection['database_migration_completed']))
        || (in_array($selection['phase'], ['importing', 'provisioned', 'target_confirmed', 'promoted'], true)
            && (!$selection['database_migration_started'] || !$selection['database_migration_completed']))
        || !validHash($selection['source_plan_sha256']) || !is_int($selection['issued_at']) || $selection['issued_at'] < 0) {
        if (($selection['phase'] ?? null) !== $expectedPhase) {
            fail('CUTOVER_SELECTION_PHASE_INVALID');
        }
        fail('CUTOVER_SELECTION_IDENTITY_INVALID');
    }
    if (!is_array($selection['source']) || !is_array($selection['candidate'])) {
        fail('CUTOVER_SELECTION_RUNTIME_INVALID');
    }
    validateRuntimeTuple($selection['source'], true);
    validateRuntimeTuple($selection['candidate'], false);
    if ($selection['source']['app_version'] !== $selection['required_bootstrap_version']
        || $selection['candidate']['storage_layout_version'] <= $selection['source']['storage_layout_version']
        || $selection['candidate']['layout_generation'] <= $selection['source']['layout_generation']) {
        fail('CUTOVER_SELECTION_RUNTIME_INVALID');
    }
    validateEvidenceFields($selection);

    if (!is_array($selection['artifacts']) || array_keys($selection['artifacts']) !== CUTOVER_ARTIFACTS) {
        fail('CUTOVER_SELECTION_ARTIFACT_SET_INVALID');
    }
    $targetIDs = [];
    foreach ($selection['artifacts'] as $name => &$artifact) {
        if (!is_array($artifact)) {
            fail('CUTOVER_SELECTION_ARTIFACT_INVALID');
        }
        exactKeys($artifact, ['source', 'target'], 'CUTOVER_SELECTION_ARTIFACT_INVALID');
        if (!is_array($artifact['source']) || !is_array($artifact['target'])) {
            fail('CUTOVER_SELECTION_ARTIFACT_INVALID');
        }
        exactKeys($artifact['source'], ['mode', 'relative_path', 'volume', 'content'], 'CUTOVER_SELECTION_SOURCE_INVALID');
        exactKeys($artifact['target'], ['volume', 'policy', 'content'], 'CUTOVER_SELECTION_TARGET_INVALID');
        $mode = $artifact['source']['mode'];
        if (!is_string($mode) || !in_array($mode, CUTOVER_SOURCE_MODES, true)
            || !cleanRelativePath($artifact['source']['relative_path'], $mode)) {
            fail('CUTOVER_SELECTION_SOURCE_INVALID');
        }
        validateArtifactRouting($name, $mode, $artifact['source']['relative_path']);
        if (in_array($mode, ['legacy_volume', 'already_namespaced'], true)) {
            $artifact['source']['volume'] = validateVolume($artifact['source']['volume']);
        } elseif ($artifact['source']['volume'] !== null) {
            fail('CUTOVER_SELECTION_SOURCE_INVALID');
        }
        $sourceNullable = !phaseNeedsSourceContent($selection['phase']) && $mode !== 'absent';
        $artifact['source']['content'] = validateContent($artifact['source']['content'], $sourceNullable);
        if ($mode === 'absent' && ($artifact['source']['content'] === null
            || $artifact['source']['content']['manifest_sha256'] !== CUTOVER_EMPTY_MANIFEST_SHA256
            || $artifact['source']['content']['root_sha256'] !== CUTOVER_EMPTY_ROOT_SHA256
            || $artifact['source']['content']['entry_count'] !== 0)) {
            fail('CUTOVER_SELECTION_SOURCE_INVALID');
        }
        $artifact['target']['volume'] = validateVolume($artifact['target']['volume']);
        validateTargetRole($selection['installation_storage_namespace'], $name, $artifact['target']['volume']);
        $artifact['target']['policy'] = validatePolicy($artifact['target']['policy']);
        $artifact['target']['content'] = validateContent(
            $artifact['target']['content'],
            !selectionNeedsTargetContent($selection),
        );
        $targetID = $artifact['target']['volume']['docker_volume_id'];
        if (isset($targetIDs[$targetID])) {
            fail('CUTOVER_SELECTION_TARGET_INVALID');
        }
        $targetIDs[$targetID] = true;
    }
    unset($artifact);

    $signature = is_string($selection['signature']) ? base64_decode($selection['signature'], true) : false;
    if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        fail('CUTOVER_SELECTION_SIGNATURE_INVALID');
    }
    unset($selection['signature']);
    $unsigned = json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (!sodium_crypto_sign_verify_detached($signature, $unsigned, $public)) {
        fail('CUTOVER_SELECTION_SIGNATURE_INVALID');
    }
    if ($selection['phase'] === 'provisioned'
        && $selection['target_authorization_sha256'] !== targetAuthorizationHash($selection)) {
        fail('CUTOVER_TARGET_AUTHORIZATION_INVALID');
    }
}

function printPlan(array $selection): void
{
    $header = [
        'selection', $selection['job_id'], $selection['phase'], $selection['installation_storage_namespace'],
        $selection['required_bootstrap_version'], $selection['main_manifest_sha256'],
        (string) $selection['authority_revision'], $selection['source_plan_sha256'], $selection['key_id'],
        $selection['candidate']['app_version'], $selection['candidate']['deployment_id'],
        (string) $selection['candidate']['storage_layout_version'],
        (string) $selection['candidate']['layout_generation'],
    ];
    fwrite(STDOUT, implode("\t", $header) . PHP_EOL);
    foreach ($selection['artifacts'] as $name => $artifact) {
        $sourceVolume = $artifact['source']['volume'];
        $sourceContent = $artifact['source']['content'];
        $target = $artifact['target'];
        $targetContent = $target['content'];
        $row = [
            'artifact', $name, $artifact['source']['mode'], $artifact['source']['relative_path'],
            $sourceVolume['volume_name'] ?? '-', $sourceVolume['docker_volume_id'] ?? '-',
            $sourceContent['manifest_sha256'] ?? '-', $sourceContent['root_sha256'] ?? '-',
            isset($sourceContent['entry_count']) ? (string) $sourceContent['entry_count'] : '-',
            $target['volume']['volume_name'], $target['volume']['docker_volume_id'],
            $target['volume']['labels_sha256'], $target['volume']['marker_id'], $target['volume']['marker_sha256'],
            (string) $target['policy']['root_uid'], (string) $target['policy']['root_gid'], $target['policy']['root_mode'],
            (string) $target['policy']['marker_uid'], (string) $target['policy']['marker_gid'], $target['policy']['marker_mode'],
            (string) $target['policy']['directory_uid'], (string) $target['policy']['directory_gid'], $target['policy']['directory_mode'],
            (string) $target['policy']['file_uid'], (string) $target['policy']['file_gid'], $target['policy']['file_mode'],
            $targetContent['manifest_sha256'] ?? '-', $targetContent['root_sha256'] ?? '-',
            isset($targetContent['entry_count']) ? (string) $targetContent['entry_count'] : '-',
        ];
        fwrite(STDOUT, implode("\t", $row) . PHP_EOL);
    }
}

function readContentEvidence(string $path): array
{
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || ($stat['size'] ?? 0) < 1 || ($stat['size'] ?? 0) > 65_536) {
        fail('CUTOVER_CONTENT_EVIDENCE_INVALID');
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || count($lines) !== count(CUTOVER_ARTIFACTS)) {
        fail('CUTOVER_CONTENT_EVIDENCE_INVALID');
    }
    $contents = [];
    foreach ($lines as $index => $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 4 || $fields[0] !== CUTOVER_ARTIFACTS[$index]
            || !validHash($fields[1]) || !validHash($fields[2])
            || preg_match('/^(?:0|[1-9][0-9]{0,7})$/D', $fields[3]) !== 1) {
            fail('CUTOVER_CONTENT_EVIDENCE_INVALID');
        }
        $contents[$fields[0]] = [
            'manifest_sha256' => $fields[1],
            'root_sha256' => $fields[2],
            'entry_count' => (int) $fields[3],
        ];
    }

    return $contents;
}

function protocolHash(array $value): string
{
    return 'sha256:' . hash(
        'sha256',
        json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    );
}

function targetAuthorizationHash(array $selection): string
{
    unset($selection['signature']);
    $selection['target_authorization_sha256'] = null;

    return 'sha256:' . hash(
        'sha256',
        "mallbase-storage-cutover-target-authorization-v1\0"
            . json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    );
}

function writeCanonicalFile(string $path, array $value): void
{
    $parent = dirname($path);
    if (!is_dir($parent) || is_link($parent) || file_exists($path) || is_link($path)) {
        fail('CUTOVER_OUTPUT_INVALID');
    }
    $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        fail('CUTOVER_OUTPUT_INVALID');
    }
    $written = fwrite($handle, $encoded);
    if ($written !== strlen($encoded) || !fflush($handle) || !fclose($handle) || !chmod($path, 0600)) {
        @fclose($handle);
        fail('CUTOVER_OUTPUT_INVALID');
    }
}

function writeExportReceipt(array $selection, array $contents, string $output): void
{
    $artifacts = [];
    foreach (CUTOVER_ARTIFACTS as $name) {
        $source = $selection['artifacts'][$name]['source'];
        $source['content'] = $contents[$name];
        if ($source['mode'] === 'absent' && $source['content'] !== [
            'manifest_sha256' => CUTOVER_EMPTY_MANIFEST_SHA256,
            'root_sha256' => CUTOVER_EMPTY_ROOT_SHA256,
            'entry_count' => 0,
        ]) {
            fail('CUTOVER_CONTENT_EVIDENCE_INVALID');
        }
        $artifacts[$name] = ['source' => $source];
    }
    $unsigned = [
        'schema_version' => 1,
        'purpose' => 'storage_cutover_export_receipt',
        'job_id' => $selection['job_id'],
        'installation_storage_namespace' => $selection['installation_storage_namespace'],
        'main_manifest_sha256' => $selection['main_manifest_sha256'],
        'authority_revision' => $selection['authority_revision'],
        'candidate' => $selection['candidate'],
        'source_plan_sha256' => $selection['source_plan_sha256'],
        'artifacts' => $artifacts,
        'complete' => true,
    ];
    $receipt = array_slice($unsigned, 0, -1, true)
        + ['receipt_sha256' => protocolHash($unsigned)]
        + ['complete' => true];
    writeCanonicalFile($output, $receipt);
}

function readCanonicalEvidence(string $path): array
{
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || ($stat['size'] ?? 0) < 2 || ($stat['size'] ?? 0) > CUTOVER_MAX_SELECTION_BYTES) {
        fail('CUTOVER_EVIDENCE_FILE_INVALID');
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || strlen($raw) !== (int) $stat['size'] || !mb_check_encoding($raw, 'UTF-8')) {
        fail('CUTOVER_EVIDENCE_FILE_INVALID');
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $encoded = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (JsonException) {
        fail('CUTOVER_EVIDENCE_CANONICAL_INVALID');
    }
    if (!is_array($decoded) || $encoded !== $raw) {
        fail('CUTOVER_EVIDENCE_CANONICAL_INVALID');
    }

    return $decoded;
}

function validateExportReceiptAgainstSelection(array $selection, array $receipt): void
{
    exactKeys($receipt, [
        'schema_version', 'purpose', 'job_id', 'installation_storage_namespace',
        'main_manifest_sha256', 'authority_revision', 'candidate', 'source_plan_sha256',
        'artifacts', 'receipt_sha256', 'complete',
    ], 'CUTOVER_EXPORT_RECEIPT_INVALID');
    if ($receipt['schema_version'] !== 1 || $receipt['purpose'] !== 'storage_cutover_export_receipt'
        || $receipt['job_id'] !== $selection['job_id']
        || $receipt['installation_storage_namespace'] !== $selection['installation_storage_namespace']
        || $receipt['main_manifest_sha256'] !== $selection['main_manifest_sha256']
        || !is_int($receipt['authority_revision']) || $receipt['authority_revision'] < 1
        || $receipt['authority_revision'] >= $selection['authority_revision']
        || $receipt['candidate'] !== $selection['candidate']
        || $receipt['source_plan_sha256'] !== $selection['source_plan_sha256']
        || $receipt['complete'] !== true || !validHash($receipt['receipt_sha256'])
        || $receipt['receipt_sha256'] !== $selection['export_receipt_sha256']) {
        fail('CUTOVER_EXPORT_RECEIPT_INVALID');
    }
    if (!is_array($receipt['artifacts']) || array_keys($receipt['artifacts']) !== CUTOVER_ARTIFACTS) {
        fail('CUTOVER_EXPORT_RECEIPT_INVALID');
    }
    foreach (CUTOVER_ARTIFACTS as $name) {
        $artifact = $receipt['artifacts'][$name];
        exactKeys($artifact, ['source'], 'CUTOVER_EXPORT_RECEIPT_INVALID');
        if ($artifact['source'] !== $selection['artifacts'][$name]['source']) {
            fail('CUTOVER_EXPORT_RECEIPT_INVALID');
        }
    }
    $unsigned = $receipt;
    $selfHash = $unsigned['receipt_sha256'];
    unset($unsigned['receipt_sha256']);
    if ($selfHash !== protocolHash($unsigned)) {
        fail('CUTOVER_EXPORT_RECEIPT_INVALID');
    }
}

function writeImportReceipt(array $selection, array $contents, string $output): void
{
    $artifacts = [];
    foreach (CUTOVER_ARTIFACTS as $name) {
        if ($selection['artifacts'][$name]['source']['content'] !== $contents[$name]) {
            fail('CUTOVER_CONTENT_EVIDENCE_INVALID');
        }
        $target = $selection['artifacts'][$name]['target'];
        $target['content'] = $contents[$name];
        $artifacts[$name] = ['target' => $target];
    }
    $unsigned = [
        'schema_version' => 1,
        'purpose' => 'storage_cutover_import_receipt',
        'job_id' => $selection['job_id'],
        'installation_storage_namespace' => $selection['installation_storage_namespace'],
        'main_manifest_sha256' => $selection['main_manifest_sha256'],
        'authority_revision' => $selection['authority_revision'],
        'candidate' => $selection['candidate'],
        'source_plan_sha256' => $selection['source_plan_sha256'],
        'export_receipt_sha256' => $selection['export_receipt_sha256'],
        'artifacts' => $artifacts,
        'complete' => true,
    ];
    $receipt = array_slice($unsigned, 0, -1, true)
        + ['receipt_sha256' => protocolHash($unsigned)]
        + ['complete' => true];
    writeCanonicalFile($output, $receipt);
}

function validateImportReceiptAgainstSelection(array $selection, array $receipt): void
{
    exactKeys($receipt, [
        'schema_version', 'purpose', 'job_id', 'installation_storage_namespace',
        'main_manifest_sha256', 'authority_revision', 'candidate', 'source_plan_sha256',
        'export_receipt_sha256', 'artifacts', 'receipt_sha256', 'complete',
    ], 'CUTOVER_IMPORT_RECEIPT_INVALID');
    if ($receipt['schema_version'] !== 1 || $receipt['purpose'] !== 'storage_cutover_import_receipt'
        || $receipt['job_id'] !== $selection['job_id']
        || $receipt['installation_storage_namespace'] !== $selection['installation_storage_namespace']
        || $receipt['main_manifest_sha256'] !== $selection['main_manifest_sha256']
        || $receipt['authority_revision'] !== $selection['authority_revision']
        || $receipt['candidate'] !== $selection['candidate']
        || $receipt['source_plan_sha256'] !== $selection['source_plan_sha256']
        || $receipt['export_receipt_sha256'] !== $selection['export_receipt_sha256']
        || $receipt['complete'] !== true || !validHash($receipt['receipt_sha256'])) {
        fail('CUTOVER_IMPORT_RECEIPT_INVALID');
    }
    if (!is_array($receipt['artifacts']) || array_keys($receipt['artifacts']) !== CUTOVER_ARTIFACTS) {
        fail('CUTOVER_IMPORT_RECEIPT_INVALID');
    }
    foreach (CUTOVER_ARTIFACTS as $name) {
        $artifact = $receipt['artifacts'][$name];
        exactKeys($artifact, ['target'], 'CUTOVER_IMPORT_RECEIPT_INVALID');
        $expected = $selection['artifacts'][$name]['target'];
        $expected['content'] = $selection['artifacts'][$name]['source']['content'];
        if ($artifact['target'] !== $expected) {
            fail('CUTOVER_IMPORT_RECEIPT_INVALID');
        }
    }
    $unsigned = $receipt;
    $selfHash = $unsigned['receipt_sha256'];
    unset($unsigned['receipt_sha256']);
    if ($selfHash !== protocolHash($unsigned)) {
        fail('CUTOVER_IMPORT_RECEIPT_INVALID');
    }
}

function dockerVolumeIdentity(string $path): array
{
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || ($stat['size'] ?? 0) < 2 || ($stat['size'] ?? 0) > CUTOVER_MAX_SELECTION_BYTES) {
        fail('CUTOVER_DOCKER_INSPECTION_INVALID');
    }
    try {
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('CUTOVER_DOCKER_INSPECTION_INVALID');
    }
    if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 1
        || !is_array($decoded[0]) || !is_string($decoded[0]['Name'] ?? null)
        || ($decoded[0]['Driver'] ?? null) !== 'local' || ($decoded[0]['Scope'] ?? null) !== 'local') {
        fail('CUTOVER_DOCKER_INSPECTION_INVALID');
    }
    $labels = $decoded[0]['Labels'] ?? [];
    if ($labels === null) {
        $labels = [];
    }
    if (!is_array($labels) || ($labels !== [] && array_is_list($labels))) {
        fail('CUTOVER_DOCKER_INSPECTION_INVALID');
    }
    foreach ($labels as $key => $value) {
        if (!is_string($key) || !is_string($value) || str_contains($key, "\0") || str_contains($value, "\0")) {
            fail('CUTOVER_DOCKER_INSPECTION_INVALID');
        }
    }
    ksort($labels, SORT_STRING);
    $labelsSHA = 'sha256:' . hash(
        'sha256',
        json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
    );
    $identity = [
        'driver' => 'local',
        'labels_sha256' => $labelsSHA,
        'name' => $decoded[0]['Name'],
        'scope' => 'local',
    ];

    return [
        'volume_name' => $decoded[0]['Name'],
        'docker_volume_id' => 'docker:sha256:' . hash(
            'sha256',
            json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        ),
        'labels_sha256' => $labelsSHA,
    ];
}

function printDockerVolumeObservation(array $selection, string $artifact, string $sourcePath, string $targetPath): void
{
    if (!array_key_exists($artifact, $selection['artifacts'])) {
        fail('CUTOVER_DOCKER_OBSERVATION_INVALID');
    }
    $expectedSource = $selection['artifacts'][$artifact]['source']['volume'];
    if ($expectedSource === null) {
        if ($sourcePath !== '-') {
            fail('CUTOVER_DOCKER_OBSERVATION_INVALID');
        }
        $source = ['-', '-', '-'];
    } else {
        if ($sourcePath === '-') {
            fail('CUTOVER_DOCKER_OBSERVATION_INVALID');
        }
        $sourceIdentity = dockerVolumeIdentity($sourcePath);
        if ($sourceIdentity !== [
            'volume_name' => $expectedSource['volume_name'],
            'docker_volume_id' => $expectedSource['docker_volume_id'],
            'labels_sha256' => $expectedSource['labels_sha256'],
        ]) {
            fail('CUTOVER_DOCKER_IDENTITY_MISMATCH');
        }
        $source = array_values($sourceIdentity);
    }
    $targetIdentity = dockerVolumeIdentity($targetPath);
    $expectedTarget = $selection['artifacts'][$artifact]['target']['volume'];
    if ($targetIdentity !== [
        'volume_name' => $expectedTarget['volume_name'],
        'docker_volume_id' => $expectedTarget['docker_volume_id'],
        'labels_sha256' => $expectedTarget['labels_sha256'],
    ]) {
        fail('CUTOVER_DOCKER_IDENTITY_MISMATCH');
    }
    fwrite(STDOUT, implode("\t", [$artifact, ...$source, ...array_values($targetIdentity)]) . PHP_EOL);
}

function readHostObservations(string $path, array $selection): void
{
    $stat = @lstat($path);
    $lines = $stat === false ? false : @file($path, FILE_IGNORE_NEW_LINES);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || ($stat['size'] ?? 0) < 1 || ($stat['size'] ?? 0) > 131_072
        || !is_array($lines) || count($lines) !== count(CUTOVER_ARTIFACTS)) {
        fail('CUTOVER_HOST_OBSERVATION_INVALID');
    }
    foreach ($lines as $index => $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 7 || $fields[0] !== CUTOVER_ARTIFACTS[$index]) {
            fail('CUTOVER_HOST_OBSERVATION_INVALID');
        }
        $source = $selection['artifacts'][$fields[0]]['source']['volume'];
        $target = $selection['artifacts'][$fields[0]]['target']['volume'];
        $sourceFields = array_slice($fields, 1, 3);
        if (($source === null && $sourceFields !== ['-', '-', '-'])
            || ($source !== null && $sourceFields !== [
                $source['volume_name'], $source['docker_volume_id'], $source['labels_sha256'],
            ]) || array_slice($fields, 4, 3) !== [
                $target['volume_name'], $target['docker_volume_id'], $target['labels_sha256'],
            ]) {
            fail('CUTOVER_HOST_OBSERVATION_INVALID');
        }
    }
}

function writeHostInspection(array $selection, array $importReceipt, string $output): void
{
    $sources = [];
    $targets = [];
    foreach (CUTOVER_ARTIFACTS as $name) {
        $sources[$name] = $selection['artifacts'][$name]['source']['volume'];
        $targets[$name] = $selection['artifacts'][$name]['target']['volume'];
    }
    $unsigned = [
        'schema_version' => 1,
        'purpose' => 'storage_cutover_host_inspection',
        'job_id' => $selection['job_id'],
        'installation_storage_namespace' => $selection['installation_storage_namespace'],
        'main_manifest_sha256' => $selection['main_manifest_sha256'],
        'authority_revision' => $selection['authority_revision'],
        'candidate' => $selection['candidate'],
        'source_plan_sha256' => $selection['source_plan_sha256'],
        'export_receipt_sha256' => $selection['export_receipt_sha256'],
        'import_receipt_sha256' => $importReceipt['receipt_sha256'],
        'sources' => $sources,
        'targets' => $targets,
        'complete' => true,
    ];
    $inspection = array_slice($unsigned, 0, -1, true)
        + ['inspection_sha256' => protocolHash($unsigned)]
        + ['complete' => true];
    writeCanonicalFile($output, $inspection);
}

function readCanonicalLineJson(string $path, string $code): array
{
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
        || ($stat['size'] ?? 0) < 3 || ($stat['size'] ?? 0) > CUTOVER_MAX_SELECTION_BYTES) {
        fail($code);
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || !str_ends_with($raw, "\n") || str_ends_with($raw, "\n\n")) {
        fail($code);
    }
    $canonical = substr($raw, 0, -1);
    try {
        $decoded = json_decode($canonical, true, 64, JSON_THROW_ON_ERROR);
        $encoded = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (JsonException) {
        fail($code);
    }
    if (!is_array($decoded) || $encoded !== $canonical) {
        fail($code);
    }

    return $decoded;
}

function readImageRuntime(string $versionPath, string $deploymentPath, array $selection): array
{
    foreach ([$versionPath, $deploymentPath] as $path) {
        $stat = @lstat($path);
        if ($stat === false || !is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
            || ($stat['uid'] ?? -1) !== 0 || (($stat['mode'] ?? 0) & 0133) !== 0
            || ($stat['size'] ?? 0) < 2 || ($stat['size'] ?? 0) > 65_536) {
            fail('CUTOVER_IMAGE_RUNTIME_INVALID');
        }
    }
    try {
        $version = json_decode((string) file_get_contents($versionPath), true, 32, JSON_THROW_ON_ERROR);
        $deployment = json_decode((string) file_get_contents($deploymentPath), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('CUTOVER_IMAGE_RUNTIME_INVALID');
    }
    if (!is_array($version) || array_keys($version) !== ['version', 'released_at', 'notes']
        || !validVersion($version['version'] ?? null) || !is_array($deployment)) {
        fail('CUTOVER_IMAGE_RUNTIME_INVALID');
    }
    $fields = array_keys($deployment);
    sort($fields, SORT_STRING);
    $expectedFields = [
        'app_version', 'deployment_id', 'job_id', 'main_manifest_sha256', 'provenance_kind',
        'release_inventory_sha256', 'schema_version', 'storage_layout_generation', 'storage_layout_version',
    ];
    sort($expectedFields, SORT_STRING);
    if ($fields !== $expectedFields || ($deployment['schema_version'] ?? null) !== 1
        || ($deployment['provenance_kind'] ?? null) !== 'upgrade'
        || ($deployment['app_version'] ?? null) !== $version['version']
        || ($deployment['job_id'] ?? null) !== $selection['job_id']
        || ($deployment['main_manifest_sha256'] ?? null) !== $selection['main_manifest_sha256']) {
        fail('CUTOVER_IMAGE_RUNTIME_INVALID');
    }
    $runtime = [
        'app_version' => $deployment['app_version'] ?? null,
        'deployment_id' => $deployment['deployment_id'] ?? null,
        'release_inventory_sha256' => $deployment['release_inventory_sha256'] ?? null,
        'storage_layout_version' => $deployment['storage_layout_version'] ?? null,
        'layout_generation' => $deployment['storage_layout_generation'] ?? null,
    ];
    if ($runtime !== $selection['candidate']) {
        fail('CUTOVER_IMAGE_RUNTIME_INVALID');
    }

    return $runtime;
}

function validatePhpTargetSnapshot(array $snapshot, array $selection): void
{
    exactKeys($snapshot, [
        'schema_version', 'purpose', 'job_id', 'gate_state', 'gate_revision',
        'required_runtime', 'maintenance_fenced',
    ], 'CUTOVER_PHP_TARGET_SNAPSHOT_INVALID');
    $candidate = $selection['candidate'];
    $required = [
        'app_version' => $candidate['app_version'],
        'deployment_id' => $candidate['deployment_id'],
        'storage_layout_version' => $candidate['storage_layout_version'],
        'layout_generation' => $candidate['layout_generation'],
    ];
    if ($snapshot['schema_version'] !== 1 || $snapshot['purpose'] !== 'storage_cutover_php_target_snapshot'
        || $snapshot['job_id'] !== $selection['job_id'] || !is_string($snapshot['gate_state'])
        || $snapshot['gate_state'] !== 'awaiting_deployment'
        || !is_int($snapshot['gate_revision']) || $snapshot['gate_revision'] < 0
        || $snapshot['required_runtime'] !== $required || $snapshot['maintenance_fenced'] !== true) {
        fail('CUTOVER_PHP_TARGET_SNAPSHOT_INVALID');
    }
}

function writeTargetVerification(
    array $selection,
    array $contents,
    array $actualRuntime,
    string $output,
): void {
    $verified = [];
    foreach (CUTOVER_ARTIFACTS as $name) {
        if ($selection['artifacts'][$name]['target']['content'] !== $contents[$name]) {
            fail('CUTOVER_TARGET_CONTENT_INVALID');
        }
        $verified[$name] = $contents[$name];
    }
    $unsigned = [
        'schema_version' => 1,
        'purpose' => 'storage_cutover_target_verification',
        'job_id' => $selection['job_id'],
        'installation_storage_namespace' => $selection['installation_storage_namespace'],
        'main_manifest_sha256' => $selection['main_manifest_sha256'],
        'authority_revision' => $selection['authority_revision'],
        'candidate' => $selection['candidate'],
        'source_plan_sha256' => $selection['source_plan_sha256'],
        'actual_runtime' => $actualRuntime,
        'target_only_gate' => true,
        'maintenance_fenced' => true,
        'import_receipt_sha256' => $selection['import_receipt_sha256'],
        'host_inspection_sha256' => $selection['host_inspection_sha256'],
        'target_authorization_sha256' => $selection['target_authorization_sha256'],
        'verified_contents' => $verified,
        'complete' => true,
    ];
    $verification = array_slice($unsigned, 0, -1, true)
        + ['verification_sha256' => protocolHash($unsigned)]
        + ['complete' => true];
    writeCanonicalFile($output, $verification);
}

$command = $argv[1] ?? '';
if ($command === 'selection-plan' && $argc === 6) {
    [, $selectionPath, $trustPath, $expectedJob, $expectedPhase] = array_slice($argv, 1);
    if (!validUuid($expectedJob) || !in_array($expectedPhase, CUTOVER_PHASES, true)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, $expectedPhase, $keyID, $public);
    printPlan($selection);
    exit(0);
}

if ($command === 'write-export-receipt' && $argc === 7) {
    [, $selectionPath, $trustPath, $expectedJob, $contentPath, $outputPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, 'prepared', $keyID, $public);
    writeExportReceipt($selection, readContentEvidence($contentPath), $outputPath);
    exit(0);
}

if ($command === 'verify-export-receipt' && $argc === 6) {
    [, $selectionPath, $trustPath, $expectedJob, $receiptPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, 'importing', $keyID, $public);
    validateExportReceiptAgainstSelection($selection, readCanonicalEvidence($receiptPath));
    exit(0);
}

if ($command === 'write-import-receipt' && $argc === 7) {
    [, $selectionPath, $trustPath, $expectedJob, $contentPath, $outputPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, 'importing', $keyID, $public);
    writeImportReceipt($selection, readContentEvidence($contentPath), $outputPath);
    exit(0);
}

if ($command === 'docker-volume-identity' && $argc === 6) {
    $identity = dockerVolumeIdentity($argv[2]);
    if ($identity !== [
        'volume_name' => $argv[3],
        'docker_volume_id' => $argv[4],
        'labels_sha256' => $argv[5],
    ]) {
        fail('CUTOVER_DOCKER_IDENTITY_MISMATCH');
    }
    fwrite(STDOUT, implode("\t", $identity) . PHP_EOL);
    exit(0);
}

if ($command === 'docker-volume-observation' && $argc === 9) {
    [, $selectionPath, $trustPath, $expectedJob, $expectedPhase, $artifact,
        $sourcePath, $targetPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob) || !in_array($expectedPhase, CUTOVER_PHASES, true)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, $expectedPhase, $keyID, $public);
    printDockerVolumeObservation($selection, $artifact, $sourcePath, $targetPath);
    exit(0);
}

if ($command === 'write-host-inspection' && $argc === 8) {
    [, $selectionPath, $trustPath, $expectedJob, $importPath, $observationsPath, $outputPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, 'importing', $keyID, $public);
    $importReceipt = readCanonicalEvidence($importPath);
    validateImportReceiptAgainstSelection($selection, $importReceipt);
    readHostObservations($observationsPath, $selection);
    writeHostInspection($selection, $importReceipt, $outputPath);
    exit(0);
}

if ($command === 'write-target-verification' && $argc === 10) {
    [, $selectionPath, $trustPath, $expectedJob, $contentPath, $snapshotPath,
        $versionPath, $deploymentPath, $outputPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, 'provisioned', $keyID, $public);
    $snapshot = readCanonicalLineJson($snapshotPath, 'CUTOVER_PHP_TARGET_SNAPSHOT_INVALID');
    validatePhpTargetSnapshot($snapshot, $selection);
    writeTargetVerification(
        $selection,
        readContentEvidence($contentPath),
        readImageRuntime($versionPath, $deploymentPath, $selection),
        $outputPath,
    );
    exit(0);
}

if ($command === 'verify-image-runtime' && $argc === 8) {
    [, $selectionPath, $trustPath, $expectedJob, $expectedPhase,
        $versionPath, $deploymentPath] = array_slice($argv, 1);
    if (!validUuid($expectedJob) || !in_array($expectedPhase, CUTOVER_PHASES, true)) {
        fail('CUTOVER_VALIDATOR_REQUEST_INVALID');
    }
    $trust = readCanonicalTrust($trustPath);
    [$keyID, $public] = validateTrust($trust);
    $selection = readCanonicalFile(
        $selectionPath,
        'CUTOVER_SELECTION_FILE_INVALID',
        'CUTOVER_SELECTION_CANONICAL_INVALID',
    );
    validateSelection($selection, $expectedJob, $expectedPhase, $keyID, $public);
    readImageRuntime($versionPath, $deploymentPath, $selection);
    exit(0);
}

fail('CUTOVER_VALIDATOR_USAGE_INVALID');
