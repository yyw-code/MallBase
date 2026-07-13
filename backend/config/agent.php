<?php

declare(strict_types=1);

$readEnvironment = static function (string $name, mixed $default): mixed {
    if (function_exists('env')) {
        return env($name, $default);
    }
    $value = getenv($name);

    return $value === false ? $default : $value;
};

$projectRoot = dirname(__DIR__, 2);
$configuredRoot = trim((string) $readEnvironment('MALLBASE_UPGRADE_ROOT', ''));
$platformOrigin = 'https://platform.gosowong.cn';

return [
    'upgrade_root' => $configuredRoot !== '' ? $configuredRoot : $projectRoot . DIRECTORY_SEPARATOR . 'upgrade',
    'platform_origin' => $platformOrigin,
    'expected_gid' => (int) $readEnvironment('MALLBASE_UPGRADE_SHARED_GID', 10001),
    'agent_uid' => (int) $readEnvironment('MALLBASE_AGENT_UID', 10001),
    'php_euid' => function_exists('posix_geteuid') ? posix_geteuid() : -1,
    'upgrade_namespace_id' => trim((string) $readEnvironment('MALLBASE_UPGRADE_NAMESPACE_ID', '')),
    'activation_proof_lifetime' => max(1, (int) $readEnvironment('MALLBASE_AGENT_ACTIVATION_PROOF_LIFETIME', 900)),
    'report_interval' => max(1, (int) $readEnvironment('MALLBASE_AGENT_REPORT_INTERVAL', 86400)),
    'retry_interval' => max(1, (int) $readEnvironment('MALLBASE_AGENT_RETRY_INTERVAL', 300)),
    'reservation_interval' => max(1, (int) $readEnvironment('MALLBASE_AGENT_RESERVATION_INTERVAL', 60)),
    'component_seen_throttle' => max(1, (int) $readEnvironment('MALLBASE_AGENT_COMPONENT_SEEN_THROTTLE', 3600)),
    'instance_lock_timeout_milliseconds' => max(1, (int) $readEnvironment('MALLBASE_AGENT_LOCK_TIMEOUT_MS', 2000)),
    'max_json_bytes' => 64 * 1024,
];
