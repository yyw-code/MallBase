<?php

declare(strict_types=1);

$readEnvironment = static function (string $name): mixed {
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    return function_exists('env') ? env($name, null) : null;
};

$readDecimal = static function (string $name, int $default, int $minimum, int $maximum) use ($readEnvironment): int {
    $raw = $readEnvironment($name);
    if ($raw === null) {
        return $default;
    }
    if (!is_string($raw) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1) {
        return -1;
    }
    $maximumText = (string) $maximum;
    if (strlen($raw) > strlen($maximumText)
        || strlen($raw) === strlen($maximumText) && strcmp($raw, $maximumText) > 0) {
        return -1;
    }
    $value = (int) $raw;

    return $value >= $minimum ? $value : -1;
};

$projectRoot = dirname(__DIR__, 2);
$configuredRootValue = $readEnvironment('MALLBASE_UPGRADE_ROOT');
$configuredRoot = is_string($configuredRootValue) ? $configuredRootValue : '';
$platformOrigin = 'https://platform.gosowong.cn';
$namespaceValue = $readEnvironment('MALLBASE_UPGRADE_NAMESPACE_ID');
$namespace = is_string($namespaceValue)
    && strlen($namespaceValue) <= 64
    && preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $namespaceValue) === 1
        ? $namespaceValue
        : '';

return [
    'upgrade_root' => $configuredRoot !== '' ? $configuredRoot : $projectRoot . DIRECTORY_SEPARATOR . 'upgrade',
    'platform_origin' => $platformOrigin,
    'expected_gid' => $readDecimal('MALLBASE_UPGRADE_SHARED_GID', 10001, 0, 4_294_967_294),
    'agent_uid' => $readDecimal('MALLBASE_AGENT_UID', 10001, 0, 4_294_967_294),
    'php_euid' => function_exists('posix_geteuid') ? posix_geteuid() : -1,
    'upgrade_namespace_id' => $namespace,
    'activation_proof_lifetime' => $readDecimal('MALLBASE_AGENT_ACTIVATION_PROOF_LIFETIME', 900, 1, 4_102_444_800),
    'report_interval' => $readDecimal('MALLBASE_AGENT_REPORT_INTERVAL', 86400, 1, 4_102_444_800),
    'retry_interval' => $readDecimal('MALLBASE_AGENT_RETRY_INTERVAL', 300, 1, 4_102_444_800),
    'reservation_interval' => $readDecimal('MALLBASE_AGENT_RESERVATION_INTERVAL', 60, 1, 4_102_444_800),
    'component_seen_throttle' => $readDecimal('MALLBASE_AGENT_COMPONENT_SEEN_THROTTLE', 3600, 1, 4_102_444_800),
    'heartbeat_timeout_milliseconds' => $readDecimal('MALLBASE_AGENT_HEARTBEAT_TIMEOUT_MS', 5000, 1, 60_000),
    'instance_lock_timeout_milliseconds' => $readDecimal('MALLBASE_AGENT_LOCK_TIMEOUT_MS', 2000, 1, 60_000),
    'max_json_bytes' => 64 * 1024,
];
