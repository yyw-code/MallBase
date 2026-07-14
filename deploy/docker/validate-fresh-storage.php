<?php

declare(strict_types=1);

const FRESH_MAX_JSON_BYTES = 1048576;

function freshFail(string $code): never
{
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}

function freshReadJson(string $path, bool $canonical = true): array
{
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
        || $stat['nlink'] !== 1 || $stat['size'] < 2 || $stat['size'] > FRESH_MAX_JSON_BYTES) {
        freshFail('FRESH_STORAGE_INPUT_INVALID');
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        freshFail('FRESH_STORAGE_INPUT_INVALID');
    }
    $decode = $raw;
    if ($canonical) {
        if (!str_ends_with($raw, "\n") || str_ends_with($raw, "\n\n")) {
            freshFail('FRESH_STORAGE_JSON_NOT_CANONICAL');
        }
        $decode = substr($raw, 0, -1);
        if ($decode === '' || str_contains($decode, "\n") || str_contains($decode, "\r")) {
            freshFail('FRESH_STORAGE_JSON_NOT_CANONICAL');
        }
    }
    try {
        $value = json_decode($decode, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        freshFail('FRESH_STORAGE_JSON_INVALID');
    }
    if (!is_array($value) || ($canonical && array_is_list($value))) {
        freshFail('FRESH_STORAGE_JSON_INVALID');
    }
    if ($canonical) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_string($encoded) || !hash_equals($decode, $encoded)) {
            freshFail('FRESH_STORAGE_JSON_NOT_CANONICAL');
        }
    }

    return $value;
}

function freshUuid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

function freshNamespace(mixed $value): bool
{
    return is_string($value) && preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $value) === 1;
}

function freshHash(mixed $value): bool
{
    return is_string($value) && preg_match('/^sha256:[0-9a-f]{64}$/D', $value) === 1;
}

function freshBootstrap(string $path): array
{
    $value = freshReadJson($path);
    if (array_keys($value) !== ['schema_version', 'operation_id', 'installation_storage_namespace', 'agent_uid', 'shared_gid']
        || $value['schema_version'] !== 1 || !freshUuid($value['operation_id'])
        || !freshNamespace($value['installation_storage_namespace'])
        || !is_int($value['agent_uid']) || $value['agent_uid'] < 0
        || !is_int($value['shared_gid']) || $value['shared_gid'] < 0) {
        freshFail('FRESH_STORAGE_BOOTSTRAP_ID_INVALID');
    }

    return $value;
}

function freshAtomicNamespace(string $projectRoot, string $namespace): void
{
    $root = realpath($projectRoot);
    if (!is_string($root) || !is_dir($root) || is_link($projectRoot)) {
        freshFail('FRESH_STORAGE_PROJECT_ROOT_INVALID');
    }
    $path = $root . '/.env';
    $lockPath = $root . '/upgrade/agent-private/storage-bootstrap-env.lock';
    if (!is_dir(dirname($lockPath)) || is_link(dirname($lockPath))) {
        freshFail('FRESH_STORAGE_ENV_LOCK_FAILED');
    }
    if (file_exists($lockPath) || is_link($lockPath)) {
        $lockStat = @lstat($lockPath);
        if (!is_array($lockStat) || ($lockStat['mode'] & 0170000) !== 0100000 || is_link($lockPath)
            || $lockStat['nlink'] !== 1 || ($lockStat['mode'] & 0777) !== 0600) {
            freshFail('FRESH_STORAGE_ENV_LOCK_FAILED');
        }
        $lock = @fopen($lockPath, 'c+b');
    } else {
        $lock = @fopen($lockPath, 'x+b');
    }
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        freshFail('FRESH_STORAGE_ENV_LOCK_FAILED');
    }
    @chmod($lockPath, 0600);
    $contents = '';
    if (file_exists($path) || is_link($path)) {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path) || $stat['nlink'] !== 1) {
            freshFail('FRESH_STORAGE_ENV_INVALID');
        }
        $read = @file_get_contents($path);
        if (!is_string($read) || strlen($read) > FRESH_MAX_JSON_BYTES || str_contains($read, "\0")) {
            freshFail('FRESH_STORAGE_ENV_INVALID');
        }
        $contents = $read;
    }
    $lines = preg_split('/\r?\n/', $contents);
    if (!is_array($lines)) {
        freshFail('FRESH_STORAGE_ENV_INVALID');
    }
    $result = [];
    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, 'MALLBASE_STORAGE_NAMESPACE=')) {
            continue;
        }
        $result[] = $line;
    }
    $result[] = 'MALLBASE_STORAGE_NAMESPACE=' . $namespace;
    $payload = implode("\n", $result) . "\n";
    $temp = @tempnam($root, '.env.storage-bootstrap.');
    if (!is_string($temp)) {
        freshFail('FRESH_STORAGE_ENV_WRITE_FAILED');
    }
    $handle = @fopen($temp, 'c+b');
    if (!is_resource($handle)) {
        @unlink($temp);
        freshFail('FRESH_STORAGE_ENV_WRITE_FAILED');
    }
    if (!@chmod($temp, 0600) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)
        || (function_exists('fsync') && !fsync($handle))) {
        fclose($handle);
        @unlink($temp);
        freshFail('FRESH_STORAGE_ENV_WRITE_FAILED');
    }
    fclose($handle);
    if (!@rename($temp, $path)) {
        @unlink($temp);
        freshFail('FRESH_STORAGE_ENV_WRITE_FAILED');
    }
    $directory = @fopen($root, 'r');
    if (is_resource($directory)) {
        if (function_exists('fsync')) {
            @fsync($directory);
        }
        fclose($directory);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

function freshDockerIdentity(string $path, string $namespace, string $role): array
{
    if (!freshNamespace($namespace) || !in_array($role, ['runtime', 'uploads'], true)) {
        freshFail('FRESH_STORAGE_DOCKER_IDENTITY_INVALID');
    }
    $decoded = freshReadJson($path, false);
    if (!array_is_list($decoded) || count($decoded) !== 1 || !is_array($decoded[0])) {
        freshFail('FRESH_STORAGE_DOCKER_IDENTITY_INVALID');
    }
    $volume = $decoded[0];
    $name = $namespace . '_' . $role;
    $required = [
        'com.mallbase.storage.layout-generation' => '1',
        'com.mallbase.storage.layout-version' => '1',
        'com.mallbase.storage.managed' => 'true',
        'com.mallbase.storage.namespace' => $namespace,
        'com.mallbase.storage.role' => $role,
    ];
    if (($volume['Name'] ?? null) !== $name || ($volume['Driver'] ?? null) !== 'local'
        || ($volume['Scope'] ?? null) !== 'local' || !is_array($volume['Labels'] ?? null)) {
        freshFail('FRESH_STORAGE_DOCKER_IDENTITY_INVALID');
    }
    if ($volume['Labels'] !== $required) {
        freshFail('FRESH_STORAGE_DOCKER_LABELS_INVALID');
    }
    foreach ($required as $key => $expected) {
        if (($volume['Labels'][$key] ?? null) !== $expected) {
            freshFail('FRESH_STORAGE_DOCKER_LABELS_INVALID');
        }
    }
    $policy = 'sha256:' . hash('sha256', json_encode($required, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $identity = [
        'driver' => 'local',
        'labels_sha256' => $policy,
        'name' => $name,
        'scope' => 'local',
    ];

    return [
        'volume_name' => $name,
        'policy_sha256' => $policy,
        'mount_identity' => 'docker:sha256:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"),
    ];
}

function freshLayoutField(string $path, string $operation, string $field): string
{
    $layout = freshReadJson($path);
    if (!freshUuid($operation) || ($layout['schema_version'] ?? null) !== 1
        || !freshNamespace($layout['installation_storage_namespace'] ?? null)
        || !is_int($layout['authority_revision'] ?? null) || !is_string($layout['state'] ?? null)) {
        freshFail('FRESH_STORAGE_LAYOUT_INVALID');
    }
    $fresh = $layout['fresh'] ?? null;
    if (is_array($fresh) && ($fresh['operation_id'] ?? null) !== $operation) {
        freshFail('FRESH_STORAGE_LAYOUT_INVALID');
    }
    return match ($field) {
        'state' => $layout['state'],
        'namespace' => $layout['installation_storage_namespace'],
        'authority_revision' => (string) $layout['authority_revision'],
        'layout_generation' => is_array($fresh) && is_int($fresh['layout_generation'] ?? null)
            ? (string) $fresh['layout_generation'] : freshFail('FRESH_STORAGE_LAYOUT_INVALID'),
        default => freshFail('FRESH_STORAGE_LAYOUT_FIELD_INVALID'),
    };
}

function freshStartField(string $path, string $field): string
{
    $layout = freshReadJson($path);
    if (($layout['schema_version'] ?? null) !== 1
        || !freshNamespace($layout['installation_storage_namespace'] ?? null)
        || !is_int($layout['authority_revision'] ?? null) || $layout['authority_revision'] <= 0
        || !is_string($layout['state'] ?? null)) {
        freshFail('STORAGE_START_LAYOUT_INVALID');
    }

    $fresh = $layout['fresh'] ?? null;
    $adoption = $layout['adoption'] ?? null;
    $mode = 'blocked';
    $operation = '';
    if (is_array($adoption)) {
        $operation = $adoption['operation_id'] ?? '';
        if (!freshUuid($operation) || ($layout['migration_id'] ?? null) !== $operation) {
            freshFail('STORAGE_START_LAYOUT_INVALID');
        }
        $active = $layout['active'] ?? null;
        if ($layout['state'] === 'ready'
            && !array_key_exists('candidate', $layout)
            && !array_key_exists('adoption_phase', $layout)
            && is_array($active) && ($active['boot_eligible'] ?? null) === true
            && freshHash($active['finalize_receipt_sha256'] ?? null)
            && freshHash($adoption['target_confirmation_sha256'] ?? null)
            && freshHash($adoption['host_inspection_sha256'] ?? null)
            && freshHash($adoption['finalize_evidence_sha256'] ?? null)) {
            $mode = 'adoption_ready';
        }
    } elseif (is_array($fresh)) {
        if (!freshUuid($fresh['operation_id'] ?? null)) {
            freshFail('STORAGE_START_LAYOUT_INVALID');
        }
        if (in_array($layout['state'], ['provisioning', 'ready'], true)) {
            $mode = 'fresh_finalize';
        }
    }

    return match ($field) {
        'mode' => $mode,
        'authority_revision' => (string) $layout['authority_revision'],
        'operation_id' => $operation !== '' ? $operation : freshFail('STORAGE_START_LAYOUT_INVALID'),
        default => freshFail('STORAGE_START_FIELD_INVALID'),
    };
}

function freshRequestField(string $path, string $operation, string $field): string
{
    $request = freshReadJson($path);
    if (($request['schema_version'] ?? null) !== 1 || ($request['purpose'] ?? null) !== 'fresh_storage_init'
        || ($request['operation_id'] ?? null) !== $operation || !freshNamespace($request['installation_storage_namespace'] ?? null)
        || ($request['storage_layout_version'] ?? null) !== 1 || ($request['layout_generation'] ?? null) !== 1) {
        freshFail('FRESH_STORAGE_REQUEST_INVALID');
    }
    return match ($field) {
        'namespace' => $request['installation_storage_namespace'],
        'layout_generation' => '1',
        'sha256' => 'sha256:' . hash_file('sha256', $path),
        default => freshFail('FRESH_STORAGE_REQUEST_FIELD_INVALID'),
    };
}

if ($argc < 3) {
    freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
}

switch ($argv[1]) {
    case 'bootstrap-field':
        if ($argc !== 4) freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
        $bootstrap = freshBootstrap($argv[2]);
        $field = $argv[3];
        if (!in_array($field, ['operation_id', 'installation_storage_namespace', 'agent_uid', 'shared_gid'], true)) {
            freshFail('FRESH_STORAGE_BOOTSTRAP_FIELD_INVALID');
        }
        echo (string) $bootstrap[$field], PHP_EOL;
        break;
    case 'write-namespace-env':
        if ($argc !== 4) freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
        $bootstrap = freshBootstrap($argv[2]);
        freshAtomicNamespace($argv[3], $bootstrap['installation_storage_namespace']);
        break;
    case 'docker-field':
        if ($argc !== 6) freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
        $identity = freshDockerIdentity($argv[2], $argv[3], $argv[4]);
        if (!array_key_exists($argv[5], $identity)) freshFail('FRESH_STORAGE_DOCKER_FIELD_INVALID');
        echo $identity[$argv[5]], PHP_EOL;
        break;
    case 'layout-field':
        if ($argc !== 5) freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
        echo freshLayoutField($argv[2], $argv[3], $argv[4]), PHP_EOL;
        break;
    case 'start-field':
        if ($argc !== 4) freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
        echo freshStartField($argv[2], $argv[3]), PHP_EOL;
        break;
    case 'request-field':
        if ($argc !== 5) freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
        echo freshRequestField($argv[2], $argv[3], $argv[4]), PHP_EOL;
        break;
    default:
        freshFail('FRESH_STORAGE_VALIDATOR_USAGE');
}
