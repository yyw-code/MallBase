<?php

declare(strict_types=1);

if ($argc !== 2 || $argv[1] === '' || $argv[1][0] !== '/') {
    fwrite(STDERR, "RUNTIME_ENV_EXPORT_USAGE_INVALID\n");
    exit(2);
}

$path = $argv[1];
$stat = @lstat($path);
if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
    || (($stat['mode'] ?? 0) & 0777) !== 0600 || ($stat['nlink'] ?? 0) !== 1
    || ($stat['size'] ?? -1) < 0 || ($stat['size'] ?? 0) > 1048576 || is_link($path)) {
    fwrite(STDERR, "RUNTIME_ENV_EXPORT_FILE_INVALID\n");
    exit(1);
}

$content = @file_get_contents($path);
if (!is_string($content) || strlen($content) !== (int) $stat['size']) {
    fwrite(STDERR, "RUNTIME_ENV_EXPORT_FILE_INVALID\n");
    exit(1);
}
$seenKeys = [];
foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
    $trimmed = ltrim($line);
    if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
        continue;
    }
    if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) !== 1
        || isset($seenKeys[$matches[1]])) {
        fwrite(STDERR, "RUNTIME_ENV_EXPORT_FILE_INVALID\n");
        exit(1);
    }
    $seenKeys[$matches[1]] = true;
}

$values = @parse_ini_file($path, false, INI_SCANNER_RAW);
if (!is_array($values) || count($values) !== count($seenKeys)) {
    fwrite(STDERR, "RUNTIME_ENV_EXPORT_PARSE_FAILED\n");
    exit(1);
}

foreach ($values as $key => $value) {
    if (!is_string($key) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1
        || !is_string($value) || str_contains($value, "\0")
        || str_contains($value, "\r") || str_contains($value, "\n")
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
        fwrite(STDERR, "RUNTIME_ENV_EXPORT_VALUE_INVALID\n");
        exit(1);
    }
    fwrite(STDOUT, 'export ' . $key . '=' . escapeshellarg($value) . "\n");
}
