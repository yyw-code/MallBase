<?php

declare(strict_types=1);

const MAX_JSON_BYTES = 1048576;

function fail(string $code): never
{
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}

function isRegularFile(string $path, ?array &$stat = null): bool
{
    $stat = @lstat($path);

    return is_array($stat)
        && (($stat['mode'] & 0170000) === 0100000)
        && !is_link($path)
        && $stat['nlink'] === 1;
}

function requireRegularFile(string $path, int $maxBytes, ?array $allowedModes = null): array
{
    $stat = null;
    if (!isRegularFile($path, $stat) || $stat['size'] < 0 || $stat['size'] > $maxBytes) {
        fail('SEALED_INPUT_FILE_INVALID');
    }
    $mode = $stat['mode'] & 0777;
    if ($allowedModes !== null && !in_array($mode, $allowedModes, true)) {
        fail('SEALED_INPUT_MODE_INVALID');
    }

    return $stat;
}

function readFileBounded(string $path, int $maxBytes, ?array $allowedModes = null): string
{
    requireRegularFile($path, $maxBytes, $allowedModes);
    $contents = @file_get_contents($path);
    if (!is_string($contents) || $contents === '' || strlen($contents) > $maxBytes) {
        fail('SEALED_INPUT_READ_FAILED');
    }

    return $contents;
}

function decodeCanonicalJson(string $path, bool $allowTrailingLf = true): array
{
    $raw = readFileBounded($path, MAX_JSON_BYTES);
    if ($allowTrailingLf && str_ends_with($raw, "\n")) {
        $raw = substr($raw, 0, -1);
    }
    if ($raw === '' || str_contains($raw, "\n") || str_contains($raw, "\r")) {
        fail('SEALED_JSON_ENCODING_INVALID');
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('SEALED_JSON_INVALID');
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        fail('SEALED_JSON_SHAPE_INVALID');
    }
    $canonical = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!is_string($canonical) || !hash_equals($canonical, $raw)) {
        fail('SEALED_JSON_NOT_CANONICAL');
    }

    return $decoded;
}

function assertKeys(array $value, array $required, array $optional = []): void
{
    $actual = array_keys($value);
    $allowed = array_merge($required, $optional);
    sort($actual, SORT_STRING);
    sort($allowed, SORT_STRING);
    if ($actual !== $allowed) {
        $requiredOnly = array_keys(array_intersect_key($value, array_fill_keys($required, true)));
        sort($requiredOnly, SORT_STRING);
        $expectedRequired = $required;
        sort($expectedRequired, SORT_STRING);
        $unknown = array_diff(array_keys($value), array_merge($required, $optional));
        if ($requiredOnly !== $expectedRequired || $unknown !== []) {
            fail('SEALED_JSON_FIELDS_INVALID');
        }
    }
    foreach ($required as $key) {
        if (!array_key_exists($key, $value)) {
            fail('SEALED_JSON_FIELDS_INVALID');
        }
    }
    foreach (array_keys($value) as $key) {
        if (!in_array($key, $allowed, true)) {
            fail('SEALED_JSON_FIELDS_INVALID');
        }
    }
}

function isReceiptId(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{32}$/D', $value) === 1;
}

function isHash(mixed $value, bool $prefixRequired = false): bool
{
    if (!is_string($value)) {
        return false;
    }
    $pattern = $prefixRequired ? '/^sha256:[0-9a-f]{64}$/D' : '/^(?:sha256:)?[0-9a-f]{64}$/D';

    return preg_match($pattern, $value) === 1;
}

function plainHash(string $value): string
{
    return str_starts_with($value, 'sha256:') ? substr($value, 7) : $value;
}

function isUuid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

function isSafeVersion(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,127}$/D', $value) === 1;
}

function decodeBase64Secret(mixed $value): string
{
    if (!is_string($value) || strlen($value) !== 44) {
        fail('SEALED_CHALLENGE_INVALID');
    }
    $decoded = base64_decode($value, true);
    if (!is_string($decoded) || strlen($decoded) !== 32 || base64_encode($decoded) !== $value) {
        fail('SEALED_CHALLENGE_INVALID');
    }

    return $decoded;
}

function validateSealReceipt(array $receipt, string $receiptId, string $sealId): void
{
    $required = [
        'schema_version', 'seal_id', 'receipt_id', 'tar_sha256', 'tar_size', 'attestation_sha256',
        'deployment_id', 'app_version', 'release_inventory_sha256', 'release_inventory_envelope_sha256',
        'deployment_marker_sha256', 'active_provenance_sha256', 'active_revision', 'storage_layout_version',
        'storage_layout_generation', 'source_date_epoch', 'entry_count',
    ];
    assertKeys($receipt, $required, ['main_manifest_sha256', 'job_id']);
    if ($receipt['schema_version'] !== 1 || $receipt['receipt_id'] !== $receiptId || $receipt['seal_id'] !== $sealId
        || !isHash($receipt['tar_sha256']) || !is_int($receipt['tar_size']) || $receipt['tar_size'] < 1
        || !isHash($receipt['attestation_sha256']) || !isUuid($receipt['deployment_id'])
        || !isSafeVersion($receipt['app_version']) || !isHash($receipt['release_inventory_sha256'])
        || !isHash($receipt['release_inventory_envelope_sha256']) || !isHash($receipt['deployment_marker_sha256'])
        || !isHash($receipt['active_provenance_sha256']) || !is_int($receipt['active_revision'])
        || $receipt['active_revision'] < 1 || !is_int($receipt['storage_layout_version'])
        || $receipt['storage_layout_version'] < 1 || !is_int($receipt['storage_layout_generation'])
        || $receipt['storage_layout_generation'] < 1 || !is_int($receipt['source_date_epoch'])
        || $receipt['source_date_epoch'] < 0 || !is_int($receipt['entry_count']) || $receipt['entry_count'] < 2) {
        fail('SEALED_RECEIPT_INVALID');
    }
    if (array_key_exists('main_manifest_sha256', $receipt) && !isHash($receipt['main_manifest_sha256'])) {
        fail('SEALED_RECEIPT_INVALID');
    }
    if (array_key_exists('job_id', $receipt) && !isUuid($receipt['job_id'])) {
        fail('SEALED_RECEIPT_INVALID');
    }
}

function canonicalProjectRoot(string $input): string
{
    $root = realpath($input);
    if (!is_string($root) || !is_dir($root) || is_link($input)) {
        fail('SEALED_PROJECT_ROOT_INVALID');
    }

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function validatePrivatePath(string $privateRoot, string $relative, int $maxBytes, array $modes): string
{
    if (preg_match('#^build-contexts/[0-9a-f]{32}/[a-z0-9.-]+$#D', $relative) !== 1) {
        fail('SEALED_PRIVATE_PATH_INVALID');
    }
    $expected = $privateRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $real = realpath($expected);
    if (!is_string($real) || $real !== $expected || !str_starts_with($real, $privateRoot . DIRECTORY_SEPARATOR)) {
        fail('SEALED_PRIVATE_PATH_INVALID');
    }
    requireRegularFile($real, $maxBytes, $modes);

    return $real;
}

function validateCreateResult(string $jsonPath, string $projectRoot): array
{
    $create = decodeCanonicalJson($jsonPath);
    assertKeys($create, [
        'seal_id', 'receipt_id', 'tar_name', 'receipt_name', 'challenge_name', 'challenge', 'lease_token', 'receipt',
    ]);
    if (!isReceiptId($create['seal_id']) || !isReceiptId($create['receipt_id']) || !is_array($create['receipt'])) {
        fail('SEALED_CREATE_RESULT_INVALID');
    }
    $receiptId = $create['receipt_id'];
    $sealId = $create['seal_id'];
    $prefix = 'build-contexts/' . $receiptId;
    if ($create['tar_name'] !== $prefix . '/context.tar'
        || $create['receipt_name'] !== $prefix . '/receipt.json'
        || $create['challenge_name'] !== $prefix . '/challenge.secret') {
        fail('SEALED_PRIVATE_PATH_INVALID');
    }
    $challenge = decodeBase64Secret($create['challenge']);
    decodeBase64Secret($create['lease_token']);
    validateSealReceipt($create['receipt'], $receiptId, $sealId);

    $root = canonicalProjectRoot($projectRoot);
    $privateRoot = realpath($root . '/upgrade/agent-private');
    if (!is_string($privateRoot) || !is_dir($privateRoot) || is_link($root . '/upgrade/agent-private')) {
        fail('SEALED_PRIVATE_ROOT_INVALID');
    }
    $tarPath = validatePrivatePath($privateRoot, $create['tar_name'], 2147483648, [0400]);
    $receiptPath = validatePrivatePath($privateRoot, $create['receipt_name'], MAX_JSON_BYTES, [0400]);
    $challengePath = validatePrivatePath($privateRoot, $create['challenge_name'], 64, [0400]);
    $tarStat = requireRegularFile($tarPath, 2147483648, [0400]);
    if ($tarStat['size'] !== $create['receipt']['tar_size']
        || !hash_equals(str_replace('sha256:', '', $create['receipt']['tar_sha256']), hash_file('sha256', $tarPath))) {
        fail('SEALED_TAR_INTEGRITY_INVALID');
    }
    $storedReceipt = decodeCanonicalJson($receiptPath, false);
    if ($storedReceipt !== $create['receipt']) {
        fail('SEALED_RECEIPT_BINDING_INVALID');
    }
    $storedChallenge = readFileBounded($challengePath, 64, [0400]);
    if (strlen($storedChallenge) !== 32 || !hash_equals($storedChallenge, $challenge)) {
        fail('SEALED_CHALLENGE_BINDING_INVALID');
    }
    $create['_tar_path'] = $tarPath;
    $create['_challenge_raw'] = $challenge;

    return $create;
}

function requireOciId(mixed $value): string
{
    if (!is_string($value) || preg_match('/^sha256:[0-9a-f]{64}$/D', $value) !== 1) {
        fail('SEALED_OCI_ID_INVALID');
    }

    return $value;
}

function writePrivateOutput(string $path, string $contents): void
{
    $stat = requireRegularFile($path, MAX_JSON_BYTES, [0600]);
    if ($stat['size'] !== 0 || @file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        fail('SEALED_PRIVATE_OUTPUT_FAILED');
    }
    @chmod($path, 0600);
    requireRegularFile($path, MAX_JSON_BYTES, [0600]);
}

function validateImageReceipt(string $path, string $expectedReceipt): array
{
    $receipt = decodeCanonicalJson($path);
    assertKeys($receipt, ['schema_version', 'receipt_id', 'seal_id', 'image_id', 'config_digest']);
    if ($receipt['schema_version'] !== 1 || $receipt['receipt_id'] !== $expectedReceipt
        || !isReceiptId($receipt['seal_id'])) {
        fail('SEALED_IMAGE_RECEIPT_INVALID');
    }
    $receipt['image_id'] = requireOciId($receipt['image_id']);
    $receipt['config_digest'] = requireOciId($receipt['config_digest']);

    return $receipt;
}

function verifyAttestation(array $args): void
{
    if (count($args) !== 8) {
        fail('SEALED_VALIDATOR_USAGE');
    }
    [, , $attestationPath, $challengePath, $markerPath, $expectedReceipt, $expectedSeal, $outputPath] = $args;
    if (!isReceiptId($expectedReceipt) || !isReceiptId($expectedSeal)) {
        fail('SEALED_EXPECTED_ID_INVALID');
    }
    $attestation = decodeCanonicalJson($attestationPath, false);
    $required = [
        'schema_version', 'seal_id', 'receipt_id', 'app_version', 'deployment_id', 'release_inventory_sha256',
        'release_inventory_envelope_sha256', 'deployment_marker_sha256', 'active_provenance_sha256',
        'active_revision', 'source_date_epoch', 'challenge_hmac',
    ];
    assertKeys($attestation, $required, ['main_manifest_sha256']);
    if ($attestation['schema_version'] !== 1 || $attestation['receipt_id'] !== $expectedReceipt
        || $attestation['seal_id'] !== $expectedSeal || !isSafeVersion($attestation['app_version'])
        || !isUuid($attestation['deployment_id']) || !isHash($attestation['release_inventory_sha256'])
        || !isHash($attestation['release_inventory_envelope_sha256']) || !isHash($attestation['deployment_marker_sha256'])
        || !isHash($attestation['active_provenance_sha256']) || !is_int($attestation['active_revision'])
        || $attestation['active_revision'] < 1 || !is_int($attestation['source_date_epoch'])
        || $attestation['source_date_epoch'] < 0
        || !is_string($attestation['challenge_hmac'])
        || preg_match('/^[0-9a-f]{64}$/D', $attestation['challenge_hmac']) !== 1) {
        fail('SEALED_ATTESTATION_INVALID');
    }
    if (array_key_exists('main_manifest_sha256', $attestation) && !isHash($attestation['main_manifest_sha256'])) {
        fail('SEALED_ATTESTATION_INVALID');
    }
    $challenge = readFileBounded($challengePath, 64, [0400, 0600]);
    if (strlen($challenge) !== 32) {
        fail('SEALED_CHALLENGE_INVALID');
    }
    $provided = $attestation['challenge_hmac'];
    unset($attestation['challenge_hmac']);
    $payload = json_encode($attestation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!is_string($payload) || !hash_equals(hash_hmac('sha256', $payload, $challenge), $provided)) {
        fail('SEALED_ATTESTATION_HMAC_INVALID');
    }

    $marker = decodeCanonicalJson($markerPath, false);
    $markerRequired = [
        'schema_version', 'provenance_kind', 'app_version', 'deployment_id', 'release_inventory_sha256',
        'storage_layout_version', 'storage_layout_generation',
    ];
    assertKeys($marker, $markerRequired, ['job_id', 'main_manifest_sha256', 'release_id']);
    $markerHash = hash_file('sha256', $markerPath);
    if (!is_string($markerHash) || !hash_equals(plainHash($attestation['deployment_marker_sha256']), $markerHash)
        || $marker['schema_version'] !== 1 || !is_string($marker['provenance_kind'])
        || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $marker['provenance_kind']) !== 1
        || $marker['app_version'] !== $attestation['app_version']
        || $marker['deployment_id'] !== $attestation['deployment_id']
        || $marker['release_inventory_sha256'] !== $attestation['release_inventory_sha256']
        || ($marker['main_manifest_sha256'] ?? '') !== ($attestation['main_manifest_sha256'] ?? '')
        || !is_int($marker['storage_layout_version']) || $marker['storage_layout_version'] < 1
        || !is_int($marker['storage_layout_generation']) || $marker['storage_layout_generation'] < 1) {
        fail('SEALED_DEPLOYMENT_MARKER_INVALID');
    }
    if (array_key_exists('main_manifest_sha256', $marker) && !isHash($marker['main_manifest_sha256'])) {
        fail('SEALED_DEPLOYMENT_MARKER_INVALID');
    }
    if (array_key_exists('job_id', $marker) && !isUuid($marker['job_id'])) {
        fail('SEALED_DEPLOYMENT_MARKER_INVALID');
    }
    if (array_key_exists('release_id', $marker) && (!is_string($marker['release_id'])
        || preg_match('/^[0-9A-Za-z._-]{1,128}$/D', $marker['release_id']) !== 1)) {
        fail('SEALED_DEPLOYMENT_MARKER_INVALID');
    }
    if (file_exists($outputPath) || is_link($outputPath)) {
        fail('SEALED_VALIDATION_MARKER_INVALID');
    }
    if (@file_put_contents($outputPath, '') !== 0 || !@chmod($outputPath, 0444)) {
        fail('SEALED_VALIDATION_MARKER_FAILED');
    }
}

$mode = $argv[1] ?? '';
switch ($mode) {
    case 'create-field':
        if ($argc !== 5 || !in_array($argv[4], ['receipt_id', 'seal_id', 'tar_path'], true)) {
            fail('SEALED_VALIDATOR_USAGE');
        }
        $create = validateCreateResult($argv[2], $argv[3]);
        $field = $argv[4] === 'tar_path' ? '_tar_path' : $argv[4];
        fwrite(STDOUT, $create[$field] . PHP_EOL);
        break;
    case 'export-challenge':
        if ($argc !== 5) {
            fail('SEALED_VALIDATOR_USAGE');
        }
        $create = validateCreateResult($argv[2], $argv[3]);
        writePrivateOutput($argv[4], $create['_challenge_raw']);
        break;
    case 'write-record-request':
        if ($argc !== 7) {
            fail('SEALED_VALIDATOR_USAGE');
        }
        $create = validateCreateResult($argv[2], $argv[3]);
        $request = [
            'receipt_id' => $create['receipt_id'],
            'seal_id' => $create['seal_id'],
            'challenge' => $create['challenge'],
            'image_id' => requireOciId($argv[4]),
            'config_digest' => requireOciId($argv[5]),
        ];
        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        writePrivateOutput($argv[6], $encoded);
        break;
    case 'image-receipt-field':
        if ($argc !== 5 || !in_array($argv[4], ['seal_id', 'image_id', 'config_digest'], true)
            || !isReceiptId($argv[3])) {
            fail('SEALED_VALIDATOR_USAGE');
        }
        $receipt = validateImageReceipt($argv[2], $argv[3]);
        fwrite(STDOUT, $receipt[$argv[4]] . PHP_EOL);
        break;
    case 'oci-id':
        if ($argc !== 3) {
            fail('SEALED_VALIDATOR_USAGE');
        }
        $raw = readFileBounded($argv[2], 256, [0600]);
        if (str_ends_with($raw, "\n")) {
            $raw = substr($raw, 0, -1);
        }
        fwrite(STDOUT, requireOciId($raw) . PHP_EOL);
        break;
    case 'verify-attestation':
        verifyAttestation($argv);
        break;
    default:
        fail('SEALED_VALIDATOR_USAGE');
}
