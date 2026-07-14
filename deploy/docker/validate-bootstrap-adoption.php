<?php

declare(strict_types=1);

const BOOTSTRAP_ADOPT_MAX_JSON_BYTES = 1048576;
const BOOTSTRAP_ADOPT_MAX_TREE_ENTRIES = 100000;
const BOOTSTRAP_ADOPT_MAX_TREE_BYTES = 536870912;
const BOOTSTRAP_ADOPT_MAX_MANIFEST_ROW_BYTES = 8448;
const BOOTSTRAP_ADOPT_MAX_MANIFEST_BYTES = 844800000;
const BOOTSTRAP_ADOPT_MAX_MANIFEST_FILE_BYTES = 536870912;
const BOOTSTRAP_ADOPT_MAX_MANIFEST_TREE_BYTES = 4294967296;
const BOOTSTRAP_ADOPT_MARKER = '.mallbase-layout-marker.json';

function bootstrapAdoptFail(string $code): never
{
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}

/** @param array<mixed> $value */
function bootstrapAdoptCanonical(array $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
}

function bootstrapAdoptHash(string $bytes): string
{
    return 'sha256:' . hash('sha256', $bytes);
}

function bootstrapAdoptIsHash(mixed $value): bool
{
    return is_string($value) && preg_match('/^sha256:[0-9a-f]{64}$/D', $value) === 1;
}

function bootstrapAdoptIsUuid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

/** @return array<string,mixed> */
function bootstrapAdoptReadBootstrapID(string $path): array
{
    $value = bootstrapAdoptReadCanonical($path);
    if (array_keys($value) !== [
        'schema_version', 'operation_id', 'installation_storage_namespace', 'agent_uid', 'shared_gid',
    ] || $value['schema_version'] !== 1 || !bootstrapAdoptIsUuid($value['operation_id'])
        || !is_string($value['installation_storage_namespace'])
        || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $value['installation_storage_namespace']) !== 1
        || !is_int($value['agent_uid']) || $value['agent_uid'] <= 0
        || !is_int($value['shared_gid']) || $value['shared_gid'] <= 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_BOOTSTRAP_ID_INVALID');
    }

    return $value;
}

/** @return array<string,mixed> */
function bootstrapAdoptReadLayout(string $path, string $operation): array
{
    $layout = bootstrapAdoptReadCanonical($path);
    if (!bootstrapAdoptIsUuid($operation) || ($layout['schema_version'] ?? null) !== 1
        || !is_string($layout['installation_storage_namespace'] ?? null)
        || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $layout['installation_storage_namespace']) !== 1
        || !is_int($layout['authority_revision'] ?? null) || $layout['authority_revision'] <= 0
        || !is_int($layout['next_layout_generation'] ?? null) || $layout['next_layout_generation'] <= 0
        || !is_string($layout['state'] ?? null)
        || !in_array($layout['state'], [
            'fresh', 'legacy_required', 'provisioning', 'provisioned', 'ready', 'recovery_required',
        ], true)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
    }
    $adoption = $layout['adoption'] ?? null;
    if (!is_array($adoption) || ($adoption['operation_id'] ?? null) !== $operation
        || ($layout['migration_id'] ?? null) !== $operation
        || !is_int($adoption['layout_generation'] ?? null)
        || $adoption['layout_generation'] <= 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_OPERATION_MISMATCH');
    }
    $phase = $layout['adoption_phase'] ?? null;
    if ($layout['state'] === 'ready') {
        if ($phase !== null || array_key_exists('candidate', $layout)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
        }
    } elseif (!is_string($phase) || !in_array($phase, [
        'prepared', 'importing', 'target_confirmed', 'aborted', 'source_recovery',
    ], true)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
    }

    return $layout;
}

function bootstrapAdoptLayoutField(array $layout, string $field): string
{
    $adoption = $layout['adoption'];

    return match ($field) {
        'state' => (string) $layout['state'],
        'phase' => isset($layout['adoption_phase']) ? (string) $layout['adoption_phase'] : '-',
        'authority_revision' => (string) $layout['authority_revision'],
        'namespace' => (string) $layout['installation_storage_namespace'],
        'operation_id' => (string) $adoption['operation_id'],
        'layout_generation' => (string) $adoption['layout_generation'],
        'target_authorization_sha256' => isset($adoption['target_authorization_sha256'])
            ? (string) $adoption['target_authorization_sha256'] : '-',
        default => bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
    };
}

/** @return array<string,mixed> */
function bootstrapAdoptReadLayoutSummary(string $path): array
{
    $layout = bootstrapAdoptReadCanonical($path);
    if (($layout['schema_version'] ?? null) !== 1
        || !is_string($layout['installation_storage_namespace'] ?? null)
        || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $layout['installation_storage_namespace']) !== 1
        || !is_int($layout['authority_revision'] ?? null) || $layout['authority_revision'] <= 0
        || !is_int($layout['next_layout_generation'] ?? null) || $layout['next_layout_generation'] <= 0
        || !is_string($layout['state'] ?? null)
        || !in_array($layout['state'], [
            'fresh', 'legacy_required', 'provisioning', 'provisioned', 'ready', 'recovery_required',
        ], true)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
    }
    $operation = '-';
    $phase = '-';
    if (isset($layout['adoption'])) {
        $adoption = $layout['adoption'];
        if (!is_array($adoption) || !bootstrapAdoptIsUuid($adoption['operation_id'] ?? null)
            || !is_int($adoption['layout_generation'] ?? null) || $adoption['layout_generation'] <= 0
            || ($layout['migration_id'] ?? null) !== $adoption['operation_id']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
        }
        $operation = $adoption['operation_id'];
        if (isset($layout['adoption_phase'])) {
            if (!is_string($layout['adoption_phase']) || !in_array($layout['adoption_phase'], [
                'prepared', 'importing', 'target_confirmed', 'aborted', 'source_recovery',
            ], true)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
            }
            $phase = $layout['adoption_phase'];
        }
    } elseif (isset($layout['adoption_phase'])) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
    }

    return [
        'state' => $layout['state'],
        'phase' => $phase,
        'authority_revision' => $layout['authority_revision'],
        'operation_id' => $operation,
    ];
}

function bootstrapAdoptLayoutVolumeField(
    array $layout,
    string $artifact,
    string $field,
): string {
    if (!in_array($artifact, [
        'cert', 'demo', 'install', 'local_storage', 'public_storage', 'runtime_backup', 'uploads',
    ], true) || !in_array($field, ['volume_name', 'docker_volume_id'], true)
        || !is_array($layout['candidate']['volumes'][$artifact] ?? null)
        || !is_string($layout['candidate']['volumes'][$artifact][$field] ?? null)
        || $layout['candidate']['volumes'][$artifact][$field] === '') {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_LAYOUT_INVALID');
    }

    return $layout['candidate']['volumes'][$artifact][$field];
}

/** @return array{receipt_id:string,image_id:string,display_tag:string} */
function bootstrapAdoptReadBuildOutput(string $path): array
{
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $stat['nlink'] !== 1 || (int) $stat['size'] <= 0 || (int) $stat['size'] > 4096) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMAGE_RECORD_INVALID');
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || !preg_match(
        '/^MALLBASE_IMAGE_RECEIPT_ID=([0-9a-f]{32})\\nMALLBASE_BACKEND_IMAGE_ID=(sha256:[0-9a-f]{64})\\nMALLBASE_IMAGE_DISPLAY_TAG=([^\\x00-\\x20\\x7f]{1,255})\\n$/D',
        $bytes,
        $matches,
    )) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMAGE_RECORD_INVALID');
    }

    return ['receipt_id' => $matches[1], 'image_id' => $matches[2], 'display_tag' => $matches[3]];
}

/** @return array<string,mixed> */
function bootstrapAdoptReadRetentionProbe(string $path): array
{
    $probe = bootstrapAdoptReadCanonical($path, 0600);
    if (array_keys($probe) !== [
        'schema_version', 'purpose', 'configured_local_root', 'local_root_classification',
        'build_context_relative_root', 'local_source_path', 'local_source_content_root',
        'environment_source_path', 'environment_sha256', 'artifacts',
        'source_artifacts', 'source_content_roots', 'expected_uploads_content_root',
        'old_app_uid', 'old_app_gid',
    ] || $probe['schema_version'] !== 1
        || $probe['purpose'] !== 'storage_bootstrap_retention_probe'
        || !is_string($probe['configured_local_root']) || $probe['configured_local_root'] === ''
        || !is_string($probe['local_root_classification'])
        || !is_string($probe['environment_source_path'])
        || !in_array($probe['environment_source_path'], [
            '/app/.mallbase-env/backend.env', '/app/.env',
        ], true) || !bootstrapAdoptIsHash($probe['environment_sha256'])
        || !is_int($probe['old_app_uid']) || $probe['old_app_uid'] < 0
        || !is_int($probe['old_app_gid']) || $probe['old_app_gid'] < 0) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }
    $configured = $probe['configured_local_root'];
    if ($probe['local_root_classification'] === 'canonical') {
        if ($configured !== 'uploads' || $probe['build_context_relative_root'] !== null
            || $probe['local_source_path'] !== null || $probe['local_source_content_root'] !== null) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
        }
    } elseif ($probe['local_root_classification'] === 'relative') {
        if (!is_string($probe['build_context_relative_root'])
            || $probe['build_context_relative_root'] !== $configured
            || $probe['local_source_path'] !== '/app/public/' . $configured
            || !bootstrapAdoptIsHash($probe['local_source_content_root'])) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
        }
        bootstrapAdoptAssertRelativePath($configured);
        if ($configured === '.' || str_contains($configured, '\\') || str_ends_with($configured, '/')
            || str_contains($configured, ':')) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
        }
        foreach (explode('/', $configured) as $component) {
            if (in_array($component, ['', '.', '..', '.git', '.env', '.mallbase-sealed-context.json'], true)) {
                bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
            }
        }
        foreach (['uploads', 'storage', 'static/demo'] as $fixedRoot) {
            if ($configured === $fixedRoot || str_starts_with($configured, $fixedRoot . '/')
                || str_starts_with($fixedRoot, $configured . '/')) {
                bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
            }
        }
    } else {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }

    $artifactPaths = [
        'cert' => '/app/storage/cert',
        'demo' => '/app/public/static/demo',
        'public_storage' => '/app/public/storage',
    ];
    if (!is_array($probe['artifacts']) || array_keys($probe['artifacts']) !== array_keys($artifactPaths)) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }
    foreach ($artifactPaths as $artifact => $expectedPath) {
        $value = $probe['artifacts'][$artifact];
        if (!is_array($value) || array_keys($value) !== ['present', 'path', 'content_root']
            || !is_bool($value['present']) || $value['path'] !== $expectedPath
            || !bootstrapAdoptIsHash($value['content_root'])) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
        }
    }
    $sourcePaths = [
        'install' => '/app/runtime/install',
        'local_storage' => '/app/runtime/storage',
        'runtime_backup' => '/app/runtime/backup',
        'uploads' => '/app/public/uploads',
    ];
    if (!is_array($probe['source_artifacts'])
        || array_keys($probe['source_artifacts']) !== array_keys($sourcePaths)) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }
    foreach ($sourcePaths as $artifact => $expectedPath) {
        $value = $probe['source_artifacts'][$artifact];
        if (!is_array($value) || array_keys($value) !== ['present', 'path', 'content_root']
            || !is_bool($value['present']) || $value['path'] !== $expectedPath
            || !bootstrapAdoptIsHash($value['content_root'])
            || $value['content_root'] !== ($probe['source_content_roots'][$artifact] ?? null)) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
        }
    }
    if ($probe['source_artifacts']['uploads']['present'] !== true) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }
    if (!is_array($probe['source_content_roots'])
        || array_keys($probe['source_content_roots']) !== [
            'install', 'local_storage', 'runtime_backup', 'uploads',
        ]) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }
    foreach ($probe['source_content_roots'] as $hash) {
        if (!bootstrapAdoptIsHash($hash)) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
        }
    }
    if (!bootstrapAdoptIsHash($probe['expected_uploads_content_root'])
        || ($probe['local_root_classification'] === 'canonical'
            && $probe['expected_uploads_content_root'] !== $probe['source_content_roots']['uploads'])) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_PROBE_INVALID');
    }

    return $probe;
}

/** @param array<string,mixed> $probe */
function bootstrapAdoptRetentionProbeField(array $probe, string $field, ?string $artifact): string
{
    return match ($field) {
        'configured-root' => (string) $probe['configured_local_root'],
        'classification' => (string) $probe['local_root_classification'],
        'build-context-root' => is_string($probe['build_context_relative_root'])
            ? $probe['build_context_relative_root'] : '-',
        'local-source' => is_string($probe['local_source_path']) ? $probe['local_source_path'] : '-',
        'local-source-root' => is_string($probe['local_source_content_root'])
            ? $probe['local_source_content_root'] : '-',
        'env-source' => (string) $probe['environment_source_path'],
        'env-sha256' => (string) $probe['environment_sha256'],
        'old-uid' => (string) $probe['old_app_uid'],
        'old-gid' => (string) $probe['old_app_gid'],
        'artifact-present' => isset($probe['artifacts'][$artifact])
            ? ($probe['artifacts'][$artifact]['present'] ? 'true' : 'false')
            : bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
        'artifact-path' => isset($probe['artifacts'][$artifact])
            ? (string) $probe['artifacts'][$artifact]['path']
            : bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
        'artifact-root' => isset($probe['artifacts'][$artifact])
            ? (string) $probe['artifacts'][$artifact]['content_root']
            : bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
        'source-root' => isset($probe['source_content_roots'][$artifact])
            ? (string) $probe['source_content_roots'][$artifact]
            : bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
        'source-present' => isset($probe['source_artifacts'][$artifact])
            ? ($probe['source_artifacts'][$artifact]['present'] ? 'true' : 'false')
            : bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
        'source-path' => isset($probe['source_artifacts'][$artifact])
            ? (string) $probe['source_artifacts'][$artifact]['path']
            : bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
        'expected-uploads-root' => (string) $probe['expected_uploads_content_root'],
        default => bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE'),
    };
}

/** @return array<string,mixed> */
function bootstrapAdoptReadDockerObject(string $path): array
{
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $stat['nlink'] !== 1 || (int) $stat['size'] <= 0
        || (int) $stat['size'] > BOOTSTRAP_ADOPT_MAX_JSON_BYTES) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_DOCKER_INSPECT_INVALID');
    }
    try {
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_DOCKER_INSPECT_INVALID');
    }
    if (!is_array($decoded) || count($decoded) !== 1 || !is_array($decoded[0]) || array_is_list($decoded[0])) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_DOCKER_INSPECT_INVALID');
    }

    return $decoded[0];
}

/** @return array{container_id:string,image_id:string,runtime_name:string,uploads_name:string} */
function bootstrapAdoptContainerObservation(string $path, string $requestedId): array
{
    if (preg_match('/^[0-9a-f]{12,64}$/D', $requestedId) !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_CONTAINER_ID_INVALID');
    }
    $container = bootstrapAdoptReadDockerObject($path);
    $containerId = $container['Id'] ?? null;
    $imageId = $container['Image'] ?? null;
    if (!is_string($containerId) || preg_match('/^[0-9a-f]{64}$/D', $containerId) !== 1
        || !str_starts_with($containerId, $requestedId)
        || !is_string($imageId) || preg_match('/^sha256:[0-9a-f]{64}$/D', $imageId) !== 1
        || ($container['State']['Running'] ?? null) !== true
        || ($container['State']['Paused'] ?? null) !== false
        || !is_array($container['Mounts'] ?? null)) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_CONTAINER_STATE_INVALID');
    }
    $mounts = [];
    foreach ($container['Mounts'] as $mount) {
        if (!is_array($mount) || !is_string($mount['Destination'] ?? null)) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_CONTAINER_MOUNT_INVALID');
        }
        $destination = $mount['Destination'];
        if (!in_array($destination, ['/app/runtime', '/app/public/uploads'], true)) {
            continue;
        }
        if (isset($mounts[$destination]) || ($mount['Type'] ?? null) !== 'volume'
            || !is_string($mount['Name'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $mount['Name']) !== 1
            || ($mount['RW'] ?? null) !== true) {
            bootstrapAdoptFail('BOOTSTRAP_RETENTION_CONTAINER_MOUNT_INVALID');
        }
        $mounts[$destination] = $mount['Name'];
    }
    if (count($mounts) !== 2 || !isset($mounts['/app/runtime'], $mounts['/app/public/uploads'])
        || $mounts['/app/runtime'] === $mounts['/app/public/uploads']) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_CONTAINER_MOUNT_INVALID');
    }

    return [
        'container_id' => $containerId,
        'image_id' => $imageId,
        'runtime_name' => $mounts['/app/runtime'],
        'uploads_name' => $mounts['/app/public/uploads'],
    ];
}

/** @return array{volume_name:string,docker_volume_id:string,labels_sha256:string} */
function bootstrapAdoptDockerVolumeObservation(string $path, string $expectedName): array
{
    $volume = bootstrapAdoptReadDockerObject($path);
    $name = $volume['Name'] ?? null;
    $driver = $volume['Driver'] ?? null;
    $scope = $volume['Scope'] ?? null;
    $options = $volume['Options'] ?? null;
    $labels = $volume['Labels'] ?? null;
    if (!is_string($name) || $name !== $expectedName || !is_string($driver) || $driver !== 'local'
        || !is_string($scope) || $scope !== 'local'
        || !($options === null || $options === []) || !($labels === null || is_array($labels))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_DOCKER_VOLUME_INVALID');
    }
    $normalizedLabels = [];
    foreach ($labels ?? [] as $key => $value) {
        if (!is_string($key) || $key === '' || !is_string($value)
            || preg_match('/[\x00-\x1f\x7f]/', $key . $value) === 1) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_DOCKER_VOLUME_INVALID');
        }
        $normalizedLabels[$key] = $value;
    }
    ksort($normalizedLabels, SORT_STRING);
    $labelsSha256 = bootstrapAdoptHash(
        $normalizedLabels === [] ? "{}\n" : bootstrapAdoptCanonical($normalizedLabels),
    );
    $identity = bootstrapAdoptHash(bootstrapAdoptCanonical([
        'driver' => $driver,
        'labels_sha256' => $labelsSha256,
        'name' => $name,
        'scope' => $scope,
    ]));

    return [
        'volume_name' => $name,
        'docker_volume_id' => 'docker:' . $identity,
        'labels_sha256' => $labelsSha256,
    ];
}

/** @return array{volume_name:string,docker_volume_id:string,labels_sha256:string,content_root:string} */
function bootstrapAdoptBindObservation(
    string $path,
    string $artifact,
    int $agentUid,
    int $sharedGid,
): array {
    if (!in_array($artifact, ['cert', 'demo', 'public_storage'], true)
        || $agentUid <= 0 || $sharedGid <= 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_BIND_INVALID');
    }
    $stat = bootstrapAdoptAssertDirectory($path);
    if ((int) $stat['uid'] !== $agentUid || (int) $stat['gid'] !== $sharedGid
        || !in_array($stat['mode'] & 07777, [02770, 03770], true)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_BIND_INVALID');
    }
    $tree = bootstrapAdoptTree($path, false);
    if (count($tree['entries']) !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_BIND_NOT_EMPTY');
    }
    $identity = bootstrapAdoptHash(bootstrapAdoptCanonical([
        'device_id' => (int) $stat['dev'],
        'inode' => (int) $stat['ino'],
        'relative_role' => $artifact,
    ]));
    $policy = bootstrapAdoptHash(bootstrapAdoptCanonical([
        'agent_uid' => $agentUid,
        'artifact' => $artifact,
        'relative_role' => $artifact,
        'root_mode' => '03770',
        'shared_gid' => $sharedGid,
        'storage_kind' => 'bind',
    ]));

    return [
        'volume_name' => 'mallbase_bind_' . $artifact,
        'docker_volume_id' => 'bind:' . $identity,
        'labels_sha256' => $policy,
        'content_root' => $tree['hash'],
    ];
}

/** @return array<string,mixed> */
function bootstrapAdoptReadSourceControl(string $path, array $requiredKeys): array
{
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $stat['nlink'] !== 1 || (int) $stat['size'] <= 0
        || (int) $stat['size'] > BOOTSTRAP_ADOPT_MAX_JSON_BYTES) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || $bytes === '' || str_contains($bytes, "\r")) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    $raw = str_ends_with($bytes, "\n") ? substr($bytes, 0, -1) : $bytes;
    if ($raw === '' || str_contains($raw, "\n")) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    try {
        $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    if (!is_array($document) || array_is_list($document)
        || array_keys($document) !== $requiredKeys
        || json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $raw) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }

    return $document;
}

/** @return array{version:string,payload_sha256:string,deployment_id:string} */
function bootstrapAdoptSourceIdentity(string $projectRoot): array
{
    $inventoryPath = rtrim($projectRoot, '/') . '/.mallbase-release-inventory.json';
    $envelope = bootstrapAdoptReadSourceControl($inventoryPath, [
        'schema_version', 'payload_sha256', 'entry_count', 'signing_key_id', 'signature', 'payload_base64',
    ]);
    if ($envelope['schema_version'] !== 1
        || !is_string($envelope['payload_sha256'])
        || preg_match('/^[0-9a-f]{64}$/D', $envelope['payload_sha256']) !== 1
        || !is_int($envelope['entry_count']) || $envelope['entry_count'] <= 0
        || !is_string($envelope['signing_key_id']) || $envelope['signing_key_id'] === ''
        || !is_string($envelope['signature']) || $envelope['signature'] === ''
        || !is_string($envelope['payload_base64']) || $envelope['payload_base64'] === '') {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    $payload = base64_decode($envelope['payload_base64'], true);
    if (!is_string($payload) || $payload === '' || base64_encode($payload) !== $envelope['payload_base64']
        || !hash_equals($envelope['payload_sha256'], hash('sha256', $payload))
        || str_contains($payload, "\n") || str_contains($payload, "\r")) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    try {
        $inventory = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }
    if (!is_array($inventory) || array_is_list($inventory)
        || array_keys($inventory) !== ['schema_version', 'signing_key_id', 'app_code', 'version', 'entries']
        || json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $payload
        || $inventory['schema_version'] !== 1
        || $inventory['signing_key_id'] !== $envelope['signing_key_id']
        || !is_string($inventory['app_code']) || $inventory['app_code'] === ''
        || !is_string($inventory['version'])
        || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,127}$/D', $inventory['version']) !== 1
        || !is_array($inventory['entries']) || !array_is_list($inventory['entries'])
        || count($inventory['entries']) !== $envelope['entry_count']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
    }

    $deploymentId = '';
    $markerPath = rtrim($projectRoot, '/') . '/.mallbase-deployment.json';
    if (file_exists($markerPath) || is_link($markerPath)) {
        $markerStat = @lstat($markerPath);
        if (!is_array($markerStat) || ($markerStat['mode'] & 0170000) !== 0100000 || is_link($markerPath)
            || (int) $markerStat['nlink'] !== 1 || (int) $markerStat['size'] <= 0
            || (int) $markerStat['size'] > BOOTSTRAP_ADOPT_MAX_JSON_BYTES) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
        }
        $markerBytes = @file_get_contents($markerPath);
        if (!is_string($markerBytes) || $markerBytes === '' || str_contains($markerBytes, "\r")) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
        }
        $markerRaw = str_ends_with($markerBytes, "\n") ? substr($markerBytes, 0, -1) : $markerBytes;
        try {
            $marker = json_decode($markerRaw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
        }
        if (!is_array($marker) || array_is_list($marker)
            || json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $markerRaw
            || ($marker['schema_version'] ?? null) !== 1
            || ($marker['app_version'] ?? null) !== $inventory['version']
            || ($marker['release_inventory_sha256'] ?? null) !== $envelope['payload_sha256']
            || !bootstrapAdoptIsUuid($marker['deployment_id'] ?? null)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_INVALID');
        }
        $deploymentId = $marker['deployment_id'];
    }

    return [
        'version' => $inventory['version'],
        'payload_sha256' => 'sha256:' . $envelope['payload_sha256'],
        'deployment_id' => $deploymentId,
    ];
}

/** @return array<string,mixed> */
function bootstrapAdoptSourceVolume(
    string $artifact,
    array $observation,
    string $contentRoot,
): array {
    return [
        'artifact' => $artifact,
        'volume_name' => $observation['volume_name'],
        'docker_volume_id' => $observation['docker_volume_id'],
        'labels_sha256' => $observation['labels_sha256'],
        'content_root' => $contentRoot,
    ];
}

/** @return array<string,mixed> */
function bootstrapAdoptCandidateVolume(
    string $artifact,
    string $sourceMode,
    array $observation,
    string $contentRoot,
    bool $emptyAtPrepare,
): array {
    return [
        'artifact' => $artifact,
        'source_mode' => $sourceMode,
        'volume_name' => $observation['volume_name'],
        'docker_volume_id' => $observation['docker_volume_id'],
        'labels_sha256' => $observation['labels_sha256'],
        'marker_id' => '',
        'marker_sha256' => '',
        'expected_content_root' => $contentRoot,
        'empty_at_prepare' => $emptyAtPrepare,
    ];
}

function bootstrapAdoptAssertUploadsManifestPath(string $path): void
{
    if ($path === '' || strlen($path) > 4096 || preg_match('//u', $path) !== 1
        || str_contains($path, "\\") || str_starts_with($path, '/') || str_ends_with($path, '/')
        || preg_match('/[\x00-\x1f\x7f:]/', $path) === 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
    }
    foreach (explode('/', $path) as $component) {
        if ($component === '' || $component === '.' || $component === '..'
            || in_array($component, ['.git', '.env', '.mallbase-sealed-context.json'], true)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
    }
    if (basename($path) === BOOTSTRAP_ADOPT_MARKER) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
    }
}

/**
 * Validate and optionally copy a canonical uploads manifest without loading it
 * into PHP memory. The returned fingerprint is bound to the opened inode and
 * the path is re-checked after EOF to reject replacement during validation.
 *
 * @param resource|null $sink
 * @return array{bytes:int,entries:int,sha256:string}
 */
function bootstrapAdoptStreamUploadsManifest(string $path, string $expectedRoot, $sink = null): array
{
    $before = @lstat($path);
    if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $before['nlink'] !== 1 || (int) $before['size'] <= 0
        || (int) $before['size'] > BOOTSTRAP_ADOPT_MAX_MANIFEST_BYTES) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
    }
    $handle = @fopen($path, 'rb');
    $opened = is_resource($handle) ? @fstat($handle) : false;
    if (!is_resource($handle) || !is_array($opened)
        || (int) $opened['dev'] !== (int) $before['dev']
        || (int) $opened['ino'] !== (int) $before['ino']
        || (int) $opened['size'] !== (int) $before['size']
        || (int) $opened['nlink'] !== 1) {
        if (is_resource($handle)) fclose($handle);
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
    }

    $hasher = hash_init('sha256');
    $entries = 0;
    $bytes = 0;
    $fileBytes = 0;
    $previous = null;
    $paths = [];
    while (($line = fgets($handle, BOOTSTRAP_ADOPT_MAX_MANIFEST_ROW_BYTES + 1)) !== false) {
        $lineBytes = strlen($line);
        if ($lineBytes === 0 || $lineBytes > BOOTSTRAP_ADOPT_MAX_MANIFEST_ROW_BYTES
            || !str_ends_with($line, "\n") || str_contains($line, "\r")
            || ++$entries > BOOTSTRAP_ADOPT_MAX_TREE_ENTRIES
            || $bytes > BOOTSTRAP_ADOPT_MAX_MANIFEST_BYTES - $lineBytes
            || ($previous !== null && strcmp($previous, $line) >= 0)) {
            fclose($handle);
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
        $bytes += $lineBytes;
        $raw = substr($line, 0, -1);
        try {
            $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            fclose($handle);
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
        if (!is_array($row) || !array_is_list($row)
            || json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $raw
            || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)) {
            fclose($handle);
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
        $kind = $row[0];
        $entryPath = $row[1];
        if ($entryPath === '.') {
            if ($kind !== 'directory' || count($row) !== 2) {
                fclose($handle);
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
            }
        } else {
            bootstrapAdoptAssertUploadsManifestPath($entryPath);
        }
        if (isset($paths[$entryPath])) {
            fclose($handle);
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
        $paths[$entryPath] = true;
        if ($kind === 'directory') {
            if (count($row) !== 2) {
                fclose($handle);
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
            }
        } elseif ($kind === 'file') {
            if (count($row) !== 4 || !is_int($row[2]) || $row[2] < 0
                || $row[2] > BOOTSTRAP_ADOPT_MAX_MANIFEST_FILE_BYTES
                || $row[2] > BOOTSTRAP_ADOPT_MAX_MANIFEST_TREE_BYTES - $fileBytes
                || !bootstrapAdoptIsHash($row[3])) {
                fclose($handle);
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
            }
            $fileBytes += $row[2];
        } else {
            fclose($handle);
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
        hash_update($hasher, $line);
        if (is_resource($sink)) {
            $offset = 0;
            while ($offset < $lineBytes) {
                $written = fwrite($sink, substr($line, $offset));
                if (!is_int($written) || $written <= 0) {
                    fclose($handle);
                    bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_PUBLISH_FAILED');
                }
                $offset += $written;
            }
        }
        $previous = $line;
    }
    $eof = feof($handle);
    $afterOpen = @fstat($handle);
    fclose($handle);
    $afterPath = @lstat($path);
    $sha256 = 'sha256:' . hash_final($hasher);
    if (!$eof || $entries === 0 || !isset($paths['.']) || $bytes !== (int) $before['size']
        || !is_array($afterOpen) || !is_array($afterPath) || is_link($path)
        || (int) $afterOpen['dev'] !== (int) $before['dev']
        || (int) $afterOpen['ino'] !== (int) $before['ino']
        || (int) $afterOpen['size'] !== (int) $before['size']
        || (int) $afterOpen['nlink'] !== 1
        || (int) $afterPath['dev'] !== (int) $before['dev']
        || (int) $afterPath['ino'] !== (int) $before['ino']
        || (int) $afterPath['size'] !== (int) $before['size']
        || (int) $afterPath['nlink'] !== 1 || !hash_equals($expectedRoot, $sha256)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
    }

    return ['bytes' => $bytes, 'entries' => $entries, 'sha256' => $sha256];
}

function bootstrapAdoptWriteSource(
    string $projectRoot,
    string $probePath,
    string $containerPath,
    string $runtimeVolumePath,
    string $uploadsVolumePath,
    string $uploadsManifestPath,
    string $retentionRoot,
    string $targetCert,
    string $targetDemo,
    string $targetPublicStorage,
    string $operation,
    int $agentUid,
    int $appUid,
    int $sharedGid,
    string $outputPath,
): void {
    if (!bootstrapAdoptIsUuid($operation) || $agentUid <= 0 || $appUid <= 0
        || $agentUid === $appUid || $sharedGid <= 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_AUTHORITY_INVALID');
    }
    $projectRootReal = realpath($projectRoot);
    if (!is_string($projectRootReal) || !is_dir($projectRootReal) || is_link($projectRoot)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_AUTHORITY_INVALID');
    }
    $probe = bootstrapAdoptReadRetentionProbe($probePath);
    $containerDocument = bootstrapAdoptReadDockerObject($containerPath);
    $fullContainerId = $containerDocument['Id'] ?? null;
    if (!is_string($fullContainerId)) {
        bootstrapAdoptFail('BOOTSTRAP_RETENTION_CONTAINER_STATE_INVALID');
    }
    // container-field already bound the requested ID before this writer. Re-bind
    // using the exact full ID to prevent a changed inspect file from being used.
    $container = bootstrapAdoptContainerObservation($containerPath, $fullContainerId);
    $runtime = bootstrapAdoptDockerVolumeObservation($runtimeVolumePath, $container['runtime_name']);
    $uploads = bootstrapAdoptDockerVolumeObservation($uploadsVolumePath, $container['uploads_name']);
    $targets = [
        'cert' => bootstrapAdoptBindObservation($targetCert, 'cert', $agentUid, $sharedGid),
        'demo' => bootstrapAdoptBindObservation($targetDemo, 'demo', $agentUid, $sharedGid),
        'public_storage' => bootstrapAdoptBindObservation($targetPublicStorage, 'public_storage', $agentUid, $sharedGid),
    ];
    $identity = bootstrapAdoptSourceIdentity($projectRootReal);
    bootstrapAdoptAssertDirectory($retentionRoot);
    $env = $retentionRoot . '/env/backend.env';
    $envStat = @lstat($env);
    if (!is_array($envStat) || ($envStat['mode'] & 0170000) !== 0100000 || is_link($env)
        || (int) $envStat['nlink'] !== 1 || ($envStat['mode'] & 0777) !== 0600
        || !hash_equals($probe['environment_sha256'], bootstrapAdoptHash((string) @file_get_contents($env)))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_EXPORT_INVALID');
    }
    foreach (['cert' => 'cert', 'demo' => 'demo', 'public_storage' => 'public-storage'] as $artifact => $leaf) {
        $path = $retentionRoot . '/' . $leaf;
        if ($probe['artifacts'][$artifact]['present']) {
            if (bootstrapAdoptTree($path)['hash'] !== $probe['artifacts'][$artifact]['content_root']) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_EXPORT_INVALID');
            }
        } elseif (file_exists($path) || is_link($path)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_EXPORT_INVALID');
        }
    }
    $custom = $retentionRoot . '/custom-upload';
    if ($probe['local_root_classification'] === 'relative') {
        if (bootstrapAdoptTree($custom)['hash'] !== $probe['local_source_content_root']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_EXPORT_INVALID');
        }
        bootstrapAdoptPublishManifest(
            dirname($outputPath) . '/uploads.manifest.jsonl',
            $uploadsManifestPath,
            $probe['source_content_roots']['uploads'],
            $agentUid,
            $sharedGid,
        );
    } elseif (file_exists($custom) || is_link($custom)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_EXPORT_INVALID');
    } elseif ($uploadsManifestPath !== '-'
        || file_exists(dirname($outputPath) . '/uploads.manifest.jsonl')
        || is_link(dirname($outputPath) . '/uploads.manifest.jsonl')) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_CONFLICT');
    }

    $oldUid = $probe['old_app_uid'];
    $oldGid = $probe['old_app_gid'];
    $oldIdentityRoot = $oldUid === 0;
    $compatible = $oldUid === $appUid && $oldGid === $sharedGid;
    if (!$oldIdentityRoot && !$compatible) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_IDENTITY_POLICY_UNSUPPORTED');
    }
    $policy = [
        'app_uid' => $appUid,
        'shared_gid' => $sharedGid,
        'root_mode' => '03770',
        'dir_mode' => '02770',
        'file_mode' => '0660',
    ];
    $sourceRoots = $probe['source_content_roots'];
    $sourceVolumes = [];
    $candidateVolumes = [];
    foreach (['install', 'local_storage', 'runtime_backup'] as $artifact) {
        $sourceVolumes[$artifact] = bootstrapAdoptSourceVolume($artifact, $runtime, $sourceRoots[$artifact]);
        $candidateVolumes[$artifact] = bootstrapAdoptCandidateVolume(
            $artifact, 'legacy_broad', $runtime, $sourceRoots[$artifact], false,
        );
    }
    $sourceVolumes['uploads'] = bootstrapAdoptSourceVolume('uploads', $uploads, $sourceRoots['uploads']);
    $candidateVolumes['uploads'] = bootstrapAdoptCandidateVolume(
        'uploads', 'legacy_broad', $uploads, $probe['expected_uploads_content_root'], false,
    );
    $targetVolumes = [];
    foreach (['cert', 'demo', 'public_storage'] as $artifact) {
        $expectedRoot = $probe['artifacts'][$artifact]['content_root'];
        $targetVolumes[$artifact] = bootstrapAdoptCandidateVolume(
            $artifact, 'candidate', $targets[$artifact], $expectedRoot, true,
        );
        $candidateVolumes[$artifact] = $targetVolumes[$artifact];
    }
    ksort($sourceVolumes, SORT_STRING);
    ksort($sourceRoots, SORT_STRING);
    ksort($targetVolumes, SORT_STRING);
    ksort($candidateVolumes, SORT_STRING);
    $partition = $probe['local_root_classification'] === 'canonical'
        ? [
            'configured_local_root' => 'uploads',
            'partition_kind' => 'canonical_volume',
            'build_context_relative_root' => null,
        ]
        : [
            'configured_local_root' => $probe['configured_local_root'],
            'partition_kind' => 'build_context_relative',
            'build_context_relative_root' => $probe['build_context_relative_root'],
        ];
    $document = [
        'schema_version' => 1,
        'purpose' => 'storage_bootstrap_adopt_source',
        'evidence' => [
            'prepare' => [
                'operation_id' => $operation,
                'installation_storage_namespace' => '',
                'candidate' => [
                    'layout_version' => 1,
                    'layout_generation' => 0,
                    'app_version' => $identity['version'],
                    'deployment_id' => $identity['deployment_id'],
                    'release_inventory_sha256' => $identity['payload_sha256'],
                    'boot_eligible' => false,
                    'volumes' => $candidateVolumes,
                ],
                'retention' => [
                    'receipt_sha256' => '',
                    'source_volumes' => $sourceVolumes,
                    'source_content_roots' => $sourceRoots,
                    'old_app_uid' => $oldUid,
                    'old_app_gid' => $oldGid,
                    'old_identity_root' => $oldIdentityRoot,
                    'compatible_with_target_policy' => $compatible,
                    'target_volumes' => $targetVolumes,
                    'target_policy' => $policy,
                    'target_policy_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical($policy)),
                    'frozen_manifest_sha256' => $probe['environment_sha256'],
                ],
                'local_setting_intent_sha256' => '',
            ],
            'retention_partition' => $partition,
        ],
    ];
    $bytes = bootstrapAdoptCanonical($document);
    bootstrapAdoptAssertAbsentOrEqual($outputPath, $bytes, $agentUid, $sharedGid, 0600);
    bootstrapAdoptPublish($outputPath, $bytes, $agentUid, $sharedGid, 0600);
    bootstrapAdoptFsyncDirectoryPath(dirname(dirname($outputPath)));
}

/** @return array<string,mixed> */
function bootstrapAdoptReadCanonical(string $path, ?int $mode = null): array
{
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $stat['nlink'] !== 1 || (int) $stat['size'] <= 0
        || (int) $stat['size'] > BOOTSTRAP_ADOPT_MAX_JSON_BYTES
        || ($mode !== null && ($stat['mode'] & 0777) !== $mode)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_AUTHORITY_FILE_INVALID');
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || !str_ends_with($bytes, "\n") || substr_count($bytes, "\n") !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_AUTHORITY_FILE_INVALID');
    }
    try {
        $document = json_decode(substr($bytes, 0, -1), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_AUTHORITY_JSON_INVALID');
    }
    if (!is_array($document) || array_is_list($document)
        || !hash_equals($bytes, bootstrapAdoptCanonical($document))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_AUTHORITY_NOT_CANONICAL');
    }

    return $document;
}

function bootstrapAdoptAssertDirectory(string $path): array
{
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || is_link($path)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_ROOT_INVALID');
    }

    return $stat;
}

function bootstrapAdoptAssertRelativePath(string $path): void
{
    $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $path);
    if ($path === '' || strlen($path) > 4096 || preg_match('//u', $path) !== 1
        || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        || str_contains('/' . $normalized . '/', '/../')
        || str_starts_with($normalized, '/')) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_INVALID');
    }
}

/**
 * @return array{hash:string,entries:array<string,array{kind:string,size:int,sha256?:string}>}
 */
function bootstrapAdoptTree(string $root, bool $rejectMarker = true): array
{
    $rootStat = bootstrapAdoptAssertDirectory($root);
    $rootDevice = (int) $rootStat['dev'];
    $entries = ['.' => ['kind' => 'directory', 'size' => 0]];
    $rows = [bootstrapAdoptCanonical(['directory', '.'])];
    $entryCount = 1;
    $byteCount = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            bootstrapAdoptAssertRelativePath($relative);
            if (basename($relative) === BOOTSTRAP_ADOPT_MARKER) {
                if ($relative !== BOOTSTRAP_ADOPT_MARKER || $rejectMarker) {
                    bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESERVED_MARKER_CONFLICT');
                }
                continue;
            }
            $stat = @lstat($path);
            if (!is_array($stat) || (int) $stat['dev'] !== $rootDevice || is_link($path)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_INVALID');
            }
            $type = $stat['mode'] & 0170000;
            $canonicalPath = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($type === 0040000) {
                $entries[$canonicalPath] = ['kind' => 'directory', 'size' => 0];
                $rows[] = bootstrapAdoptCanonical(['directory', $canonicalPath]);
            } elseif ($type === 0100000 && (int) $stat['nlink'] === 1) {
                $size = (int) $stat['size'];
                if ($size < 0 || $size > BOOTSTRAP_ADOPT_MAX_TREE_BYTES - $byteCount) {
                    bootstrapAdoptFail('BOOTSTRAP_ADOPT_TREE_TOO_LARGE');
                }
                $digest = @hash_file('sha256', $path);
                $post = @lstat($path);
                if (!is_string($digest) || !is_array($post)
                    || (int) $post['dev'] !== (int) $stat['dev']
                    || (int) $post['ino'] !== (int) $stat['ino']
                    || (int) $post['size'] !== $size || (int) $post['nlink'] !== 1) {
                    bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_CHANGED');
                }
                $sha256 = 'sha256:' . $digest;
                $entries[$canonicalPath] = ['kind' => 'file', 'size' => $size, 'sha256' => $sha256];
                $rows[] = bootstrapAdoptCanonical(['file', $canonicalPath, $size, $sha256]);
                $byteCount += $size;
            } else {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_INVALID');
            }
            if (++$entryCount > BOOTSTRAP_ADOPT_MAX_TREE_ENTRIES) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_TREE_TOO_LARGE');
            }
        }
    } catch (UnexpectedValueException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_UNREADABLE');
    }
    ksort($entries, SORT_STRING);
    sort($rows, SORT_STRING);

    return ['hash' => bootstrapAdoptHash(implode('', $rows)), 'entries' => $entries];
}

/** @param array<string,array{kind:string,size:int,sha256?:string}> $entries */
function bootstrapAdoptEntriesHash(array $entries): string
{
    $rows = [];
    foreach ($entries as $path => $entry) {
        if ($entry['kind'] === 'directory' && $entry['size'] === 0) {
            $rows[] = bootstrapAdoptCanonical(['directory', $path]);
            continue;
        }
        if ($entry['kind'] !== 'file' || !isset($entry['sha256'])
            || !is_int($entry['size']) || $entry['size'] < 0
            || !bootstrapAdoptIsHash($entry['sha256'])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_TREE_INVALID');
        }
        $rows[] = bootstrapAdoptCanonical(['file', $path, $entry['size'], $entry['sha256']]);
    }
    sort($rows, SORT_STRING);

    return bootstrapAdoptHash(implode('', $rows));
}

function bootstrapAdoptEmptyTreeHash(): string
{
    return bootstrapAdoptHash(bootstrapAdoptCanonical(['directory', '.']));
}

/**
 * @param array{hash:string,entries:array<string,array{kind:string,size:int,sha256?:string}>} $source
 * @param array{hash:string,entries:array<string,array{kind:string,size:int,sha256?:string}>} $target
 */
function bootstrapAdoptMergedTreeHash(array $source, array $target): string
{
    $entries = $target['entries'];
    foreach ($source['entries'] as $path => $entry) {
        if ($path !== '.') {
            if (isset($entries[$path]) && $entries[$path] !== $entry) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
            }
            $entries[$path] = $entry;
        }
    }
    ksort($entries, SORT_STRING);

    return bootstrapAdoptEntriesHash($entries);
}

function bootstrapAdoptPublish(string $path, string $bytes, int $uid, int $gid, int $mode = 0640): void
{
    bootstrapAdoptAssertDirectory(dirname($path));
    if (file_exists($path) || is_link($path)) {
        $stat = @lstat($path);
        if (is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && !is_link($path)
            && (int) $stat['nlink'] === 1 && ($stat['mode'] & 0777) === $mode
            && (int) $stat['uid'] === $uid && (int) $stat['gid'] === $gid
            && (int) $stat['size'] >= 0 && (int) $stat['size'] <= BOOTSTRAP_ADOPT_MAX_JSON_BYTES) {
            $existing = @file_get_contents($path);
            if (is_string($existing) && hash_equals($existing, $bytes)) {
                return;
            }
        }
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_CONFLICT');
    }
    $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(12)) . '.tmp';
    $handle = @fopen($temporary, 'x+b');
    if (!is_resource($handle)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_PUBLISH_FAILED');
    }
    try {
        if (!chmod($temporary, $mode) || !chown($temporary, $uid) || !chgrp($temporary, $gid)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_PUBLISH_FAILED');
        }
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written <= 0) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_PUBLISH_FAILED');
            }
            $offset += $written;
        }
        if (!fflush($handle) || !fsync($handle)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_PUBLISH_FAILED');
        }
    } finally {
        fclose($handle);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_PUBLISH_FAILED');
    }
    $directory = @fopen(dirname($path), 'rb');
    if (!is_resource($directory) || !fsync($directory)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_PUBLISH_FAILED');
    }
    fclose($directory);
}

function bootstrapAdoptPublishManifest(
    string $path,
    string $source,
    string $expectedRoot,
    int $uid,
    int $gid,
): void
{
    bootstrapAdoptAssertDirectory(dirname($path));
    $sourceFingerprint = bootstrapAdoptStreamUploadsManifest($source, $expectedRoot);
    if (file_exists($path) || is_link($path)) {
        $stat = @lstat($path);
        if (is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && !is_link($path)
            && (int) $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600
            && (int) $stat['uid'] === $uid && (int) $stat['gid'] === $gid
            && (int) $stat['size'] === $sourceFingerprint['bytes']) {
            $existing = bootstrapAdoptStreamUploadsManifest($path, $expectedRoot);
            if ($existing['bytes'] === $sourceFingerprint['bytes']
                && $existing['entries'] === $sourceFingerprint['entries']) {
                return;
            }
        }
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_CONFLICT');
    }
    $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(12)) . '.tmp';
    $handle = @fopen($temporary, 'x+b');
    if (!is_resource($handle)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_PUBLISH_FAILED');
    }
    $cleanup = $temporary;
    register_shutdown_function(static function () use (&$cleanup): void {
        if (is_string($cleanup)) @unlink($cleanup);
    });
    try {
        if (!chmod($temporary, 0600) || !chown($temporary, $uid) || !chgrp($temporary, $gid)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_PUBLISH_FAILED');
        }
        $copied = bootstrapAdoptStreamUploadsManifest($source, $expectedRoot, $handle);
        if ($copied !== $sourceFingerprint) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID');
        }
        if (!fflush($handle) || !fsync($handle)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_PUBLISH_FAILED');
        }
    } finally {
        fclose($handle);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_PUBLISH_FAILED');
    }
    $cleanup = null;
    $directory = @fopen(dirname($path), 'rb');
    if (!is_resource($directory) || !fsync($directory)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_PUBLISH_FAILED');
    }
    fclose($directory);
}

function bootstrapAdoptFsyncOpenedPath(string $path, int $device, int $inode, int $type): void
{
    $handle = @fopen($path, 'rb');
    $opened = is_resource($handle) ? @fstat($handle) : false;
    if (!is_resource($handle) || !is_array($opened)
        || ($opened['mode'] & 0170000) !== $type
        || (int) $opened['dev'] !== $device || (int) $opened['ino'] !== $inode
        || ($type === 0100000 && (int) $opened['nlink'] !== 1)
        || !fsync($handle)) {
        if (is_resource($handle)) fclose($handle);
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
    }
    fclose($handle);
    $after = @lstat($path);
    if (!is_array($after) || is_link($path) || ($after['mode'] & 0170000) !== $type
        || (int) $after['dev'] !== $device || (int) $after['ino'] !== $inode
        || ($type === 0100000 && (int) $after['nlink'] !== 1)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
    }
}

function bootstrapAdoptFsyncDirectoryPath(string $path): void
{
    $stat = bootstrapAdoptAssertDirectory($path);
    bootstrapAdoptFsyncOpenedPath($path, (int) $stat['dev'], (int) $stat['ino'], 0040000);
}

function bootstrapAdoptFsyncRetention(string $root, string $operationRoot): void
{
    $rootReal = @realpath($root);
    $operationReal = @realpath($operationRoot);
    if (!is_string($rootReal) || !is_string($operationReal)
        || dirname($rootReal) !== $operationReal || $rootReal !== rtrim($root, '/')
        || $operationReal !== rtrim($operationRoot, '/')) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
    }
    $rootStat = bootstrapAdoptAssertDirectory($root);
    $operationStat = bootstrapAdoptAssertDirectory($operationRoot);
    $device = (int) $rootStat['dev'];
    if ((int) $operationStat['dev'] !== $device) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
    }
    $entries = 1;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (++$entries > BOOTSTRAP_ADOPT_MAX_TREE_ENTRIES) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
            }
            $path = $entry->getPathname();
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || (int) $stat['dev'] !== $device) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
            }
            $type = $stat['mode'] & 0170000;
            if (!in_array($type, [0040000, 0100000], true)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
            }
            bootstrapAdoptFsyncOpenedPath($path, $device, (int) $stat['ino'], $type);
        }
    } catch (UnexpectedValueException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED');
    }
    bootstrapAdoptFsyncOpenedPath($root, $device, (int) $rootStat['ino'], 0040000);
    bootstrapAdoptFsyncOpenedPath(
        $operationRoot,
        $device,
        (int) $operationStat['ino'],
        0040000,
    );
}

function bootstrapAdoptAssertAbsentOrEqual(
    string $path,
    string $bytes,
    int $uid,
    int $gid,
    int $mode = 0640,
): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== $mode
        || (int) $stat['uid'] !== $uid || (int) $stat['gid'] !== $gid
        || (int) $stat['size'] < 0 || (int) $stat['size'] > BOOTSTRAP_ADOPT_MAX_JSON_BYTES) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_CONFLICT');
    }
    $existing = @file_get_contents($path);
    if (!is_string($existing) || !hash_equals($existing, $bytes)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESULT_CONFLICT');
    }
}

/** @param array<string,mixed> $request */
function bootstrapAdoptValidateNormalizeRequest(array $request): void
{
    $keys = [
        'schema_version', 'purpose', 'operation_id', 'agent_uid', 'app_uid', 'shared_gid',
        'target_policy', 'source_content_roots',
    ];
    if (array_keys($request) !== $keys || $request['schema_version'] !== 1
        || $request['purpose'] !== 'storage_bootstrap_adopt_normalize'
        || !bootstrapAdoptIsUuid($request['operation_id'])
        || !is_int($request['agent_uid']) || $request['agent_uid'] <= 0
        || !is_int($request['app_uid']) || $request['app_uid'] <= 0
        || $request['agent_uid'] === $request['app_uid']
        || !is_int($request['shared_gid']) || $request['shared_gid'] <= 0
        || !is_array($request['target_policy'])
        || array_keys($request['target_policy']) !== ['app_uid', 'shared_gid', 'root_mode', 'dir_mode', 'file_mode']
        || $request['target_policy'] !== [
            'app_uid' => $request['app_uid'], 'shared_gid' => $request['shared_gid'],
            'root_mode' => '03770', 'dir_mode' => '02770', 'file_mode' => '0660',
        ] || !is_array($request['source_content_roots'])
        || array_keys($request['source_content_roots']) !== ['install', 'local_storage', 'runtime_backup', 'uploads']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_NORMALIZE_REQUEST_INVALID');
    }
    foreach ($request['source_content_roots'] as $hash) {
        if (!bootstrapAdoptIsHash($hash)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_NORMALIZE_REQUEST_INVALID');
        }
    }
}

function bootstrapAdoptNormalizeTree(
    string $root,
    int $agentUid,
    int $appUid,
    int $sharedGid,
    bool $allowRootMarker = false,
): void
{
    $rootStat = bootstrapAdoptAssertDirectory($root);
    $rootDevice = (int) $rootStat['dev'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        bootstrapAdoptAssertRelativePath($relative);
        if (basename($relative) === BOOTSTRAP_ADOPT_MARKER) {
            if ($allowRootMarker && $relative === BOOTSTRAP_ADOPT_MARKER) {
                continue;
            }
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESERVED_MARKER_CONFLICT');
        }
        $stat = @lstat($path);
        if (!is_array($stat) || (int) $stat['dev'] !== $rootDevice || is_link($path)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_INVALID');
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0100000 && (int) $stat['nlink'] === 1) {
            $mode = 0660;
        } elseif ($type === 0040000) {
            $mode = 02770;
        } else {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENTRY_INVALID');
        }
        if (!chown($path, $appUid) || !chgrp($path, $sharedGid) || !chmod($path, $mode)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_PERMISSION_CHANGE_FAILED');
        }
        clearstatcache(true, $path);
        $post = @lstat($path);
        if (!is_array($post) || (int) $post['dev'] !== (int) $stat['dev']
            || (int) $post['ino'] !== (int) $stat['ino'] || (int) $post['uid'] !== $appUid
            || (int) $post['gid'] !== $sharedGid || ($post['mode'] & 07777) !== $mode) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_PERMISSION_VERIFY_FAILED');
        }
    }
    if (!chown($root, $agentUid) || !chgrp($root, $sharedGid) || !chmod($root, 03770)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_PERMISSION_CHANGE_FAILED');
    }
    clearstatcache(true, $root);
}

function bootstrapAdoptNormalize(
    string $requestPath,
    string $runtime,
    string $uploads,
    string $resultRoot,
    string $expectedOperation,
): void
{
    $request = bootstrapAdoptReadCanonical($requestPath, 0444);
    bootstrapAdoptValidateNormalizeRequest($request);
    if (!bootstrapAdoptIsUuid($expectedOperation)
        || !hash_equals($expectedOperation, (string) $request['operation_id'])) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_OPERATION_MISMATCH');
    }
    $agentUid = $request['agent_uid'];
    $appUid = $request['app_uid'];
    $sharedGid = $request['shared_gid'];
    $artifactRoots = [
        'install' => rtrim($runtime, '/') . '/install',
        'local_storage' => rtrim($runtime, '/') . '/storage',
        'runtime_backup' => rtrim($runtime, '/') . '/backup',
        'uploads' => $uploads,
    ];
    bootstrapAdoptAssertDirectory($resultRoot . '/normalization');

    $broadObserved = [
        'runtime' => bootstrapAdoptTree($runtime),
        'uploads' => bootstrapAdoptTree($uploads),
    ];
    $beforeRoots = [];
    $missingRoots = [];
    foreach ($artifactRoots as $artifact => $root) {
        if (file_exists($root) || is_link($root)) {
            $beforeRoots[$artifact] = bootstrapAdoptTree($root)['hash'];
            continue;
        }
        if ($artifact === 'uploads'
            || $request['source_content_roots'][$artifact] !== bootstrapAdoptEmptyTreeHash()) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_ROOT_MISSING');
        }
        $beforeRoots[$artifact] = bootstrapAdoptEmptyTreeHash();
        $missingRoots[$artifact] = $root;
    }
    if ($beforeRoots !== $request['source_content_roots']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_CONTENT_CHANGED');
    }
    $expectedRuntimeEntries = $broadObserved['runtime']['entries'];
    foreach ($missingRoots as $artifact => $_root) {
        $relative = match ($artifact) {
            'install' => 'install',
            'local_storage' => 'storage',
            'runtime_backup' => 'backup',
            default => bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_ROOT_MISSING'),
        };
        if (isset($expectedRuntimeEntries[$relative])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_ROOT_MISSING');
        }
        $expectedRuntimeEntries[$relative] = ['kind' => 'directory', 'size' => 0];
    }
    ksort($expectedRuntimeEntries, SORT_STRING);
    $broadExpected = [
        'runtime' => [
            'hash' => bootstrapAdoptEntriesHash($expectedRuntimeEntries),
            'entries' => $expectedRuntimeEntries,
        ],
        'uploads' => $broadObserved['uploads'],
    ];
    $intent = [
        'schema_version' => 1,
        'purpose' => 'storage_bootstrap_adopt_normalization_intent',
        'operation_id' => $request['operation_id'],
        'broad_content_roots' => [
            'runtime' => $broadExpected['runtime']['hash'],
            'uploads' => $broadExpected['uploads']['hash'],
        ],
        'source_content_roots' => $beforeRoots,
        'target_policy' => $request['target_policy'],
    ];
    $intentBytes = bootstrapAdoptCanonical($intent);
    $runtimeDoneBytes = bootstrapAdoptCanonical([
        'operation_id' => $request['operation_id'], 'artifact' => 'runtime', 'complete' => true,
    ]);
    $uploadsDoneBytes = bootstrapAdoptCanonical([
        'operation_id' => $request['operation_id'], 'artifact' => 'uploads', 'complete' => true,
    ]);
    $withoutHash = [
        'operation_id' => $request['operation_id'],
        'before_content_roots' => $beforeRoots,
        'after_content_roots' => $beforeRoots,
        'target_policy' => $request['target_policy'],
        'complete' => true,
    ];
    $evidence = [
        'operation_id' => $request['operation_id'],
        'receipt_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical($withoutHash)),
        'before_content_roots' => $beforeRoots,
        'after_content_roots' => $beforeRoots,
        'target_policy' => $request['target_policy'],
        'complete' => true,
    ];
    $receiptBytes = bootstrapAdoptCanonical([
        'schema_version' => 1,
        'purpose' => 'storage_bootstrap_adopt_normalization_receipt',
        'evidence' => $evidence,
    ]);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/normalization/intent.json', $intentBytes, $agentUid, $sharedGid);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/normalization/runtime.done.json', $runtimeDoneBytes, $agentUid, $sharedGid);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/normalization/uploads.done.json', $uploadsDoneBytes, $agentUid, $sharedGid);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/normalization/receipt.json', $receiptBytes, $agentUid, $sharedGid);
    bootstrapAdoptPublish($resultRoot . '/normalization/intent.json', $intentBytes, $agentUid, $sharedGid);

    foreach ($missingRoots as $root) {
        if (!mkdir($root, 0700)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_SOURCE_ROOT_CREATE_FAILED');
        }
    }
    bootstrapAdoptNormalizeTree($runtime, $agentUid, $appUid, $sharedGid);
    bootstrapAdoptPublish(
        $resultRoot . '/normalization/runtime.done.json',
        $runtimeDoneBytes,
        $agentUid,
        $sharedGid,
    );
    bootstrapAdoptNormalizeTree($uploads, $agentUid, $appUid, $sharedGid);
    bootstrapAdoptPublish(
        $resultRoot . '/normalization/uploads.done.json',
        $uploadsDoneBytes,
        $agentUid,
        $sharedGid,
    );

    $broadAfter = [
        'runtime' => bootstrapAdoptTree($runtime),
        'uploads' => bootstrapAdoptTree($uploads),
    ];
    if ($broadExpected !== $broadAfter) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_CONTENT_CHANGED_DURING_NORMALIZATION');
    }
    $afterRoots = [];
    foreach ($artifactRoots as $artifact => $root) {
        $afterRoots[$artifact] = bootstrapAdoptTree($root)['hash'];
    }
    if ($afterRoots !== $beforeRoots) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_CONTENT_CHANGED_DURING_NORMALIZATION');
    }
    bootstrapAdoptPublish(
        $resultRoot . '/normalization/receipt.json',
        $receiptBytes,
        $agentUid,
        $sharedGid,
    );
}

/** @param array<string,mixed> $request */
function bootstrapAdoptValidateImportRequest(array $request): void
{
    if (array_keys($request) !== [
        'schema_version', 'purpose', 'operation_id', 'installation_storage_namespace',
        'layout_generation', 'agent_uid', 'app_uid', 'shared_gid', 'target_policy',
        'normalization_receipt_sha256', 'frozen_manifest_sha256', 'candidate_volumes', 'imports',
    ] || $request['schema_version'] !== 1 || $request['purpose'] !== 'storage_bootstrap_adopt_import'
        || !bootstrapAdoptIsUuid($request['operation_id'])
        || !is_string($request['installation_storage_namespace'])
        || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $request['installation_storage_namespace']) !== 1
        || !is_int($request['layout_generation']) || $request['layout_generation'] <= 0
        || !is_int($request['agent_uid']) || $request['agent_uid'] <= 0
        || !is_int($request['app_uid']) || $request['app_uid'] <= 0
        || $request['agent_uid'] === $request['app_uid']
        || !is_int($request['shared_gid']) || $request['shared_gid'] <= 0
        || !bootstrapAdoptIsHash($request['normalization_receipt_sha256'])
        || !bootstrapAdoptIsHash($request['frozen_manifest_sha256'])
        || !is_array($request['target_policy']) || $request['target_policy'] !== [
            'app_uid' => $request['app_uid'], 'shared_gid' => $request['shared_gid'],
            'root_mode' => '03770', 'dir_mode' => '02770', 'file_mode' => '0660',
        ] || !is_array($request['candidate_volumes'])
        || array_keys($request['candidate_volumes']) !== [
            'cert', 'demo', 'install', 'local_storage', 'public_storage', 'runtime_backup', 'uploads',
        ] || !is_array($request['imports'])
        || array_keys($request['imports']) !== ['cert', 'custom_upload', 'demo', 'env', 'public_storage']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_REQUEST_INVALID');
    }
    $candidateArtifacts = ['cert', 'demo', 'public_storage'];
    foreach ($request['candidate_volumes'] as $artifact => $volume) {
        if (!is_array($volume) || array_keys($volume) !== [
            'artifact', 'source_mode', 'volume_name', 'docker_volume_id', 'labels_sha256',
            'marker_id', 'marker_sha256', 'expected_content_root', 'empty_at_prepare',
        ] || $volume['artifact'] !== $artifact || !is_string($volume['volume_name']) || $volume['volume_name'] === ''
            || !is_string($volume['docker_volume_id']) || $volume['docker_volume_id'] === ''
            || !bootstrapAdoptIsHash($volume['labels_sha256']) || !bootstrapAdoptIsUuid($volume['marker_id'])
            || !bootstrapAdoptIsHash($volume['marker_sha256'])
            || !bootstrapAdoptIsHash($volume['expected_content_root'])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_REQUEST_INVALID');
        }
        $candidate = in_array($artifact, $candidateArtifacts, true);
        if ($volume['source_mode'] !== ($candidate ? 'candidate' : 'legacy_broad')
            || $volume['empty_at_prepare'] !== $candidate) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_REQUEST_INVALID');
        }
    }

    $runtime = $request['candidate_volumes']['install'];
    foreach (['local_storage', 'runtime_backup'] as $artifact) {
        $volume = $request['candidate_volumes'][$artifact];
        if ($volume['volume_name'] !== $runtime['volume_name']
            || $volume['docker_volume_id'] !== $runtime['docker_volume_id']
            || $volume['labels_sha256'] !== $runtime['labels_sha256']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TOPOLOGY_INVALID');
        }
    }
    $uploads = $request['candidate_volumes']['uploads'];
    if ($uploads['docker_volume_id'] === $runtime['docker_volume_id']
        || ($uploads['volume_name'] === $runtime['volume_name']
            && $uploads['labels_sha256'] === $runtime['labels_sha256'])) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TOPOLOGY_INVALID');
    }
    $sourceIds = [$runtime['docker_volume_id'] => true, $uploads['docker_volume_id'] => true];
    $targetIds = [];
    foreach ($candidateArtifacts as $artifact) {
        $dockerId = $request['candidate_volumes'][$artifact]['docker_volume_id'];
        if (isset($sourceIds[$dockerId]) || isset($targetIds[$dockerId])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TOPOLOGY_INVALID');
        }
        $targetIds[$dockerId] = true;
    }
    foreach ($request['imports'] as $artifact => $import) {
        $hashKey = $artifact === 'env' ? 'sha256' : 'content_root';
        if (!is_array($import) || array_keys($import) !== ['present', $hashKey]
            || !is_bool($import['present']) || !bootstrapAdoptIsHash($import[$hashKey])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_REQUEST_INVALID');
        }
    }
    if ($request['imports']['env']['present'] !== true) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_ENV_REQUIRED');
    }
}

function bootstrapAdoptCopyFile(
    string $source,
    string $target,
    int $uid,
    int $gid,
    int $mode,
    bool $replaceEmpty = false,
): void {
    $sourceStat = @lstat($source);
    if (!is_array($sourceStat) || ($sourceStat['mode'] & 0170000) !== 0100000 || is_link($source)
        || (int) $sourceStat['nlink'] !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_INVALID');
    }
    $sourceHash = @hash_file('sha256', $source);
    if (!is_string($sourceHash)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_INVALID');
    }
    if (file_exists($target) || is_link($target)) {
        $targetStat = @lstat($target);
        if (!is_array($targetStat) || ($targetStat['mode'] & 0170000) !== 0100000 || is_link($target)
            || (int) $targetStat['nlink'] !== 1) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
        }
        $targetHash = @hash_file('sha256', $target);
        if (!is_string($targetHash)) bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
        if (hash_equals($sourceHash, $targetHash) && (int) $sourceStat['size'] === (int) $targetStat['size']) {
            if (!chown($target, $uid) || !chgrp($target, $gid) || !chmod($target, $mode)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_POLICY_FAILED');
            }
            return;
        }
        if (!$replaceEmpty || (int) $targetStat['size'] !== 0) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
        }
    }
    $temporary = dirname($target) . '/.' . basename($target) . '.' . bin2hex(random_bytes(12)) . '.tmp';
    $input = @fopen($source, 'rb');
    $output = @fopen($temporary, 'x+b');
    if (!is_resource($input) || !is_resource($output)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_COPY_FAILED');
    }
    try {
        if (stream_copy_to_stream($input, $output) !== (int) $sourceStat['size']
            || !fflush($output) || !fsync($output) || !chmod($temporary, $mode)
            || !chown($temporary, $uid) || !chgrp($temporary, $gid)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_COPY_FAILED');
        }
    } finally {
        fclose($input);
        fclose($output);
    }
    if (!rename($temporary, $target)) {
        @unlink($temporary);
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_COPY_FAILED');
    }
    $directory = @fopen(dirname($target), 'rb');
    if (!is_resource($directory) || !fsync($directory)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_COPY_FAILED');
    }
    fclose($directory);
}

function bootstrapAdoptCopyTree(string $source, string $target, int $uid, int $gid): void
{
    bootstrapAdoptAssertDirectory($source);
    bootstrapAdoptAssertDirectory($target);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $sourcePath = $entry->getPathname();
        $relative = substr($sourcePath, strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
        bootstrapAdoptAssertRelativePath($relative);
        if (basename($relative) === BOOTSTRAP_ADOPT_MARKER) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RESERVED_MARKER_CONFLICT');
        }
        $sourceStat = @lstat($sourcePath);
        if (!is_array($sourceStat) || is_link($sourcePath)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_INVALID');
        }
        $targetPath = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
        $type = $sourceStat['mode'] & 0170000;
        if ($type === 0040000) {
            if (file_exists($targetPath) || is_link($targetPath)) {
                bootstrapAdoptAssertDirectory($targetPath);
            } elseif (!mkdir($targetPath, 02770)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_COPY_FAILED');
            }
            if (!chown($targetPath, $uid) || !chgrp($targetPath, $gid) || !chmod($targetPath, 02770)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_POLICY_FAILED');
            }
        } elseif ($type === 0100000 && (int) $sourceStat['nlink'] === 1) {
            bootstrapAdoptCopyFile($sourcePath, $targetPath, $uid, $gid, 0660);
        } else {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_INVALID');
        }
    }
}

function bootstrapAdoptAssertCopyTreeCompatible(string $source, string $target, bool $exactTarget): void
{
    $sourceTree = bootstrapAdoptTree($source);
    $targetTree = bootstrapAdoptTree($target, false);
    foreach ($sourceTree['entries'] as $path => $sourceEntry) {
        if ($path === '.' || !isset($targetTree['entries'][$path])) {
            continue;
        }
        if ($targetTree['entries'][$path] !== $sourceEntry) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
        }
    }
    if ($exactTarget) {
        foreach ($targetTree['entries'] as $path => $_entry) {
            if ($path !== '.' && !isset($sourceTree['entries'][$path])) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
            }
        }
    }
}

function bootstrapAdoptAssertCopyFileCompatible(string $source, string $target, bool $replaceEmpty): void
{
    $sourceStat = @lstat($source);
    if (!is_array($sourceStat) || ($sourceStat['mode'] & 0170000) !== 0100000 || is_link($source)
        || (int) $sourceStat['nlink'] !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_INVALID');
    }
    if (!file_exists($target) && !is_link($target)) {
        return;
    }
    $targetStat = @lstat($target);
    if (!is_array($targetStat) || ($targetStat['mode'] & 0170000) !== 0100000 || is_link($target)
        || (int) $targetStat['nlink'] !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
    }
    if ($replaceEmpty && (int) $targetStat['size'] === 0) {
        return;
    }
    $sourceHash = @hash_file('sha256', $source);
    $targetHash = @hash_file('sha256', $target);
    if (!is_string($sourceHash) || !is_string($targetHash) || !hash_equals($sourceHash, $targetHash)
        || (int) $sourceStat['size'] !== (int) $targetStat['size']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT');
    }
}

/** @param array<string,mixed> $request */
function bootstrapAdoptImport(
    array $request,
    string $retentionRoot,
    array $targetRoots,
    string $envRoot,
    string $resultRoot,
): void {
    $agentUid = $request['agent_uid'];
    $appUid = $request['app_uid'];
    $sharedGid = $request['shared_gid'];
    $operation = $request['operation_id'];
    $normalization = bootstrapAdoptReadCanonical($resultRoot . '/normalization/receipt.json', 0640);
    if (($normalization['schema_version'] ?? null) !== 1
        || ($normalization['purpose'] ?? null) !== 'storage_bootstrap_adopt_normalization_receipt'
        || !is_array($normalization['evidence'] ?? null)
        || ($normalization['evidence']['operation_id'] ?? null) !== $operation
        || ($normalization['evidence']['receipt_sha256'] ?? null) !== $request['normalization_receipt_sha256']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_NORMALIZATION_RECEIPT_INVALID');
    }
    $normalizationWithoutHash = $normalization['evidence'];
    unset($normalizationWithoutHash['receipt_sha256']);
    if (bootstrapAdoptHash(bootstrapAdoptCanonical($normalizationWithoutHash))
        !== $request['normalization_receipt_sha256']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_NORMALIZATION_RECEIPT_INVALID');
    }

    $sourceRoots = [
        'cert' => $retentionRoot . '/cert',
        'custom_upload' => $retentionRoot . '/custom-upload',
        'demo' => $retentionRoot . '/demo',
        'public_storage' => $retentionRoot . '/public-storage',
    ];
    $delta = [];
    $sourceTrees = [];
    foreach ($sourceRoots as $artifact => $source) {
        $import = $request['imports'][$artifact];
        if ($import['present']) {
            $sourceTrees[$artifact] = bootstrapAdoptTree($source);
            $actual = $sourceTrees[$artifact]['hash'];
            if (!hash_equals($import['content_root'], $actual)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_CHANGED');
            }
            $delta[$artifact] = $actual;
        } else {
            if (file_exists($source) || is_link($source)) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_CONFLICT');
            }
            $delta[$artifact] = $import['content_root'];
        }
    }
    $envSource = $retentionRoot . '/env/backend.env';
    $envStat = @lstat($envSource);
    if (!is_array($envStat) || ($envStat['mode'] & 0170000) !== 0100000 || is_link($envSource)
        || (int) $envStat['nlink'] !== 1
        || bootstrapAdoptHash((string) file_get_contents($envSource)) !== $request['imports']['env']['sha256']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_SOURCE_CHANGED');
    }
    $delta['env'] = $request['imports']['env']['sha256'];
    ksort($delta, SORT_STRING);

    foreach (['cert', 'demo', 'public_storage'] as $artifact) {
        if ($request['imports'][$artifact]['present']) {
            bootstrapAdoptAssertCopyTreeCompatible($sourceRoots[$artifact], $targetRoots[$artifact], true);
        }
    }
    if ($request['imports']['custom_upload']['present']) {
        bootstrapAdoptAssertCopyTreeCompatible($sourceRoots['custom_upload'], $targetRoots['uploads'], false);
    }
    bootstrapAdoptAssertCopyFileCompatible($envSource, $envRoot . '/backend.env', true);

    $targetTrees = [];
    foreach ($targetRoots as $artifact => $root) {
        bootstrapAdoptAssertDirectory($root);
        $targetTrees[$artifact] = bootstrapAdoptTree($root, false);
        $volume = $request['candidate_volumes'][$artifact];
        $marker = [
            'schema_version' => 1,
            'installation_storage_namespace' => $request['installation_storage_namespace'],
            'artifact' => $artifact,
            'storage_layout_version' => 1,
            'layout_generation' => $request['layout_generation'],
            'marker_id' => $volume['marker_id'],
        ];
        $markerBytes = bootstrapAdoptCanonical($marker);
        if (bootstrapAdoptHash($markerBytes) !== $volume['marker_sha256']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_MARKER_AUTHORITY_INVALID');
        }
        bootstrapAdoptAssertAbsentOrEqual(
            $root . '/' . BOOTSTRAP_ADOPT_MARKER,
            $markerBytes,
            $agentUid,
            $sharedGid,
            0444,
        );
    }

    $importedDeltaSha256 = bootstrapAdoptHash(bootstrapAdoptCanonical($delta));
    $expectedRoots = [];
    foreach ($request['candidate_volumes'] as $artifact => $volume) {
        $expectedRoots[$artifact] = $volume['expected_content_root'];
    }
    $predictedRoots = [];
    foreach (['cert', 'demo', 'public_storage'] as $artifact) {
        $predictedRoots[$artifact] = $request['imports'][$artifact]['present']
            ? $sourceTrees[$artifact]['hash']
            : $targetTrees[$artifact]['hash'];
    }
    foreach (['install', 'local_storage', 'runtime_backup'] as $artifact) {
        $predictedRoots[$artifact] = $targetTrees[$artifact]['hash'];
    }
    $predictedRoots['uploads'] = $request['imports']['custom_upload']['present']
        ? bootstrapAdoptMergedTreeHash($sourceTrees['custom_upload'], $targetTrees['uploads'])
        : $targetTrees['uploads']['hash'];
    ksort($predictedRoots, SORT_STRING);
    foreach ($expectedRoots as $artifact => $expectedRoot) {
        if (!hash_equals($expectedRoot, $predictedRoots[$artifact])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONTENT_INVALID');
        }
    }
    $importWithoutHash = [
        'operation_id' => $operation,
        'normalization_receipt_sha256' => $request['normalization_receipt_sha256'],
        'frozen_manifest_sha256' => $request['frozen_manifest_sha256'],
        'imported_delta_sha256' => $importedDeltaSha256,
        'target_content_roots' => $expectedRoots,
        'volume_markers' => $request['candidate_volumes'],
        'complete' => true,
    ];
    $importEvidence = [
        'operation_id' => $operation,
        'receipt_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical($importWithoutHash)),
        'normalization_receipt_sha256' => $request['normalization_receipt_sha256'],
        'frozen_manifest_sha256' => $request['frozen_manifest_sha256'],
        'imported_delta_sha256' => $importedDeltaSha256,
        'target_content_roots' => $expectedRoots,
        'volume_markers' => $request['candidate_volumes'],
        'complete' => true,
    ];
    $markerTuple = [];
    $inventoryVolumes = [];
    foreach ($request['candidate_volumes'] as $artifact => $volume) {
        $markerTuple[$artifact] = ['marker_id' => $volume['marker_id'], 'marker_sha256' => $volume['marker_sha256']];
        $inventoryVolumes[$artifact] = [
            'artifact' => $artifact, 'volume_name' => $volume['volume_name'],
            'docker_volume_id' => $volume['docker_volume_id'], 'labels_sha256' => $volume['labels_sha256'],
            'source_mode' => $volume['source_mode'], 'marker_id' => $volume['marker_id'],
            'marker_sha256' => $volume['marker_sha256'], 'final_content_root' => $volume['expected_content_root'],
        ];
    }
    $compositeWithoutHash = [
        'operation_id' => $operation,
        'normalization_receipt_sha256' => $request['normalization_receipt_sha256'],
        'import_receipt_sha256' => $importEvidence['receipt_sha256'],
        'protected_markers_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical($markerTuple)),
        'target_inventory_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical([
            'target_policy' => $request['target_policy'], 'volumes' => $inventoryVolumes,
        ])),
        'complete' => true,
    ];
    $compositeEvidence = [
        'operation_id' => $operation,
        'receipt_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical($compositeWithoutHash)),
        'normalization_receipt_sha256' => $request['normalization_receipt_sha256'],
        'import_receipt_sha256' => $importEvidence['receipt_sha256'],
        'protected_markers_sha256' => $compositeWithoutHash['protected_markers_sha256'],
        'target_inventory_sha256' => $compositeWithoutHash['target_inventory_sha256'],
        'complete' => true,
    ];
    $intentBytes = bootstrapAdoptCanonical([
        'schema_version' => 1, 'purpose' => 'storage_bootstrap_adopt_import_intent',
        'operation_id' => $operation, 'imported_delta_sha256' => $importedDeltaSha256,
        'target_content_roots' => $expectedRoots,
    ]);
    $importBytes = bootstrapAdoptCanonical([
        'schema_version' => 1, 'purpose' => 'storage_bootstrap_adopt_import_receipt',
        'evidence' => $importEvidence,
    ]);
    $compositeBytes = bootstrapAdoptCanonical([
        'schema_version' => 1, 'purpose' => 'storage_bootstrap_adopt_composite_receipt',
        'evidence' => $compositeEvidence,
    ]);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/import/intent.json', $intentBytes, $agentUid, $sharedGid);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/import/receipt.json', $importBytes, $agentUid, $sharedGid);
    bootstrapAdoptAssertAbsentOrEqual($resultRoot . '/import/composite.json', $compositeBytes, $agentUid, $sharedGid);
    bootstrapAdoptPublish($resultRoot . '/import/intent.json', $intentBytes, $agentUid, $sharedGid);

    foreach (['cert', 'demo', 'public_storage'] as $artifact) {
        if ($request['imports'][$artifact]['present']) {
            bootstrapAdoptCopyTree($sourceRoots[$artifact], $targetRoots[$artifact], $appUid, $sharedGid);
        }
    }
    if ($request['imports']['custom_upload']['present']) {
        bootstrapAdoptCopyTree($sourceRoots['custom_upload'], $targetRoots['uploads'], $appUid, $sharedGid);
    }
    bootstrapAdoptAssertDirectory($envRoot);
    bootstrapAdoptCopyFile($envSource, $envRoot . '/backend.env', $appUid, $sharedGid, 0600, true);

    foreach ($targetRoots as $artifact => $root) {
        bootstrapAdoptNormalizeTree($root, $agentUid, $appUid, $sharedGid, true);
        $actualRoot = bootstrapAdoptTree($root, false)['hash'];
        if (!hash_equals($expectedRoots[$artifact], $actualRoot)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_IMPORT_TARGET_CONTENT_INVALID');
        }
        $volume = $request['candidate_volumes'][$artifact];
        $marker = [
            'schema_version' => 1,
            'installation_storage_namespace' => $request['installation_storage_namespace'],
            'artifact' => $artifact,
            'storage_layout_version' => 1,
            'layout_generation' => $request['layout_generation'],
            'marker_id' => $volume['marker_id'],
        ];
        bootstrapAdoptPublish(
            $root . '/' . BOOTSTRAP_ADOPT_MARKER,
            bootstrapAdoptCanonical($marker),
            $agentUid,
            $sharedGid,
            0444,
        );
        if (bootstrapAdoptTree($root, false)['hash'] !== $actualRoot) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_MARKER_CONTENT_CHANGED');
        }
    }
    bootstrapAdoptPublish($resultRoot . '/import/receipt.json', $importBytes, $agentUid, $sharedGid);
    bootstrapAdoptPublish($resultRoot . '/import/composite.json', $compositeBytes, $agentUid, $sharedGid);
}

/** @return array{document:array<string,mixed>,bytes:string} */
function bootstrapAdoptReadOwnedCanonical(
    string $path,
    int $mode,
    int $uid,
    int $gid,
): array {
    $before = @lstat($path);
    if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || is_link($path)
        || (int) $before['nlink'] !== 1 || ($before['mode'] & 0777) !== $mode
        || (int) $before['uid'] !== $uid || (int) $before['gid'] !== $gid
        || (int) $before['size'] <= 0 || (int) $before['size'] > BOOTSTRAP_ADOPT_MAX_JSON_BYTES) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    $bytes = @file_get_contents($path);
    $after = @lstat($path);
    if (!is_string($bytes) || !is_array($after)
        || (int) $after['dev'] !== (int) $before['dev']
        || (int) $after['ino'] !== (int) $before['ino']
        || (int) $after['size'] !== (int) $before['size']
        || (int) $after['nlink'] !== 1 || ($after['mode'] & 0777) !== $mode
        || (int) $after['uid'] !== $uid || (int) $after['gid'] !== $gid
        || !str_ends_with($bytes, "\n") || substr_count($bytes, "\n") !== 1) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    try {
        $document = json_decode(substr($bytes, 0, -1), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    if (!is_array($document) || array_is_list($document)
        || !hash_equals($bytes, bootstrapAdoptCanonical($document))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }

    return ['document' => $document, 'bytes' => $bytes];
}

function bootstrapAdoptAssertOwnedDirectory(string $path, int $uid, int $gid): void
{
    $stat = bootstrapAdoptAssertDirectory($path);
    $mode = $stat['mode'] & 07777;
    if (!in_array($mode, [02770, 0770], true)
        || (int) $stat['uid'] !== $uid || (int) $stat['gid'] !== $gid) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
}

/** @param array<string,mixed> $authorization @param array<string,mixed> $public */
function bootstrapAdoptVerifyTargetAuthorization(
    array $authorization,
    string $raw,
    array $public,
    string $operation,
): void {
    if (array_keys($authorization) !== [
        'schema_version', 'purpose', 'key_id', 'installation_storage_namespace', 'migration_id',
        'operation_id', 'layout_generation', 'issued_authority_revision', 'retention_receipt_sha256',
        'composite_receipt_sha256', 'frozen_manifest_sha256', 'target_policy_sha256',
        'local_setting_intent_sha256', 'targets', 'issued_at', 'signature',
    ] || $authorization['schema_version'] !== 1
        || $authorization['purpose'] !== 'bootstrap_target_finalize'
        || array_keys($public) !== ['schema_version', 'key_id', 'public_key']
        || $public['schema_version'] !== 1 || $authorization['key_id'] !== $public['key_id']
        || !bootstrapAdoptIsHash($authorization['key_id'])
        || !bootstrapAdoptIsHash($authorization['retention_receipt_sha256'])
        || !bootstrapAdoptIsHash($authorization['composite_receipt_sha256'])
        || !bootstrapAdoptIsHash($authorization['frozen_manifest_sha256'])
        || !bootstrapAdoptIsHash($authorization['target_policy_sha256'])
        || !bootstrapAdoptIsHash($authorization['local_setting_intent_sha256'])
        || !is_int($authorization['layout_generation']) || $authorization['layout_generation'] <= 0
        || !is_int($authorization['issued_authority_revision'])
        || $authorization['issued_authority_revision'] <= 0
        || !is_int($authorization['issued_at']) || $authorization['issued_at'] <= 0
        || $authorization['operation_id'] !== $operation
        || $authorization['migration_id'] !== $operation
        || !is_array($authorization['targets']) || count($authorization['targets']) !== 7) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_AUTHORITY_INVALID');
    }
    $targetKeys = array_keys($authorization['targets']);
    if ($targetKeys !== ['cert', 'demo', 'install', 'local_storage', 'public_storage', 'runtime_backup', 'uploads']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_AUTHORITY_INVALID');
    }
    foreach ($authorization['targets'] as $artifact => $target) {
        if (!is_array($target) || array_keys($target) !== [
            'artifact', 'docker_volume_id', 'marker_id', 'marker_sha256', 'expected_content_root',
        ] || $target['artifact'] !== $artifact || !is_string($target['docker_volume_id'])
            || $target['docker_volume_id'] === '' || !bootstrapAdoptIsUuid($target['marker_id'])
            || !bootstrapAdoptIsHash($target['marker_sha256'])
            || !bootstrapAdoptIsHash($target['expected_content_root'])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_AUTHORITY_INVALID');
        }
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_SIGNATURE_UNAVAILABLE');
    }
    $publicKey = base64_decode((string) $public['public_key'], true);
    $signature = base64_decode((string) $authorization['signature'], true);
    if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || bootstrapAdoptHash($publicKey) !== $public['key_id']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_AUTHORITY_INVALID');
    }
    $unsigned = $authorization;
    unset($unsigned['signature']);
    if (!sodium_crypto_sign_verify_detached($signature, bootstrapAdoptCanonical($unsigned), $publicKey)
        || !hash_equals($raw, bootstrapAdoptCanonical($authorization))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_SIGNATURE_INVALID');
    }
}

function bootstrapAdoptPublishTargetOutput(
    string $sourceRoot,
    string $destinationRoot,
    string $authorizationPath,
    string $publicPath,
    string $operation,
    int $agentUid,
    int $appUid,
    int $sharedGid,
): void {
    if (!bootstrapAdoptIsUuid($operation) || $agentUid <= 0 || $appUid <= 0
        || $agentUid === $appUid || $sharedGid <= 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    bootstrapAdoptAssertOwnedDirectory($sourceRoot, $agentUid, $sharedGid);
    bootstrapAdoptAssertOwnedDirectory($destinationRoot, $agentUid, $sharedGid);
    $entries = @scandir($sourceRoot);
    if (!is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== [
        '.finalize.lock', 'confirmation.json', 'local-setting-intent.json', 'local-setting.json',
    ]) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    $lock = @lstat($sourceRoot . '/.finalize.lock');
    if (!is_array($lock) || ($lock['mode'] & 0170000) !== 0100000
        || is_link($sourceRoot . '/.finalize.lock') || (int) $lock['nlink'] !== 1
        || ($lock['mode'] & 0777) !== 0640 || (int) $lock['uid'] !== $appUid
        || (int) $lock['gid'] !== $sharedGid || (int) $lock['size'] !== 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }

    $authorization = bootstrapAdoptReadOwnedCanonical($authorizationPath, 0444, $agentUid, $sharedGid);
    $public = bootstrapAdoptReadOwnedCanonical($publicPath, 0444, $agentUid, $sharedGid);
    bootstrapAdoptVerifyTargetAuthorization(
        $authorization['document'],
        $authorization['bytes'],
        $public['document'],
        $operation,
    );
    $authorizationHash = bootstrapAdoptHash($authorization['bytes']);
    $intent = bootstrapAdoptReadOwnedCanonical(
        $sourceRoot . '/local-setting-intent.json', 0640, $appUid, $sharedGid,
    );
    $local = bootstrapAdoptReadOwnedCanonical(
        $sourceRoot . '/local-setting.json', 0640, $appUid, $sharedGid,
    );
    $confirmation = bootstrapAdoptReadOwnedCanonical(
        $sourceRoot . '/confirmation.json', 0640, $appUid, $sharedGid,
    );

    $intentDocument = $intent['document'];
    if (array_keys($intentDocument) !== [
        'schema_version', 'purpose', 'operation_id', 'retention_receipt_sha256',
        'expected_old_value_sha256', 'canonical_value', 'local_setting_intent_sha256',
        'target_authorization_sha256',
    ] || $intentDocument['schema_version'] !== 1
        || $intentDocument['purpose'] !== 'storage_bootstrap_local_setting_intent'
        || $intentDocument['operation_id'] !== $operation
        || $intentDocument['retention_receipt_sha256']
            !== $authorization['document']['retention_receipt_sha256']
        || !bootstrapAdoptIsHash($intentDocument['expected_old_value_sha256'])
        || $intentDocument['canonical_value'] !== 'uploads'
        || $intentDocument['target_authorization_sha256'] !== $authorizationHash) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    $intentBinding = array_slice($intentDocument, 0, 6, true);
    if (bootstrapAdoptHash(bootstrapAdoptCanonical($intentBinding))
        !== $intentDocument['local_setting_intent_sha256']
        || $intentDocument['local_setting_intent_sha256']
            !== $authorization['document']['local_setting_intent_sha256']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }

    $localDocument = $local['document'];
    if (array_keys($localDocument) !== [
        'schema_version', 'purpose', 'operation_id', 'retention_receipt_sha256',
        'local_setting_intent_sha256', 'expected_old_value_sha256', 'canonical_value',
        'target_authorization_sha256', 'effective_value_sha256', 'complete',
    ] || $localDocument['schema_version'] !== 1
        || $localDocument['purpose'] !== 'storage_bootstrap_local_setting_receipt'
        || $localDocument['operation_id'] !== $operation
        || $localDocument['retention_receipt_sha256'] !== $intentDocument['retention_receipt_sha256']
        || $localDocument['local_setting_intent_sha256'] !== $intentDocument['local_setting_intent_sha256']
        || $localDocument['expected_old_value_sha256'] !== $intentDocument['expected_old_value_sha256']
        || $localDocument['canonical_value'] !== 'uploads'
        || $localDocument['target_authorization_sha256'] !== $authorizationHash
        || $localDocument['effective_value_sha256']
            !== bootstrapAdoptHash(bootstrapAdoptCanonical(['uploads']))
        || $localDocument['complete'] !== true) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }

    $confirmationDocument = $confirmation['document'];
    if (array_keys($confirmationDocument) !== ['schema_version', 'purpose', 'evidence']
        || $confirmationDocument['schema_version'] !== 1
        || $confirmationDocument['purpose'] !== 'storage_bootstrap_adopt_target_confirmation'
        || !is_array($confirmationDocument['evidence'])) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    $evidence = $confirmationDocument['evidence'];
    if (array_keys($evidence) !== [
        'operation_id', 'confirmation_sha256', 'composite_receipt_sha256',
        'target_authorization_sha256', 'verified_target_roots',
        'local_setting_receipt_sha256', 'complete',
    ] || $evidence['operation_id'] !== $operation
        || $evidence['composite_receipt_sha256']
            !== $authorization['document']['composite_receipt_sha256']
        || $evidence['target_authorization_sha256'] !== $authorizationHash
        || !is_array($evidence['verified_target_roots'])
        || array_keys($evidence['verified_target_roots']) !== array_keys($authorization['document']['targets'])
        || $evidence['local_setting_receipt_sha256'] !== bootstrapAdoptHash($local['bytes'])
        || $evidence['complete'] !== true) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }
    foreach ($evidence['verified_target_roots'] as $artifact => $root) {
        if ($root !== $authorization['document']['targets'][$artifact]['expected_content_root']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
        }
    }
    $confirmationWithoutHash = $evidence;
    unset($confirmationWithoutHash['confirmation_sha256']);
    if (!bootstrapAdoptIsHash($evidence['confirmation_sha256'])
        || $evidence['confirmation_sha256']
            !== bootstrapAdoptHash(bootstrapAdoptCanonical($confirmationWithoutHash))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID');
    }

    bootstrapAdoptAssertAbsentOrEqual(
        $destinationRoot . '/local-setting-intent.json', $intent['bytes'], $agentUid, $sharedGid,
    );
    bootstrapAdoptAssertAbsentOrEqual(
        $destinationRoot . '/local-setting.json', $local['bytes'], $agentUid, $sharedGid,
    );
    bootstrapAdoptAssertAbsentOrEqual(
        $destinationRoot . '/confirmation.json', $confirmation['bytes'], $agentUid, $sharedGid,
    );
    bootstrapAdoptPublish(
        $destinationRoot . '/local-setting-intent.json', $intent['bytes'], $agentUid, $sharedGid,
    );
    bootstrapAdoptPublish(
        $destinationRoot . '/local-setting.json', $local['bytes'], $agentUid, $sharedGid,
    );
    bootstrapAdoptPublish(
        $destinationRoot . '/confirmation.json', $confirmation['bytes'], $agentUid, $sharedGid,
    );
}

/** @return array<string,mixed> */
function bootstrapAdoptReadEvidence(string $path, string $purpose): array
{
    $envelope = bootstrapAdoptReadCanonical($path, 0640);
    if (array_keys($envelope) !== ['schema_version', 'purpose', 'evidence']
        || $envelope['schema_version'] !== 1 || $envelope['purpose'] !== $purpose
        || !is_array($envelope['evidence']) || array_is_list($envelope['evidence'])) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_FINALIZE_EVIDENCE_INVALID');
    }

    return $envelope['evidence'];
}

/** @return array{volume_name:string,docker_volume_id:string,labels_sha256:string} */
function bootstrapAdoptCurrentBindObservation(
    string $path,
    string $artifact,
    int $agentUid,
    int $sharedGid,
): array {
    $stat = bootstrapAdoptAssertDirectory($path);
    if ((int) $stat['uid'] !== $agentUid || (int) $stat['gid'] !== $sharedGid
        || ($stat['mode'] & 07777) !== 03770) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_POLICY_INVALID');
    }
    $identity = bootstrapAdoptHash(bootstrapAdoptCanonical([
        'device_id' => (int) $stat['dev'], 'inode' => (int) $stat['ino'], 'relative_role' => $artifact,
    ]));
    $policy = bootstrapAdoptHash(bootstrapAdoptCanonical([
        'agent_uid' => $agentUid, 'artifact' => $artifact, 'relative_role' => $artifact,
        'root_mode' => '03770', 'shared_gid' => $sharedGid, 'storage_kind' => 'bind',
    ]));

    return [
        'volume_name' => 'mallbase_bind_' . $artifact,
        'docker_volume_id' => 'bind:' . $identity,
        'labels_sha256' => $policy,
    ];
}

function bootstrapAdoptVerifyFinalRoot(
    string $root,
    string $artifact,
    array $request,
    int $agentUid,
    int $appUid,
    int $sharedGid,
): string {
    $rootStat = bootstrapAdoptAssertDirectory($root);
    if ((int) $rootStat['uid'] !== $agentUid || (int) $rootStat['gid'] !== $sharedGid
        || ($rootStat['mode'] & 07777) !== 03770) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_POLICY_INVALID');
    }
    $volume = $request['candidate_volumes'][$artifact] ?? null;
    if (!is_array($volume)) bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_INVENTORY_INVALID');
    $markerPath = rtrim($root, '/') . '/' . BOOTSTRAP_ADOPT_MARKER;
    $markerStat = @lstat($markerPath);
    $expectedMarker = bootstrapAdoptCanonical([
        'schema_version' => 1,
        'installation_storage_namespace' => $request['installation_storage_namespace'],
        'artifact' => $artifact,
        'storage_layout_version' => 1,
        'layout_generation' => $request['layout_generation'],
        'marker_id' => $volume['marker_id'],
    ]);
    if (!is_array($markerStat) || ($markerStat['mode'] & 0170000) !== 0100000 || is_link($markerPath)
        || (int) $markerStat['nlink'] !== 1 || (int) $markerStat['uid'] !== $agentUid
        || (int) $markerStat['gid'] !== $sharedGid || ($markerStat['mode'] & 0777) !== 0444
        || !hash_equals($expectedMarker, (string) @file_get_contents($markerPath))
        || !hash_equals($volume['marker_sha256'], bootstrapAdoptHash($expectedMarker))) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_MARKER_INVALID');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        if ($relative === BOOTSTRAP_ADOPT_MARKER) continue;
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || (int) $stat['uid'] !== $appUid
            || (int) $stat['gid'] !== $sharedGid) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_POLICY_INVALID');
        }
        $type = $stat['mode'] & 0170000;
        if (($type === 0040000 && ($stat['mode'] & 07777) !== 02770)
            || ($type === 0100000 && ((int) $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0660))
            || !in_array($type, [0040000, 0100000], true)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_POLICY_INVALID');
        }
    }
    $rootHash = bootstrapAdoptTree($root, false)['hash'];
    if (!hash_equals($volume['expected_content_root'], $rootHash)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_CONTENT_INVALID');
    }

    return $rootHash;
}

function bootstrapAdoptWriteHostFinalize(
    string $layoutPath,
    string $importAuthorityPath,
    string $runtimeVolumePath,
    string $uploadsVolumePath,
    string $runtimeRoot,
    string $uploadsRoot,
    string $certRoot,
    string $demoRoot,
    string $publicStorageRoot,
    string $resultRoot,
    string $operation,
    int $agentUid,
    int $appUid,
    int $sharedGid,
): void {
    if (!bootstrapAdoptIsUuid($operation) || $agentUid <= 0 || $appUid <= 0
        || $agentUid === $appUid || $sharedGid <= 0) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_FINALIZE_EVIDENCE_INVALID');
    }
    $layout = bootstrapAdoptReadLayout($layoutPath, $operation);
    if ($layout['state'] !== 'provisioned' || $layout['adoption_phase'] !== 'target_confirmed'
        || !is_array($layout['candidate'] ?? null) || !is_array($layout['adoption'] ?? null)) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_FINALIZE_PHASE_INVALID');
    }
    $request = bootstrapAdoptReadCanonical($importAuthorityPath, 0444);
    bootstrapAdoptValidateImportRequest($request);
    if ($request['operation_id'] !== $operation
        || $request['installation_storage_namespace'] !== $layout['installation_storage_namespace']
        || $request['layout_generation'] !== $layout['candidate']['layout_generation']
        || $request['candidate_volumes'] !== $layout['candidate']['volumes']
        || $request['target_policy'] !== $layout['adoption']['target_policy']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_INVENTORY_INVALID');
    }
    $runtimeName = $request['candidate_volumes']['install']['volume_name'];
    $uploadsName = $request['candidate_volumes']['uploads']['volume_name'];
    $runtime = bootstrapAdoptDockerVolumeObservation($runtimeVolumePath, $runtimeName);
    $uploads = bootstrapAdoptDockerVolumeObservation($uploadsVolumePath, $uploadsName);
    foreach (['install', 'local_storage', 'runtime_backup'] as $artifact) {
        $volume = $request['candidate_volumes'][$artifact];
        if ($volume['volume_name'] !== $runtime['volume_name']
            || $volume['docker_volume_id'] !== $runtime['docker_volume_id']
            || $volume['labels_sha256'] !== $runtime['labels_sha256']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_IDENTITY_CHANGED');
        }
    }
    $uploadVolume = $request['candidate_volumes']['uploads'];
    if ($uploadVolume['volume_name'] !== $uploads['volume_name']
        || $uploadVolume['docker_volume_id'] !== $uploads['docker_volume_id']
        || $uploadVolume['labels_sha256'] !== $uploads['labels_sha256']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_IDENTITY_CHANGED');
    }
    foreach ([
        'cert' => $certRoot, 'demo' => $demoRoot, 'public_storage' => $publicStorageRoot,
    ] as $artifact => $root) {
        $bind = bootstrapAdoptCurrentBindObservation($root, $artifact, $agentUid, $sharedGid);
        $volume = $request['candidate_volumes'][$artifact];
        if ($volume['volume_name'] !== $bind['volume_name']
            || $volume['docker_volume_id'] !== $bind['docker_volume_id']
            || $volume['labels_sha256'] !== $bind['labels_sha256']) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_IDENTITY_CHANGED');
        }
    }
    $roots = [
        'cert' => $certRoot,
        'demo' => $demoRoot,
        'install' => rtrim($runtimeRoot, '/') . '/install',
        'local_storage' => rtrim($runtimeRoot, '/') . '/storage',
        'public_storage' => $publicStorageRoot,
        'runtime_backup' => rtrim($runtimeRoot, '/') . '/backup',
        'uploads' => $uploadsRoot,
    ];
    foreach ($roots as $artifact => $root) {
        bootstrapAdoptVerifyFinalRoot($root, $artifact, $request, $agentUid, $appUid, $sharedGid);
    }
    $normalization = bootstrapAdoptReadEvidence(
        $resultRoot . '/normalization/receipt.json',
        'storage_bootstrap_adopt_normalization_receipt',
    );
    $import = bootstrapAdoptReadEvidence(
        $resultRoot . '/import/receipt.json',
        'storage_bootstrap_adopt_import_receipt',
    );
    $composite = bootstrapAdoptReadEvidence(
        $resultRoot . '/import/composite.json',
        'storage_bootstrap_adopt_composite_receipt',
    );
    $confirmation = bootstrapAdoptReadEvidence(
        $resultRoot . '/target/confirmation.json',
        'storage_bootstrap_adopt_target_confirmation',
    );
    foreach ([$normalization, $import, $composite, $confirmation] as $evidence) {
        if (($evidence['operation_id'] ?? null) !== $operation || ($evidence['complete'] ?? null) !== true) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_FINALIZE_EVIDENCE_INVALID');
        }
    }
    $normalizationBinding = $normalization;
    unset($normalizationBinding['receipt_sha256']);
    $importBinding = $import;
    unset($importBinding['receipt_sha256']);
    $compositeBinding = $composite;
    unset($compositeBinding['receipt_sha256']);
    $confirmationBinding = $confirmation;
    unset($confirmationBinding['confirmation_sha256']);
    if (($normalization['receipt_sha256'] ?? null) !== bootstrapAdoptHash(bootstrapAdoptCanonical($normalizationBinding))
        || ($import['receipt_sha256'] ?? null) !== bootstrapAdoptHash(bootstrapAdoptCanonical($importBinding))
        || ($composite['receipt_sha256'] ?? null) !== bootstrapAdoptHash(bootstrapAdoptCanonical($compositeBinding))
        || ($confirmation['confirmation_sha256'] ?? null) !== bootstrapAdoptHash(bootstrapAdoptCanonical($confirmationBinding))
        || $import['normalization_receipt_sha256'] !== $normalization['receipt_sha256']
        || $composite['normalization_receipt_sha256'] !== $normalization['receipt_sha256']
        || $composite['import_receipt_sha256'] !== $import['receipt_sha256']
        || $confirmation['composite_receipt_sha256'] !== $composite['receipt_sha256']) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_FINALIZE_EVIDENCE_INVALID');
    }
    $markerTuple = [];
    $inventoryVolumes = [];
    foreach ($request['candidate_volumes'] as $artifact => $volume) {
        $markerTuple[$artifact] = [
            'marker_id' => $volume['marker_id'], 'marker_sha256' => $volume['marker_sha256'],
        ];
        $inventoryVolumes[$artifact] = [
            'artifact' => $artifact,
            'volume_name' => $volume['volume_name'],
            'docker_volume_id' => $volume['docker_volume_id'],
            'labels_sha256' => $volume['labels_sha256'],
            'source_mode' => $volume['source_mode'],
            'marker_id' => $volume['marker_id'],
            'marker_sha256' => $volume['marker_sha256'],
            'final_content_root' => $volume['expected_content_root'],
        ];
    }
    $protectedMarkers = bootstrapAdoptHash(bootstrapAdoptCanonical($markerTuple));
    $targetInventory = bootstrapAdoptHash(bootstrapAdoptCanonical([
        'target_policy' => $request['target_policy'], 'volumes' => $inventoryVolumes,
    ]));
    if ($composite['protected_markers_sha256'] !== $protectedMarkers
        || $composite['target_inventory_sha256'] !== $targetInventory) {
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_HOST_INVENTORY_INVALID');
    }
    $sourceIdentities = [
        'install' => $runtime['docker_volume_id'],
        'local_storage' => $runtime['docker_volume_id'],
        'runtime_backup' => $runtime['docker_volume_id'],
        'uploads' => $uploads['docker_volume_id'],
    ];
    $targetIdentities = [];
    foreach ($request['candidate_volumes'] as $artifact => $volume) {
        $targetIdentities[$artifact] = $volume['docker_volume_id'];
    }
    $hostWithoutHash = [
        'volume_identities' => ['source' => $sourceIdentities, 'target' => $targetIdentities],
        'marker_tuple_sha256' => $protectedMarkers,
        'metadata_policy_sha256' => $layout['adoption']['target_policy_sha256'],
        'target_inventory_sha256' => $targetInventory,
        'complete' => true,
    ];
    $host = [
        'inspection_sha256' => bootstrapAdoptHash(bootstrapAdoptCanonical($hostWithoutHash)),
        ...$hostWithoutHash,
    ];
    $hostBytes = bootstrapAdoptCanonical([
        'schema_version' => 1,
        'purpose' => 'storage_bootstrap_adopt_host_inspection',
        'evidence' => $host,
    ]);
    $finalizeWithoutHash = [
        'operation_id' => $operation,
        'retention_receipt_sha256' => $layout['adoption']['retention_receipt_sha256'],
        'normalization_receipt_sha256' => $normalization['receipt_sha256'],
        'import_receipt_sha256' => $import['receipt_sha256'],
        'composite_receipt_sha256' => $composite['receipt_sha256'],
        'target_confirmation_sha256' => $confirmation['confirmation_sha256'],
        'host_inspection_sha256' => $host['inspection_sha256'],
        'complete' => true,
    ];
    $finalize = $finalizeWithoutHash;
    $finalize['finalize_receipt_sha256'] = bootstrapAdoptHash(bootstrapAdoptCanonical($finalizeWithoutHash));
    // Match Go struct field order: finalize_receipt_sha256 precedes complete.
    $finalize = [
        'operation_id' => $finalize['operation_id'],
        'retention_receipt_sha256' => $finalize['retention_receipt_sha256'],
        'normalization_receipt_sha256' => $finalize['normalization_receipt_sha256'],
        'import_receipt_sha256' => $finalize['import_receipt_sha256'],
        'composite_receipt_sha256' => $finalize['composite_receipt_sha256'],
        'target_confirmation_sha256' => $finalize['target_confirmation_sha256'],
        'host_inspection_sha256' => $finalize['host_inspection_sha256'],
        'finalize_receipt_sha256' => $finalize['finalize_receipt_sha256'],
        'complete' => true,
    ];
    $finalizeBytes = bootstrapAdoptCanonical([
        'schema_version' => 1,
        'purpose' => 'storage_bootstrap_adopt_finalize',
        'evidence' => $finalize,
    ]);
    bootstrapAdoptAssertAbsentOrEqual(
        $resultRoot . '/host/inspection.json', $hostBytes, $agentUid, $sharedGid,
    );
    bootstrapAdoptAssertAbsentOrEqual(
        $resultRoot . '/finalize/receipt.json', $finalizeBytes, $agentUid, $sharedGid,
    );
    bootstrapAdoptPublish($resultRoot . '/host/inspection.json', $hostBytes, $agentUid, $sharedGid);
    bootstrapAdoptPublish($resultRoot . '/finalize/receipt.json', $finalizeBytes, $agentUid, $sharedGid);
}

/** @return array<string,string> */
function bootstrapAdoptReceiptVectors(): array
{
    $operation = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    $hashA = 'sha256:' . str_repeat('a', 64);
    $hashB = 'sha256:' . str_repeat('b', 64);
    $policy = ['app_uid' => 1000, 'shared_gid' => 1000, 'root_mode' => '03770', 'dir_mode' => '02770', 'file_mode' => '0660'];
    $normalization = [
        'operation_id' => $operation, 'before_content_roots' => ['uploads' => $hashA],
        'after_content_roots' => ['uploads' => $hashA], 'target_policy' => $policy, 'complete' => true,
    ];
    $normalizationHash = bootstrapAdoptHash(bootstrapAdoptCanonical($normalization));
    $volumes = [
        'bridge' => [
            'artifact' => 'bridge', 'source_mode' => 'candidate', 'volume_name' => 'mb_bridge',
            'docker_volume_id' => 'docker-bridge', 'labels_sha256' => $hashB,
            'marker_id' => 'eb3f56cf-446b-4c0e-b147-3f2860be5077', 'marker_sha256' => $hashA,
            'expected_content_root' => $hashB, 'empty_at_prepare' => true,
        ],
        'uploads' => [
            'artifact' => 'uploads', 'source_mode' => 'legacy_broad', 'volume_name' => 'mb_uploads',
            'docker_volume_id' => 'docker-uploads', 'labels_sha256' => $hashA,
            'marker_id' => 'e96d02ca-4367-453a-9943-2ffc892c099b', 'marker_sha256' => $hashB,
            'expected_content_root' => $hashA, 'empty_at_prepare' => false,
        ],
    ];
    $roots = ['bridge' => $hashB, 'uploads' => $hashA];
    $import = [
        'operation_id' => $operation, 'normalization_receipt_sha256' => $normalizationHash,
        'frozen_manifest_sha256' => $hashB, 'imported_delta_sha256' => $hashA,
        'target_content_roots' => $roots, 'volume_markers' => $volumes, 'complete' => true,
    ];
    $importHash = bootstrapAdoptHash(bootstrapAdoptCanonical($import));
    $composite = [
        'operation_id' => $operation, 'normalization_receipt_sha256' => $normalizationHash,
        'import_receipt_sha256' => $importHash, 'protected_markers_sha256' => $hashA,
        'target_inventory_sha256' => $hashB, 'complete' => true,
    ];
    $compositeHash = bootstrapAdoptHash(bootstrapAdoptCanonical($composite));
    $confirmation = [
        'operation_id' => $operation, 'composite_receipt_sha256' => $compositeHash,
        'target_authorization_sha256' => $hashA, 'verified_target_roots' => $roots,
        'local_setting_receipt_sha256' => $hashB, 'complete' => true,
    ];
    $confirmationHash = bootstrapAdoptHash(bootstrapAdoptCanonical($confirmation));
    $host = [
        'volume_identities' => [
            'source' => ['uploads' => 'docker-uploads'],
            'target' => ['bridge' => 'docker-bridge', 'uploads' => 'docker-uploads'],
        ],
        'marker_tuple_sha256' => $hashA, 'metadata_policy_sha256' => $hashB,
        'target_inventory_sha256' => $hashA, 'complete' => true,
    ];
    $hostHash = bootstrapAdoptHash(bootstrapAdoptCanonical($host));
    $finalize = [
        'operation_id' => $operation, 'retention_receipt_sha256' => $hashA,
        'normalization_receipt_sha256' => $normalizationHash, 'import_receipt_sha256' => $importHash,
        'composite_receipt_sha256' => $compositeHash, 'target_confirmation_sha256' => $confirmationHash,
        'host_inspection_sha256' => $hostHash, 'complete' => true,
    ];

    return [
        'normalization' => $normalizationHash,
        'import' => $importHash,
        'composite' => $compositeHash,
        'confirmation' => $confirmationHash,
        'host' => $hostHash,
        'finalize' => bootstrapAdoptHash(bootstrapAdoptCanonical($finalize)),
    ];
}

if ($argc < 2) {
    bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
}

switch ($argv[1]) {
    case 'bootstrap-id-field':
        if ($argc !== 4) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        $bootstrap = bootstrapAdoptReadBootstrapID($argv[2]);
        if (!isset($bootstrap[$argv[3]])) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo $bootstrap[$argv[3]], PHP_EOL;
        break;
    case 'layout-field':
        if ($argc !== 5) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo bootstrapAdoptLayoutField(
            bootstrapAdoptReadLayout($argv[2], $argv[3]),
            $argv[4],
        ), PHP_EOL;
        break;
    case 'layout-summary-field':
        if ($argc !== 4 || !in_array($argv[3], [
            'state', 'phase', 'authority_revision', 'operation_id',
        ], true)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        }
        $summary = bootstrapAdoptReadLayoutSummary($argv[2]);
        echo $summary[$argv[3]], PHP_EOL;
        break;
    case 'layout-volume-field':
        if ($argc !== 6) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo bootstrapAdoptLayoutVolumeField(
            bootstrapAdoptReadLayout($argv[2], $argv[3]),
            $argv[4],
            $argv[5],
        ), PHP_EOL;
        break;
    case 'build-output-field':
        if ($argc !== 4 || !in_array($argv[3], ['receipt_id', 'image_id', 'display_tag'], true)) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        }
        $build = bootstrapAdoptReadBuildOutput($argv[2]);
        echo $build[$argv[3]], PHP_EOL;
        break;
    case 'container-field':
        if ($argc !== 5) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        $container = bootstrapAdoptContainerObservation($argv[2], $argv[3]);
        if (!isset($container[$argv[4]])) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo $container[$argv[4]], PHP_EOL;
        break;
    case 'docker-volume-field':
        if ($argc !== 5) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        $volume = bootstrapAdoptDockerVolumeObservation($argv[2], $argv[3]);
        if (!isset($volume[$argv[4]])) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo $volume[$argv[4]], PHP_EOL;
        break;
    case 'bind-field':
        if ($argc !== 7 || preg_match('/^[1-9][0-9]{0,9}$/D', $argv[4]) !== 1
            || preg_match('/^[1-9][0-9]{0,9}$/D', $argv[5]) !== 1) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        }
        $bind = bootstrapAdoptBindObservation($argv[2], $argv[3], (int) $argv[4], (int) $argv[5]);
        if (!isset($bind[$argv[6]])) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo $bind[$argv[6]], PHP_EOL;
        break;
    case 'probe-field':
        if ($argc !== 5) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        $probe = bootstrapAdoptReadRetentionProbe($argv[2]);
        $artifact = $argv[4] === '-' ? null : $argv[4];
        echo bootstrapAdoptRetentionProbeField($probe, $argv[3], $artifact), PHP_EOL;
        break;
    case 'content-root':
        if ($argc !== 3) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        echo bootstrapAdoptTree($argv[2], false)['hash'], PHP_EOL;
        break;
    case 'validate-uploads-manifest':
        if ($argc !== 4 || !bootstrapAdoptIsHash($argv[3])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        }
        echo bootstrapAdoptCanonical(bootstrapAdoptStreamUploadsManifest($argv[2], $argv[3]));
        break;
    case 'fsync-retention':
        if ($argc !== 4) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        bootstrapAdoptFsyncRetention($argv[2], $argv[3]);
        break;
    case 'write-source':
        if ($argc !== 17) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        foreach ([13, 14, 15] as $index) {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $argv[$index]) !== 1) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
            }
        }
        bootstrapAdoptWriteSource(
            $argv[2], $argv[3], $argv[4], $argv[5], $argv[6], $argv[7], $argv[8],
            $argv[9], $argv[10], $argv[11], $argv[12],
            (int) $argv[13], (int) $argv[14], (int) $argv[15], $argv[16],
        );
        break;
    case 'normalize':
        if ($argc !== 7) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        bootstrapAdoptNormalize($argv[2], $argv[3], $argv[4], $argv[5], $argv[6]);
        break;
    case 'import':
        if ($argc !== 12) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        $request = bootstrapAdoptReadCanonical($argv[2], 0444);
        bootstrapAdoptValidateImportRequest($request);
        if (!bootstrapAdoptIsUuid($argv[11]) || !hash_equals($argv[11], (string) $request['operation_id'])) {
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_OPERATION_MISMATCH');
        }
        bootstrapAdoptImport(
            $request,
            $argv[3],
            [
                'cert' => $argv[6],
                'demo' => $argv[7],
                'install' => rtrim($argv[4], '/') . '/install',
                'local_storage' => rtrim($argv[4], '/') . '/storage',
                'public_storage' => $argv[8],
                'runtime_backup' => rtrim($argv[4], '/') . '/backup',
                'uploads' => $argv[5],
            ],
            $argv[9],
            $argv[10],
        );
        break;
    case 'receipt-vectors':
        if ($argc !== 2) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        $want = [
            'normalization' => 'sha256:eb41c4e8f21cd967be5b6ead64316a9da8b43caa80016da37a574bceb1fd036d',
            'import' => 'sha256:f8ca8c23ae6bd98d92da290440ccd3c978b6755a88b35724e78beb0ec9de0387',
            'composite' => 'sha256:e534f2c54384c63d97b8cb9e61f78067e9862b94dc9e9f40fd19e178d1589827',
            'confirmation' => 'sha256:be41453dae111608e19e183eb0e75ce8c00bc632fd6dc8509c1ac1a93c177209',
            'host' => 'sha256:e1e772b5751f93fe471a64de0b076e916c0d54de2106b6c6e5f714bd5e5bb358',
            'finalize' => 'sha256:6a2824fa7ef2e6199b0203e92f6890c2b10c414c602de7445b7cb93b0cf26a52',
        ];
        $got = bootstrapAdoptReceiptVectors();
        if ($got !== $want) {
            fwrite(STDERR, bootstrapAdoptCanonical(['got' => $got, 'want' => $want]));
            bootstrapAdoptFail('BOOTSTRAP_ADOPT_RECEIPT_VECTOR_MISMATCH');
        }
        echo bootstrapAdoptCanonical($got);
        break;
    case 'publish-target':
        if ($argc !== 10) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        foreach ([7, 8, 9] as $index) {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $argv[$index]) !== 1) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
            }
        }
        bootstrapAdoptPublishTargetOutput(
            $argv[2], $argv[3], $argv[4], $argv[5], $argv[6],
            (int) $argv[7], (int) $argv[8], (int) $argv[9],
        );
        break;
    case 'write-host-finalize':
        if ($argc !== 16) bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
        foreach ([13, 14, 15] as $index) {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $argv[$index]) !== 1) {
                bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
            }
        }
        bootstrapAdoptWriteHostFinalize(
            $argv[2], $argv[3], $argv[4], $argv[5], $argv[6], $argv[7],
            $argv[8], $argv[9], $argv[10], $argv[11], $argv[12],
            (int) $argv[13], (int) $argv[14], (int) $argv[15],
        );
        break;
    default:
        bootstrapAdoptFail('BOOTSTRAP_ADOPT_VALIDATOR_USAGE');
}
