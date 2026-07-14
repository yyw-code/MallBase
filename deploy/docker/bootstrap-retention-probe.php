<?php

declare(strict_types=1);

const BOOTSTRAP_RETENTION_PROBE_ROOT = '/app';
const BOOTSTRAP_RETENTION_PROBE_MAX_ENTRIES = 100000;
const BOOTSTRAP_RETENTION_PROBE_MAX_BYTES = 536870912;
const BOOTSTRAP_RETENTION_PROBE_MAX_ENV_BYTES = 1048576;
const BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_ROW_BYTES = 8448;
const BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_BYTES = 844800000;
const BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_FILE_BYTES = 536870912;
const BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_TREE_BYTES = 4294967296;

function bootstrapRetentionProbeFail(string $code): never
{
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}

/** @param array<mixed> $value */
function bootstrapRetentionProbeCanonical(array $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
}

function bootstrapRetentionProbeHash(string $bytes): string
{
    return 'sha256:' . hash('sha256', $bytes);
}

function bootstrapRetentionProbeAssertRelativeRoot(string $value): void
{
    if ($value === '' || $value === '.' || strlen($value) > 4096
        || preg_match('//u', $value) !== 1 || str_contains($value, "\\")
        || str_starts_with($value, '/') || str_ends_with($value, '/')
        || preg_match('/[\x00-\x1f\x7f:]/', $value) === 1) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED');
    }
    foreach (explode('/', $value) as $component) {
        if ($component === '' || $component === '.' || $component === '..'
            || in_array($component, ['.git', '.env', '.mallbase-sealed-context.json'], true)) {
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED');
        }
    }
    foreach (['uploads', 'storage', 'static/demo'] as $fixedRoot) {
        if ($value === $fixedRoot || str_starts_with($value, $fixedRoot . '/')
            || str_starts_with($fixedRoot, $value . '/')) {
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED');
        }
    }
}

/** @param array<string,array{kind:string,size:int,sha256?:string}> $entries */
function bootstrapRetentionProbeEntriesHash(array $entries): string
{
    return bootstrapRetentionProbeHash(bootstrapRetentionProbeManifestBytes($entries));
}

/** @param array<string,array{kind:string,size:int,sha256?:string}> $entries */
function bootstrapRetentionProbeManifestBytes(array $entries): string
{
    $rows = [];
    foreach ($entries as $path => $entry) {
        if ($entry['kind'] === 'directory') {
            $rows[] = bootstrapRetentionProbeCanonical(['directory', $path]);
        } else {
            $rows[] = bootstrapRetentionProbeCanonical([
                'file', $path, $entry['size'], $entry['sha256'],
            ]);
        }
    }
    sort($rows, SORT_STRING);
    $bytes = implode('', $rows);
    if ($bytes === '' || strlen($bytes) > BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_BYTES) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_TOO_LARGE');
    }

    return $bytes;
}

/**
 * @param array{manifest:array<string,array{kind:string,size:int,sha256?:string}>} $source
 * @param array{manifest:array<string,array{kind:string,size:int,sha256?:string}>} $target
 */
function bootstrapRetentionProbeMergedRoot(array $source, array $target): string
{
    $entries = $target['manifest'];
    foreach ($source['manifest'] as $path => $entry) {
        if ($path === '.') {
            continue;
        }
        if (isset($entries[$path]) && $entries[$path] !== $entry) {
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_UPLOAD_TARGET_CONFLICT');
        }
        $entries[$path] = $entry;
    }
    ksort($entries, SORT_STRING);

    return bootstrapRetentionProbeEntriesHash($entries);
}

/**
 * @return array{
 *   hash:string,entries:int,bytes:int,
 *   manifest:array<string,array{kind:string,size:int,sha256?:string}>
 * }
 */
function bootstrapRetentionProbeTree(string $root): array
{
    $rootStat = @lstat($root);
    $rootReal = @realpath($root);
    if (!is_array($rootStat) || ($rootStat['mode'] & 0170000) !== 0040000
        || is_link($root) || !is_string($rootReal) || $rootReal !== rtrim($root, '/')) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
    }

    $rootDevice = (int) $rootStat['dev'];
    $rows = [bootstrapRetentionProbeCanonical(['directory', '.'])];
    $manifest = ['.' => ['kind' => 'directory', 'size' => 0]];
    $entryCount = 1;
    $byteCount = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen(rtrim($root, '/')) + 1);
            if ($relative === '' || strlen($relative) > 4096 || preg_match('//u', $relative) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $relative) === 1
                || basename($relative) === '.mallbase-layout-marker.json') {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            $stat = @lstat($path);
            if (!is_array($stat) || (int) $stat['dev'] !== $rootDevice || is_link($path)) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            $type = $stat['mode'] & 0170000;
            $canonicalPath = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($type === 0040000) {
                $rows[] = bootstrapRetentionProbeCanonical(['directory', $canonicalPath]);
                $manifest[$canonicalPath] = ['kind' => 'directory', 'size' => 0];
            } elseif ($type === 0100000 && (int) $stat['nlink'] === 1) {
                $size = (int) $stat['size'];
                if ($size < 0 || $size > BOOTSTRAP_RETENTION_PROBE_MAX_BYTES - $byteCount) {
                    bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_TOO_LARGE');
                }
                $digest = @hash_file('sha256', $path);
                $post = @lstat($path);
                if (!is_string($digest) || !is_array($post)
                    || (int) $post['dev'] !== (int) $stat['dev']
                    || (int) $post['ino'] !== (int) $stat['ino']
                    || (int) $post['size'] !== $size || (int) $post['nlink'] !== 1) {
                    bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_CHANGED');
                }
                $rows[] = bootstrapRetentionProbeCanonical([
                    'file', $canonicalPath, $size, 'sha256:' . $digest,
                ]);
                $manifest[$canonicalPath] = [
                    'kind' => 'file', 'size' => $size, 'sha256' => 'sha256:' . $digest,
                ];
                $byteCount += $size;
            } else {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            if (++$entryCount > BOOTSTRAP_RETENTION_PROBE_MAX_ENTRIES) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_TOO_LARGE');
            }
        }
    } catch (UnexpectedValueException) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_UNREADABLE');
    }
    sort($rows, SORT_STRING);
    ksort($manifest, SORT_STRING);

    return [
        'hash' => bootstrapRetentionProbeHash(implode('', $rows)),
        'entries' => $entryCount,
        'bytes' => $byteCount,
        'manifest' => $manifest,
    ];
}

/** @return array{present:bool,path:string,content_root:string} */
function bootstrapRetentionProbeDirectory(string $path): array
{
    if (!file_exists($path) && !is_link($path)) {
        return [
            'present' => false,
            'path' => $path,
            'content_root' => bootstrapRetentionProbeHash(
                bootstrapRetentionProbeCanonical(['directory', '.']),
            ),
        ];
    }
    $tree = bootstrapRetentionProbeTree($path);

    return ['present' => true, 'path' => $path, 'content_root' => $tree['hash']];
}

/** @return array{path:string,sha256:string} */
function bootstrapRetentionProbeEnvironment(): array
{
    $candidates = [
        BOOTSTRAP_RETENTION_PROBE_ROOT . '/.mallbase-env/backend.env',
        BOOTSTRAP_RETENTION_PROBE_ROOT . '/.env',
    ];
    $present = [];
    foreach ($candidates as $path) {
        if (!file_exists($path) && !is_link($path)) {
            continue;
        }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || (int) $stat['nlink'] !== 1 || (int) $stat['size'] <= 0
            || (int) $stat['size'] > BOOTSTRAP_RETENTION_PROBE_MAX_ENV_BYTES
            || !is_readable($path)) {
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_ENV_INVALID');
        }
        $present[] = $path;
    }
    if ($present === []) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_ENV_MISSING');
    }
    if (count($present) === 2) {
        $first = @hash_file('sha256', $present[0]);
        $second = @hash_file('sha256', $present[1]);
        if (!is_string($first) || !is_string($second) || !hash_equals($first, $second)) {
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_ENV_AMBIGUOUS');
        }
    }

    $sha256 = @hash_file('sha256', $present[0]);
    if (!is_string($sha256)) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_ENV_INVALID');
    }

    return ['path' => $present[0], 'sha256' => 'sha256:' . $sha256];
}

function bootstrapRetentionProbeSetting(): string
{
    $autoload = BOOTSTRAP_RETENTION_PROBE_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload) || is_link($autoload)) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_APPLICATION_UNAVAILABLE');
    }
    try {
        chdir(BOOTSTRAP_RETENTION_PROBE_ROOT);
        require $autoload;
        $app = new think\App();
        $app->initialize();
        $value = (new app\model\setting\Setting())
            ->where('code', 'local_root_path')
            ->value('value');
    } catch (Throwable) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SETTING_UNAVAILABLE');
    }
    if (!is_string($value) || $value === '') {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SETTING_UNAVAILABLE');
    }

    return $value;
}

function bootstrapRetentionProbeUploadsManifest(string $root): void
{
    $rootStat = @lstat($root);
    $rootReal = @realpath($root);
    if (!is_array($rootStat) || ($rootStat['mode'] & 0170000) !== 0040000
        || is_link($root) || !is_string($rootReal) || $rootReal !== rtrim($root, '/')) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
    }
    $temporary = @tempnam(sys_get_temp_dir(), 'mallbase-uploads-manifest.');
    $sorted = is_string($temporary)
        ? @tempnam(sys_get_temp_dir(), 'mallbase-uploads-manifest-sorted.') : false;
    if (!is_string($temporary) || !is_string($sorted)
        || !chmod($temporary, 0600) || !chmod($sorted, 0600)) {
        if (is_string($temporary)) @unlink($temporary);
        if (is_string($sorted)) @unlink($sorted);
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_TEMP_UNAVAILABLE');
    }
    register_shutdown_function(static function () use ($temporary, $sorted): void {
        @unlink($temporary);
        @unlink($sorted);
    });
    $output = @fopen($temporary, 'wb');
    if (!is_resource($output)) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_TEMP_UNAVAILABLE');
    }
    $entryCount = 0;
    $manifestBytes = 0;
    $fileBytes = 0;
    $rootDevice = (int) $rootStat['dev'];
    $writeRow = static function (string $row) use (
        $output,
        &$entryCount,
        &$manifestBytes,
    ): void {
        $length = strlen($row);
        if ($length === 0 || $length > BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_ROW_BYTES
            || ++$entryCount > BOOTSTRAP_RETENTION_PROBE_MAX_ENTRIES
            || $manifestBytes > BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_BYTES - $length
            || fwrite($output, $row) !== $length) {
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_TOO_LARGE');
        }
        $manifestBytes += $length;
    };
    $writeRow(bootstrapRetentionProbeCanonical(['directory', '.']));
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen(rtrim($root, '/')) + 1);
            if ($relative === '' || strlen($relative) > 4096 || preg_match('//u', $relative) !== 1
                || str_contains($relative, "\\") || str_starts_with($relative, '/')
                || str_ends_with($relative, '/') || preg_match('/[\x00-\x1f\x7f:]/', $relative) === 1) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            foreach (explode('/', $relative) as $component) {
                if ($component === '' || $component === '.' || $component === '..'
                    || in_array($component, ['.git', '.env', '.mallbase-sealed-context.json'], true)) {
                    bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
                }
            }
            if (basename($relative) === '.mallbase-layout-marker.json') {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            $stat = @lstat($path);
            if (!is_array($stat) || (int) $stat['dev'] !== $rootDevice || is_link($path)) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            $canonicalPath = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $type = $stat['mode'] & 0170000;
            if ($type === 0040000) {
                $writeRow(bootstrapRetentionProbeCanonical(['directory', $canonicalPath]));
                continue;
            }
            if ($type !== 0100000 || (int) $stat['nlink'] !== 1) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
            }
            $size = (int) $stat['size'];
            if ($size < 0 || $size > BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_FILE_BYTES
                || $size > BOOTSTRAP_RETENTION_PROBE_MAX_MANIFEST_TREE_BYTES - $fileBytes) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_TOO_LARGE');
            }
            $digest = @hash_file('sha256', $path);
            $post = @lstat($path);
            if (!is_string($digest) || !is_array($post)
                || (int) $post['dev'] !== (int) $stat['dev']
                || (int) $post['ino'] !== (int) $stat['ino']
                || (int) $post['size'] !== $size || (int) $post['nlink'] !== 1) {
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_CHANGED');
            }
            $writeRow(bootstrapRetentionProbeCanonical([
                'file', $canonicalPath, $size, 'sha256:' . $digest,
            ]));
            $fileBytes += $size;
        }
    } catch (UnexpectedValueException) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_UNREADABLE');
    }
    $afterRoot = @lstat($root);
    if (!is_array($afterRoot) || (int) $afterRoot['dev'] !== (int) $rootStat['dev']
        || (int) $afterRoot['ino'] !== (int) $rootStat['ino'] || is_link($root)
        || !fflush($output) || !fsync($output)) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_CHANGED');
    }
    fclose($output);

    $sort = is_executable('/usr/bin/sort') ? '/usr/bin/sort' : '/bin/sort';
    if (!is_executable($sort)) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_SORT_UNAVAILABLE');
    }
    $process = @proc_open(
        [$sort, '-o', $sorted, $temporary],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['LC_ALL' => 'C'],
    );
    if (!is_resource($process) || proc_close($process) !== 0) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_SORT_FAILED');
    }
    $input = @fopen($sorted, 'rb');
    if (!is_resource($input)) {
        bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_SORT_FAILED');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1048576);
        if (!is_string($chunk) || ($chunk === '' && !feof($input))) {
            fclose($input);
            bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_READ_FAILED');
        }
        $offset = 0;
        while ($offset < strlen($chunk)) {
            $written = fwrite(STDOUT, substr($chunk, $offset));
            if (!is_int($written) || $written <= 0) {
                fclose($input);
                bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_MANIFEST_WRITE_FAILED');
            }
            $offset += $written;
        }
    }
    fclose($input);
}

if (getenv('MALLBASE_BOOTSTRAP_RETENTION_PROBE_MODE') === 'uploads-manifest') {
    bootstrapRetentionProbeUploadsManifest(BOOTSTRAP_RETENTION_PROBE_ROOT . '/public/uploads');
    exit(0);
}

$publicRoot = BOOTSTRAP_RETENTION_PROBE_ROOT . '/public';
$publicStat = @lstat($publicRoot);
$publicReal = @realpath($publicRoot);
if (!is_array($publicStat) || ($publicStat['mode'] & 0170000) !== 0040000
    || is_link($publicRoot) || $publicReal !== $publicRoot) {
    bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_PUBLIC_ROOT_INVALID');
}

$configuredRoot = bootstrapRetentionProbeSetting();
$classification = 'canonical';
$buildContextRelativeRoot = null;
$localSource = null;
$localSourceContentRoot = null;
$localSourceTree = null;
if ($configuredRoot !== 'uploads') {
    bootstrapRetentionProbeAssertRelativeRoot($configuredRoot);
    $classification = 'relative';
    $buildContextRelativeRoot = $configuredRoot;
    $localSource = $publicRoot . '/' . $configuredRoot;
    $localSourceTree = bootstrapRetentionProbeTree($localSource);
    $localSourceContentRoot = $localSourceTree['hash'];
}

$sourceContentRoots = [];
$sourceTrees = [];
$sourceArtifacts = [];
foreach ([
    'install' => BOOTSTRAP_RETENTION_PROBE_ROOT . '/runtime/install',
    'local_storage' => BOOTSTRAP_RETENTION_PROBE_ROOT . '/runtime/storage',
    'runtime_backup' => BOOTSTRAP_RETENTION_PROBE_ROOT . '/runtime/backup',
    'uploads' => $publicRoot . '/uploads',
] as $artifact => $path) {
    $sourceArtifacts[$artifact] = bootstrapRetentionProbeDirectory($path);
    $sourceContentRoots[$artifact] = $sourceArtifacts[$artifact]['content_root'];
    if ($sourceArtifacts[$artifact]['present']) {
        $sourceTrees[$artifact] = bootstrapRetentionProbeTree($path);
    }
}
if (!$sourceArtifacts['uploads']['present']) {
    bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_SOURCE_INVALID');
}
$expectedUploadsContentRoot = $classification === 'canonical'
    ? $sourceContentRoots['uploads']
    : bootstrapRetentionProbeMergedRoot($localSourceTree, $sourceTrees['uploads']);

$environment = bootstrapRetentionProbeEnvironment();
$result = [
    'schema_version' => 1,
    'purpose' => 'storage_bootstrap_retention_probe',
    'configured_local_root' => $configuredRoot,
    'local_root_classification' => $classification,
    'build_context_relative_root' => $buildContextRelativeRoot,
    'local_source_path' => $localSource,
    'local_source_content_root' => $localSourceContentRoot,
    'environment_source_path' => $environment['path'],
    'environment_sha256' => $environment['sha256'],
    'artifacts' => [
        'cert' => bootstrapRetentionProbeDirectory(BOOTSTRAP_RETENTION_PROBE_ROOT . '/storage/cert'),
        'demo' => bootstrapRetentionProbeDirectory($publicRoot . '/static/demo'),
        'public_storage' => bootstrapRetentionProbeDirectory($publicRoot . '/storage'),
    ],
    'source_artifacts' => $sourceArtifacts,
    'source_content_roots' => $sourceContentRoots,
    'expected_uploads_content_root' => $expectedUploadsContentRoot,
    'old_app_uid' => function_exists('posix_geteuid') ? posix_geteuid() : -1,
    'old_app_gid' => function_exists('posix_getegid') ? posix_getegid() : -1,
];
if ($result['old_app_uid'] < 0 || $result['old_app_gid'] < 0) {
    bootstrapRetentionProbeFail('BOOTSTRAP_RETENTION_APP_IDENTITY_INVALID');
}

fwrite(STDOUT, bootstrapRetentionProbeCanonical($result));
