<?php

declare(strict_types=1);

function targetFail(string $code): never
{
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}

$jobId = getenv('MALLBASE_UPGRADE_JOB_ID');
$sharedGid = getenv('MALLBASE_UPGRADE_SHARED_GID');
if (getenv('MALLBASE_RUNTIME_ROLE') !== 'target-verify'
    || !is_string($jobId)
    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1
    || !is_string($sharedGid)
    || preg_match('/^[1-9][0-9]{0,9}$/D', $sharedGid) !== 1
    || posix_geteuid() !== 0
    || !posix_setgid((int) $sharedGid)
    || !posix_setuid(10000)
    || posix_geteuid() !== 10000
    || posix_getegid() !== (int) $sharedGid) {
    targetFail('CUTOVER_PHP_IDENTITY_DROP_FAILED');
}
$status = @file_get_contents('/proc/self/status');
if (is_string($status)
    && (preg_match('/^CapEff:\s+0+$/mD', $status) !== 1
        || preg_match('/^NoNewPrivs:\s+1$/mD', $status) !== 1)) {
    targetFail('CUTOVER_PHP_CAPABILITY_SET_INVALID');
}

$process = proc_open(
    [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        '-d',
        'opcache.jit_buffer_size=0',
        'think',
        'upgrade:storage-cutover-target-snapshot',
        '--job-id=' . $jobId,
    ],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    '/app',
    null,
    ['bypass_shell' => true],
);
if (!is_resource($process)) {
    targetFail('CUTOVER_PHP_TARGET_SNAPSHOT_FAILED');
}
exit(proc_close($process));
