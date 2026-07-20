<?php

declare(strict_types=1);

$isInstalled = is_file(runtime_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock');

return [
    'simple_gate_enabled' => $isInstalled,
    'dump_executable' => (string) env('MALLBASE_UPGRADE_DUMP_EXECUTABLE', '/usr/bin/mariadb-dump'),
    'restore_executable' => (string) env('MALLBASE_UPGRADE_RESTORE_EXECUTABLE', '/usr/bin/mariadb'),
];
