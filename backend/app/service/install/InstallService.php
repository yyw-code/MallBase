<?php

declare(strict_types=1);

namespace app\service\install;

use app\service\RegionImportService;
use app\service\admin\setting\SettingService;
use mall_base\base\BaseModel;
use mall_base\base\BaseService;
use PDO;
use PDOException;
use Redis;
use RedisException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * 安装服务
 * @extends BaseService<BaseModel>
 */
class InstallService extends BaseService
{
    /**
     * @var array<string, string>
     */
    private array $stepTitles = [
        'db_test'                  => '校验并准备数据库',
        'environment'              => '检查安装环境',
        'redis_test'               => '校验 Redis 连接',
        'write_env'                => '写入配置文件',
        'create_db'                => '创建数据库',
        'import_sql'               => '导入表结构',
        'create_admin'             => '创建管理员',
        'import_demo'              => '导入演示数据',
        'copy_demo_static'         => '拷贝演示静态资源',
        'sync_permissions'         => '同步路由权限',
        'sync_setting_permissions' => '同步系统设置菜单',
        'seed_role_permissions'    => '初始化默认角色权限',
        'import_regions'           => '导入地区数据',
        'seed_site_url'            => '写入站点域名',
        'verify_default_assets'    => '检查默认静态资源',
        'write_lock'               => '写入安装锁',
    ];

    /**
     * @var array<int, string> 默认静态资源文件清单（相对 backend/public 根）
     */
    private array $defaultAssets = [
        'static/admin/logo.png',
        'static/admin/favicon.png',
        'static/admin/slogan.png',
        'static/admin/avatar-default.png',
        'static/client/logo.png',
        'static/client/launch.png',
        'static/client/share-cover.png',
        'static/decorate/decorate-banner-market.png',
        'static/decorate/decorate-banner-member.png',
        'static/decorate/decorate-banner-home.png',
        'static/decorate/decorate-nav-digital.png',
        'static/decorate/decorate-nav-beauty.png',
        'static/decorate/decorate-nav-fashion.png',
        'static/decorate/decorate-nav-home.png',
        'static/decorate/decorate-nav-food.png',
        'static/decorate/decorate-nav-sport.png',
        'static/decorate/decorate-cube-new.png',
        'static/decorate/decorate-cube-picks.png',
        'static/decorate/decorate-cube-member.png',
        'static/decorate/decorate-cube-sale.png',
        'static/decorate/decorate-entry-category.png',
        'static/decorate/profile-order-pay.svg',
        'static/decorate/profile-order-ship.svg',
        'static/decorate/profile-order-receive.svg',
        'static/decorate/profile-order-refund.svg',
        'static/decorate/profile-service-address.svg',
        'static/decorate/profile-service-settings.svg',
        'static/decorate/profile-service-support.svg',
        'static/decorate/floating/service.png',
        'static/decorate/floating/cart.png',
        'static/decorate/floating/home.png',
        'static/decorate/floating/collapse-left.png',
        'static/decorate/floating/collapse-right.png',
    ];

    /**
     * 默认管理员头像路径（超管创建时使用）
     */
    private const DEFAULT_AVATAR_PATH = '/static/admin/logo.png';

    private const PLATFORM_BASE_URL = 'https://platform.gosowong.cn';
    private const PLATFORM_APP_CODE = 'mallbase';
    private const PLATFORM_CONNECT_TIMEOUT_MS = 2000;
    private const PLATFORM_TIMEOUT_MS = 5000;
    private const PERMISSION_SYNC_TIMEOUT_MS = 120_000;
    private const ENV_SECRET_PLACEHOLDER = 'please-change-or-leave-for-random';
    /** @var array<int, string> */
    private const INSTALL_CURL_FUNCTIONS = [
        'curl_init',
        'curl_setopt_array',
        'curl_exec',
        'curl_error',
        'curl_getinfo',
        'curl_close',
    ];
    /** @var array<int, string> */
    private const INSTALL_PROCESS_FUNCTIONS = [
        'proc_open',
        'proc_get_status',
        'proc_terminate',
        'proc_close',
    ];

    protected string $modelClass = BaseModel::class;

    public function isInstalled(): bool
    {
        return app()->make(InstallLockService::class)->isInstalled();
    }

    public function getLockInfo(): ?array
    {
        return app()->make(InstallLockService::class)->getLockInfo();
    }

    public function getInstallStatus(array $entries = []): array
    {
        $lockInfo = $this->getLockInfo() ?? [];

        return [
            'installed' => $this->isInstalled(),
            'installed_at' => $lockInfo['installed_at'] ?? null,
            'release' => $this->getReleaseInfo(),
            'entries' => [
                'admin_url' => $entries['admin_url'] ?? null,
                'client_url' => $entries['client_url'] ?? null,
            ],
            'meta' => $this->getInstallPageMeta(),
        ];
    }

    public function getReleaseInfo(): ?array
    {
        $path = $this->releaseFilePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $version = trim((string) ($data['version'] ?? ''));
        if ($version === '') {
            return null;
        }

        $notes = [];
        foreach (($data['notes'] ?? []) as $note) {
            if (is_string($note) && trim($note) !== '') {
                $notes[] = trim($note);
            }
        }

        return [
            'version' => $version,
            'released_at' => trim((string) ($data['released_at'] ?? '')) ?: null,
            'notes' => $notes,
        ];
    }

    public function getInstallAgreement(): array
    {
        $response = $this->fetchPlatformInstallAgreement();
        if (($response['success'] ?? false) !== true) {
            return $this->unavailableInstallAgreement((string) ($response['message'] ?? 'request_failed'));
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $enabled = $this->platformBoolean($data['enabled'] ?? true, true);
        $rawTitle = $data['title'] ?? '';
        $title = is_scalar($rawTitle) ? trim((string) $rawTitle) : '';
        $title = $title !== '' ? $title : 'MallBase 安装协议';
        $content = is_string($data['content'] ?? null) ? trim($data['content']) : '';

        if (!$enabled) {
            return [
                'app_code' => self::PLATFORM_APP_CODE,
                'enabled'  => false,
                'available' => true,
                'title'    => $title,
                'content'  => '',
                'source'   => 'platform',
            ];
        }

        if ($content === '') {
            return $this->unavailableInstallAgreement('empty_content');
        }

        return [
            'app_code' => self::PLATFORM_APP_CODE,
            'enabled'  => true,
            'available' => true,
            'title'    => $title,
            'content'  => $content,
            'source'   => 'platform',
        ];
    }

    public function getInstallPageMeta(): array
    {
        $envValues = $this->readEnvFile();
        $swooleHost = trim((string) ($envValues['SWOOLE_HTTP_HOST'] ?? '0.0.0.0'));
        $swoolePort = (int) ($envValues['SWOOLE_HTTP_PORT'] ?? 8080);
        $cronEnable = $this->envFlagText($envValues['CRON_ENABLE'] ?? null);
        $swooleQueueEnable = $this->envFlagText($envValues['SWOOLE_QUEUE_ENABLE'] ?? null);

        return [
            'runtime'      => [
                'app_debug' => $this->envFlagText($envValues['APP_DEBUG'] ?? null),
                'cron_enable' => $cronEnable,
                'swoole_queue_enable' => $swooleQueueEnable,
                'needs_swoole_restart' => $cronEnable === 'true' || $swooleQueueEnable === 'true',
                'swoole_host' => $swooleHost,
                'swoole_port' => $swoolePort,
                'db_connection' => 'mysql',
                'db_host'     => trim((string) ($envValues['DB_HOST'] ?? '')),
                'db_port'     => (int) ($envValues['DB_PORT'] ?? 3306),
                'db_name'     => trim((string) ($envValues['DB_NAME'] ?? '')),
                'db_user'     => trim((string) ($envValues['DB_USER'] ?? '')),
                'redis_driver' => 'redis',
                'redis_host'  => trim((string) ($envValues['REDIS_HOST'] ?? '')),
                'redis_port'  => (int) ($envValues['REDIS_PORT'] ?? 6379),
                'redis_db'    => (int) ($envValues['REDIS_CACHE_DB'] ?? 0),
            ],
            'restart_commands' => [
                'docker_dev' => 'docker compose -f docker-compose.dev.yml restart backend',
                'docker_prod' => 'docker compose restart',
                'manual' => "kill \$(lsof -ti :{$swoolePort}) 2>/dev/null || true\ncd backend\nphp think swoole",
            ],
        ];
    }

    private function envFlagText(mixed $value): string
    {
        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'on', 'yes'], true) ? 'true' : 'false';
    }

    private function unavailableInstallAgreement(string $reason): array
    {
        return [
            'app_code' => self::PLATFORM_APP_CODE,
            'enabled'  => true,
            'available' => false,
            'title'    => 'MallBase 安装协议',
            'content'  => '',
            'source'   => 'platform',
            'error'    => $reason !== '' ? $reason : 'request_failed',
        ];
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, message?: string}
     */
    private function fetchPlatformInstallAgreement(): array
    {
        $missingCurlFunctions = $this->missingEnvironmentFunctions(self::INSTALL_CURL_FUNCTIONS);
        if ($missingCurlFunctions !== []) {
            return [
                'success' => false,
                'message' => 'curl_missing:' . implode(',', $missingCurlFunctions),
            ];
        }

        $url = rtrim(self::PLATFORM_BASE_URL, '/')
            . '/api/v1/install/agreement?'
            . http_build_query(['app_code' => self::PLATFORM_APP_CODE]);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'message' => 'curl_init_failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::PLATFORM_CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::PLATFORM_TIMEOUT_MS,
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            return ['success' => false, 'message' => $curlError !== '' ? $curlError : 'request_failed'];
        }

        if ($status < 200 || $status >= 300) {
            return ['success' => false, 'message' => 'http_' . $status];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'invalid_json'];
        }

        if (!is_array($decoded['data'] ?? null)) {
            return ['success' => false, 'message' => 'invalid_payload'];
        }

        return ['success' => true, 'data' => $decoded['data']];
    }

    private function platformBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return $default;
        }

        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return $default;
        }

        if (in_array($text, ['1', 'true', 'on', 'yes', 'enabled'], true)) {
            return true;
        }

        if (in_array($text, ['0', 'false', 'off', 'no', 'disabled'], true)) {
            return false;
        }

        return $default;
    }

    public function checkEnvironment(): array
    {
        $items = [];

        $items[] = [
            'name'     => 'PHP 版本',
            'required' => '>= 8.2',
            'current'  => PHP_VERSION,
            'pass'     => version_compare(PHP_VERSION, '8.2.0', '>='),
        ];

        $items[] = [
            'name'     => 'Swoole 扩展',
            'required' => '已安装',
            'current'  => extension_loaded('swoole') ? phpversion('swoole') : '未安装',
            'pass'     => extension_loaded('swoole'),
        ];

        $items[] = [
            'name'     => 'PDO MySQL 扩展',
            'required' => '已安装',
            'current'  => extension_loaded('pdo_mysql') ? '已安装' : '未安装',
            'pass'     => extension_loaded('pdo_mysql'),
        ];

        $items[] = [
            'name'     => 'Redis 扩展',
            'required' => '已安装',
            'current'  => extension_loaded('redis') ? phpversion('redis') : '未安装',
            'pass'     => extension_loaded('redis'),
        ];

        $items[] = [
            'name'     => 'GD 扩展',
            'required' => '已安装',
            'current'  => extension_loaded('gd') ? '已安装' : '未安装',
            'pass'     => extension_loaded('gd'),
        ];

        $items[] = [
            'name'     => 'mbstring 扩展',
            'required' => '已安装',
            'current'  => extension_loaded('mbstring') ? '已安装' : '未安装',
            'pass'     => extension_loaded('mbstring'),
        ];

        $missingCurlFunctions = $this->missingEnvironmentFunctions(self::INSTALL_CURL_FUNCTIONS);
        $items[] = [
            'name'     => 'cURL 能力',
            'required' => '可用',
            'current'  => $missingCurlFunctions === []
                ? '可用'
                : '缺失：' . implode('、', $missingCurlFunctions),
            'pass'     => $missingCurlFunctions === [],
        ];

        $missingProcessFunctions = $this->missingEnvironmentFunctions(self::INSTALL_PROCESS_FUNCTIONS);
        $items[] = [
            'name'     => 'PHP 子进程函数组',
            'required' => '可用',
            'current'  => $missingProcessFunctions === []
                ? '可用'
                : '缺失：' . implode('、', $missingProcessFunctions),
            'pass'     => $missingProcessFunctions === [],
        ];

        $phpCli = $missingProcessFunctions === [] ? $this->resolveExecutablePhpCli() : null;
        $items[] = [
            'name'     => 'PHP CLI 可执行',
            'required' => '可用',
            'current'  => $phpCli !== null ? '可用' : '不可用',
            'pass'     => $phpCli !== null,
        ];

        $paths = $this->installationEnvironmentPaths();

        $openBasedir = trim($this->configuredOpenBasedir());
        $openBasedirPass = $openBasedir === '' || $this->openBasedirCoversPaths($openBasedir, [
            dirname($paths['public']),
            $paths['backend_env_directory'],
        ]);
        $items[] = [
            'name'     => 'open_basedir 路径覆盖',
            'required' => '覆盖后端目录及环境配置目录',
            'current'  => $openBasedir === '' ? '未限制' : ($openBasedirPass ? '已覆盖' : '未覆盖'),
            'pass'     => $openBasedirPass,
        ];

        $runtimeWritable = $this->installLockDirectoryReady($paths['runtime']);
        $items[] = [
            'name'     => 'runtime 目录可写',
            'required' => '可写且可收紧权限',
            'current'  => $runtimeWritable ? '可写且可收紧权限' : '不可写或无法收紧权限',
            'pass'     => $runtimeWritable,
        ];

        $installDirectory = rtrim($paths['runtime'], '/\\') . DIRECTORY_SEPARATOR . 'install';
        $installDirectoryWritable = $this->prepareInstallLockDirectory($installDirectory, $runtimeWritable);
        $items[] = [
            'name'     => 'runtime/install 目录可写',
            'required' => '可写且可收紧权限',
            'current'  => $installDirectoryWritable ? '可写且可收紧权限' : '不可写或无法收紧权限',
            'pass'     => $installDirectoryWritable,
        ];

        $envDirectoryWritable = !is_link($paths['backend_env_directory'])
            && is_dir($paths['backend_env_directory'])
            && is_writable($paths['backend_env_directory']);
        $items[] = [
            'name'     => 'backend 环境配置目录可写',
            'required' => '可写',
            'current'  => $envDirectoryWritable ? '可写' : '不可写',
            'pass'     => $envDirectoryWritable,
        ];

        $publicReadable = is_dir($paths['public']) && is_readable($paths['public']);
        $items[] = [
            'name'     => 'public 目录可读',
            'required' => '可读',
            'current'  => $publicReadable ? '可读' : '不可读',
            'pass'     => $publicReadable,
        ];

        $uploadsWritable = is_dir($paths['uploads']) && is_writable($paths['uploads']);
        $items[] = [
            'name'     => 'public/uploads 目录可写',
            'required' => '可写',
            'current'  => $uploadsWritable ? '可写' : '不可写',
            'pass'     => $uploadsWritable,
        ];

        $demoWritable = is_dir($paths['demo']) && is_writable($paths['demo']);
        $items[] = [
            'name'     => 'public/static/demo 目录可写',
            'required' => '可写',
            'current'  => $demoWritable ? '可写' : '不可写',
            'pass'     => $demoWritable,
        ];

        $allPass = true;
        foreach ($items as $item) {
            if (!$item['pass']) {
                $allPass = false;
                break;
            }
        }

        return ['items' => $items, 'pass' => $allPass];
    }

    /**
     * @return array{runtime:string,backend_env_directory:string,public:string,uploads:string,demo:string}
     */
    protected function installationEnvironmentPaths(): array
    {
        $public = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'public';

        return [
            'runtime' => app()->getRuntimePath(),
            'backend_env_directory' => dirname($this->resolveBackendEnvPath()),
            'public' => $public,
            'uploads' => $public . DIRECTORY_SEPARATOR . 'uploads',
            'demo' => $public . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'demo',
        ];
    }

    protected function environmentFunctionAvailable(string $function): bool
    {
        return function_exists($function);
    }

    /** @return array<int, string> */
    protected function phpCliCandidates(): array
    {
        $candidates = [];
        $binaryName = basename(PHP_BINARY);
        if (preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?(?:\.exe)?$/iD', $binaryName) === 1) {
            $candidates[] = PHP_BINARY;
        }
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR
            . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');

        return array_values(array_unique($candidates));
    }

    protected function probePhpCliExecution(string $candidate): bool
    {
        $pipes = [];
        $process = null;

        try {
            $process = @proc_open(
                [
                    $candidate,
                    '-r',
                    'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80200 ? 0 : 1);',
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                return false;
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $pipes = [];

            return proc_close($process) === 0;
        } catch (\Throwable) {
            return false;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                @proc_terminate($process);
                @proc_close($process);
            }
        }
    }

    protected function configuredOpenBasedir(): string
    {
        $value = ini_get('open_basedir');

        return is_string($value) ? $value : '';
    }

    protected function probeDirectoryChmodCapability(string $path): bool
    {
        $requiredMode = 0755;
        if (!@chmod($path, $requiredMode)) {
            return false;
        }

        clearstatcache(true, $path);
        $currentPermissions = @fileperms($path);

        return is_int($currentPermissions) && ($currentPermissions & 0777) === $requiredMode;
    }

    /**
     * @param array<int, string> $functions
     * @return array<int, string>
     */
    private function missingEnvironmentFunctions(array $functions): array
    {
        return array_values(array_filter(
            $functions,
            fn(string $function): bool => !$this->environmentFunctionAvailable($function),
        ));
    }

    private function resolveExecutablePhpCli(): ?string
    {
        foreach ($this->phpCliCandidates() as $candidate) {
            if (!$this->trustedPhpCliCandidate($candidate)) {
                continue;
            }
            if ($this->probePhpCliExecution($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function trustedPhpCliCandidate(string $candidate): bool
    {
        if ($candidate === '' || str_contains($candidate, "\0")) {
            return false;
        }
        if (!str_starts_with($candidate, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/D', $candidate) !== 1
        ) {
            return false;
        }

        return preg_match(
            '/^php(?:[0-9]+(?:\.[0-9]+)*)?(?:\.exe)?$/iD',
            basename($candidate),
        ) === 1;
    }

    /** @param array<int, string> $paths */
    private function openBasedirCoversPaths(string $configuration, array $paths): bool
    {
        $allowedRoots = [];
        foreach (explode(PATH_SEPARATOR, $configuration) as $configuredPath) {
            $configuredPath = trim($configuredPath);
            if ($configuredPath === '') {
                continue;
            }
            if ($configuredPath === '.') {
                $configuredPath = getcwd() ?: '';
            }
            $resolved = $configuredPath !== '' ? @realpath($configuredPath) : false;
            if (is_string($resolved)) {
                $allowedRoots[] = $this->normalizeCanonicalPath($resolved);
            }
        }
        if ($allowedRoots === []) {
            return false;
        }

        foreach ($paths as $path) {
            $resolvedPath = @realpath($path);
            if (!is_string($resolvedPath)) {
                return false;
            }
            $target = $this->normalizeCanonicalPath($resolvedPath);
            $covered = false;
            foreach ($allowedRoots as $allowedRoot) {
                if ($this->canonicalPathCoveredBy($target, $allowedRoot)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }

    private function normalizeCanonicalPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function canonicalPathCoveredBy(string $target, string $allowedRoot): bool
    {
        if ($target === $allowedRoot) {
            return true;
        }
        if ($allowedRoot === '/') {
            return str_starts_with($target, '/');
        }

        return str_starts_with($target, $allowedRoot . '/');
    }

    private function installLockDirectoryReady(string $path): bool
    {
        return !is_link($path)
            && is_dir($path)
            && is_writable($path)
            && $this->probeDirectoryChmodCapability($path);
    }

    private function prepareInstallLockDirectory(string $path, bool $runtimeReady): bool
    {
        if (is_link($path)) {
            return false;
        }
        if (!file_exists($path)) {
            if (!$runtimeReady || (!@mkdir($path, 0755) && !is_dir($path))) {
                return false;
            }
        }

        return $this->installLockDirectoryReady($path);
    }

    public function testDatabase(array $config): array
    {
        try {
            $dbName = trim((string) ($config['name'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
                return [
                    'success' => false,
                    'message' => '数据库名只能包含字母、数字和下划线',
                    'detail'  => null,
                ];
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $config['host'],
                $config['port'] ?? 3306
            );

            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);

            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($dbName));
            $dbExists = $stmt->fetchColumn() !== false;
            $dbCreated = false;

            if (!$dbExists) {
                try {
                    $this->createTargetDatabase($pdo, $dbName);
                    $dbExists = true;
                    $dbCreated = true;
                } catch (PDOException $e) {
                    return [
                        'success' => false,
                        'message' => '连接成功，但当前数据库账号没有创建目标数据库的权限。请换用有建库权限的账号，或先手动创建空数据库并授权当前账号。',
                        'detail'  => $e->getMessage(),
                    ];
                }
            }

            $tableCount = 0;
            $isEmpty = true;

            $tableStmt = $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($dbName)
            );
            $tableCount = (int) $tableStmt->fetchColumn();
            $isEmpty = $tableCount === 0;

            if ($isEmpty) {
                try {
                    $this->probeInstallDatabasePrivileges($pdo, $dbName);
                } catch (PDOException $e) {
                    return [
                        'success' => false,
                        'message' => '连接成功，但当前数据库账号无法在目标数据库创建、写入或清理测试表。请授予该库完整权限后再继续安装。',
                        'detail'  => $e->getMessage(),
                    ];
                }
            }

            $version = $pdo->query('SELECT VERSION()')->fetchColumn();

            $pdo = null;

            $message = '连接成功，目标数据库已创建并为空，可以继续安装';
            if (!$dbCreated && $isEmpty) {
                $message = '连接成功，目标数据库为空，可以继续安装';
            } elseif (!$isEmpty) {
                $message = sprintf('连接成功，但目标数据库已有 %d 张表，请切换到空数据库后再安装', $tableCount);
            }

            return [
                'success'     => $isEmpty,
                'version'     => $version,
                'db_exists'   => $dbExists,
                'db_created'  => $dbCreated,
                'table_count' => $tableCount,
                'is_empty'    => $isEmpty,
                'message'     => $message,
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => $this->normalizeDatabaseError($e->getMessage()),
                'detail'  => $e->getMessage(),
            ];
        }
    }

    public function testRedis(array $config): array
    {
        if (!extension_loaded('redis')) {
            return ['success' => false, 'message' => 'Redis 扩展未安装'];
        }

        try {
            $redis = new Redis();
            $connected = $redis->connect(
                $config['host'],
                (int) ($config['port'] ?? 6379),
                3.0
            );

            if (!$connected) {
                return ['success' => false, 'message' => 'Redis 连接失败，请确认主机、端口和网络可达'];
            }

            $password = $config['password'] ?? '';
            if ($password !== '') {
                $redis->auth($password);
            }

            $db = (int) ($config['db'] ?? 0);
            $redis->select($db);
            $redis->ping();
            $keyCount = $redis->dbSize();
            $info = $redis->info('server');
            $redis->close();

            $isEmpty = $keyCount === 0;
            $message = $isEmpty
                ? sprintf('连接成功，Redis DB %d 为空，可以继续安装', $db)
                : sprintf('连接成功，但 Redis DB %d 已有 %d 个键，请切换到空 DB 后再安装', $db, $keyCount);

            return [
                'success'   => $isEmpty,
                'version'   => $info['redis_version'] ?? 'unknown',
                'db'        => $db,
                'key_count' => $keyCount,
                'is_empty'  => $isEmpty,
                'message'   => $message,
            ];
        } catch (RedisException $e) {
            return [
                'success' => false,
                'message' => $this->normalizeRedisError($e->getMessage()),
                'detail'  => $e->getMessage(),
            ];
        }
    }

    /**
     * 从安装期 env 构建安装参数。
     *
     * 优先级：
     * 1. 当前进程 env（Docker entrypoint / 用户显式 export）
     * 2. 项目根目录 .env（统一主配置源）
     * 3. backend/.env（生产 / 仅后端容器 / 历史环境兜底）
     *
     * 管理员账号和演示数据不再作为 env 配置入口，Web 安装统一回到安装表单确认。
     * 可选 CLI 安装入口仍使用代码内置默认值，避免依赖第二套 env 配置。
     */
    public function buildParamsFromEnv(): array
    {
        $rootValues = $this->readRootEnvFile();
        $backendValues = $this->readBackendEnvFile();
        $inContainer = $this->isRunningInContainer();

        $get = function (string $name) use ($rootValues, $backendValues, $inContainer): string {
            return $this->resolveInstallEnvValue($name, $rootValues, $backendValues, $inContainer);
        };

        return [
            'db_host'        => $get('DB_HOST'),
            'db_port'        => $get('DB_PORT') !== '' ? (int) $get('DB_PORT') : 3306,
            'db_user'        => $get('DB_USER'),
            'db_pass'        => $get('DB_PASS'),
            'db_name'        => $get('DB_NAME'),
            'redis_host'     => $get('REDIS_HOST'),
            'redis_port'     => $get('REDIS_PORT') !== '' ? (int) $get('REDIS_PORT') : 6379,
            'redis_db'       => $get('REDIS_CACHE_DB') !== '' ? (int) $get('REDIS_CACHE_DB') : 0,
            'redis_password' => $get('REDIS_PASSWORD'),
            'admin_user'     => 'admin',
            'admin_pass'     => 'admin123',
            'import_demo'    => false,
            'cron_enable'    => false,
            'swoole_queue_enable' => false,
            // 站点域名（用于 Upload 本地域名、邮件/分享链接等全局场景，统一存 mb_setting.site_url，不再写 env）
            // env 中可预置 SITE_URL 作为 CLI 安装（install:auto）的输入；Web 向导提交空串时会回退到当前 request 域名
            'site_url'       => $get('SITE_URL'),
        ];
    }

    /**
     * @param array<string, string> $rootValues
     * @param array<string, string> $backendValues
     */
    private function resolveInstallEnvValue(
        string $name,
        array $rootValues,
        array $backendValues,
        bool $inContainer
    ): string {
        $processValue = getenv($name);
        if ($processValue !== false && trim((string) $processValue) !== '') {
            return trim((string) $processValue);
        }

        if ($name === 'DB_HOST') {
            return $this->resolveInstallHostValue(
                $rootValues[$name] ?? '',
                $backendValues[$name] ?? '',
                '127.0.0.1',
                $inContainer
            );
        }

        if ($name === 'REDIS_HOST') {
            return $this->resolveInstallHostValue(
                $rootValues[$name] ?? '',
                $backendValues[$name] ?? '',
                '127.0.0.1',
                $inContainer
            );
        }

        if ($name === 'DB_PORT') {
            return $this->firstNonEmpty([
                $rootValues['DB_PORT'] ?? '',
                !$inContainer ? ($rootValues['MYSQL_PORT'] ?? '') : '',
                $backendValues['DB_PORT'] ?? '',
            ]);
        }

        if ($name === 'REDIS_PORT') {
            return $this->firstNonEmpty([
                !$inContainer ? ($rootValues['REDIS_PORT'] ?? '') : '',
                $backendValues['REDIS_PORT'] ?? '',
                $rootValues['REDIS_PORT'] ?? '',
            ]);
        }

        return $this->firstNonEmpty([
            $rootValues[$name] ?? '',
            $backendValues[$name] ?? '',
        ]);
    }

    private function resolveInstallHostValue(string $rootValue, string $backendValue, string $default, bool $inContainer): string
    {
        $rootValue = trim($rootValue);
        if ($rootValue !== '') {
            if (!$inContainer && in_array($rootValue, ['mysql', 'redis'], true)) {
                return $default;
            }

            return $rootValue;
        }

        if (!$inContainer && $this->readRootEnvFile() !== []) {
            return $default;
        }

        return $this->firstNonEmpty([$backendValue, $default]);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function readRootEnvFile(): array
    {
        $backendRoot = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($backendRoot);

        $paths = array_unique([
            $projectRoot . DIRECTORY_SEPARATOR . '.env',
            DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . '.env',
        ]);

        foreach ($paths as $path) {
            $values = $this->parseEnvFile($path);
            if ($values !== []) {
                return $values;
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function readBackendEnvFile(): array
    {
        return $this->parseEnvFile($this->resolveBackendEnvPath());
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $key => $value) {
            $values[(string) $key] = is_string($value) ? trim($value) : trim((string) $value);
        }

        return $values;
    }

    private function isRunningInContainer(): bool
    {
        return is_file('/.dockerenv');
    }

    public function getFormDefaults(): array
    {
        $params = $this->buildParamsFromEnv();

        // site_url 默认值优先使用当前访问域名（安装页首次打开时预填），用户可编辑
        if ($params['site_url'] === '') {
            $params['site_url'] = $this->resolveDefaultSiteUrl();
        }

        return $params;
    }

    /**
     * 推导 site_url 默认值。
     *
     * 候选优先级（任一命中即返回）：
     * 1. HTTP 上下文下的当前 request 域名（Web 安装向导走这里）
     * 2. 环境变量 SITE_URL（运维可在 .env / compose env_file 中预置，主要供 install:auto CLI 场景）
     * 3. 由 SWOOLE_HTTP_HOST + SWOOLE_HTTP_PORT 拼出的 http://host:port（兜底）
     *
     * 返回空串表示完全无法推导，调用方应拒绝继续安装而不是静默装出一个只允许 localhost 的实例。
     */
    private function resolveDefaultSiteUrl(): string
    {
        // 1. HTTP 请求上下文
        try {
            $request = request();
            if ($request) {
                $scheme = $request->scheme() ?: 'http';
                $host = trim((string) $request->host());
                if ($host !== '') {
                    return $scheme . '://' . $host;
                }
            }
        } catch (\Throwable $e) {
            // CLI 无 request，走 env 兜底
        }

        // 2. env SITE_URL
        $siteUrlEnv = trim((string) env('SITE_URL', ''));
        if ($siteUrlEnv !== '') {
            return rtrim($siteUrlEnv, '/');
        }

        // 3. 由 SWOOLE host+port 拼 fallback（大概率只对开发环境有意义）
        $swooleHost = trim((string) env('SWOOLE_HTTP_HOST', ''));
        $swoolePort = trim((string) env('SWOOLE_HTTP_PORT', ''));
        if ($swooleHost !== '' && $swoolePort !== '') {
            // 0.0.0.0 不是合法访问域名，替换为 localhost 作为开发占位
            if ($swooleHost === '0.0.0.0') {
                $swooleHost = 'localhost';
            }
            return 'http://' . $swooleHost . ':' . $swoolePort;
        }

        return '';
    }

    public function execute(array $params, ?callable $progress = null): array
    {
        $steps = [];
        $emit = function (string $step, string $status, string $message, array $extra = []) use (&$steps, $progress): void {
            $event = array_merge([
                'step'    => $step,
                'title'   => $this->stepTitles[$step] ?? $step,
                'status'  => $status,
                'message' => $message,
            ], $extra);

            if ($status !== 'running') {
                $steps[$step] = $event;
            }

            if ($progress !== null) {
                $progress($event);
            }
        };

        $emit('environment', 'running', '正在检查安装环境…');
        try {
            $environment = $this->checkEnvironment();
        } catch (\Throwable $e) {
            $message = '安装环境检查失败：' . $e->getMessage();
            $emit('environment', 'error', $message);

            return $this->buildFailureResponse('environment', $message, $steps, $e->getMessage());
        }
        if (($environment['pass'] ?? false) !== true) {
            $failedNames = [];
            foreach (($environment['items'] ?? []) as $item) {
                if (is_array($item) && ($item['pass'] ?? false) !== true) {
                    $name = trim((string) ($item['name'] ?? ''));
                    if ($name !== '') {
                        $failedNames[] = $name;
                    }
                }
            }
            $message = '安装环境检查未通过';
            if ($failedNames !== []) {
                $message .= '：' . implode('、', $failedNames);
            }
            $emit('environment', 'error', $message, ['items' => $environment['items'] ?? []]);

            return $this->buildFailureResponse('environment', $message, $steps);
        }
        $emit('environment', 'success', '安装环境检查通过');

        $dbConfig = [
            'host' => trim((string) ($params['db_host'] ?? '')),
            'port' => (int) ($params['db_port'] ?? 3306),
            'user' => trim((string) ($params['db_user'] ?? '')),
            'pass' => (string) ($params['db_pass'] ?? ''),
            'name' => trim((string) ($params['db_name'] ?? '')),
        ];

        $redisConfig = [
            'host'     => trim((string) ($params['redis_host'] ?? '')),
            'port'     => (int) ($params['redis_port'] ?? 6379),
            'db'       => (int) ($params['redis_db'] ?? 0),
            'password' => (string) ($params['redis_password'] ?? ''),
        ];

        $adminUser = trim((string) ($params['admin_user'] ?? ''));
        $adminPass = (string) ($params['admin_pass'] ?? '');
        $importDemo = !empty($params['import_demo']);
        $cronEnable = $this->boolParam($params['cron_enable'] ?? false);
        $swooleQueueEnable = $this->boolParam($params['swoole_queue_enable'] ?? false);
        $siteUrl = trim((string) ($params['site_url'] ?? ''));
        if ($siteUrl === '') {
            $siteUrl = $this->resolveDefaultSiteUrl();
        }
        $siteUrl = rtrim($siteUrl, '/');
        if ($siteUrl === '') {
            // 拒绝用空 site_url 继续安装，否则 Upload 本地访问域名、邮件/分享链接等
            // 全局场景会退化到不可预期状态（静默故障，事后极难排查）
            return $this->buildFailureResponse(
                'write_env',
                '站点域名（site_url）未指定。Web 安装请在表单填写；CLI 安装（install:auto）请设置 SITE_URL 环境变量。',
                $steps
            );
        }
        $jwtSecret = $this->resolveJwtSecret($params, $this->readEnvFile());
        $runtimeMarker = bin2hex(random_bytes(16));

        $emit('db_test', 'running', '正在校验并准备数据库…');
        $dbTest = $this->testDatabase($dbConfig);
        if (!$dbTest['success']) {
            $emit('db_test', 'error', $dbTest['message'], ['detail' => $dbTest['detail'] ?? null]);
            return $this->buildFailureResponse('db_test', $dbTest['message'], $steps, $dbTest['detail'] ?? null);
        }
        $emit('db_test', 'success', $dbTest['message'], [
            'db_exists'   => $dbTest['db_exists'] ?? false,
            'table_count' => $dbTest['table_count'] ?? 0,
            'is_empty'    => $dbTest['is_empty'] ?? false,
        ]);

        $emit('redis_test', 'running', '正在校验 Redis 连接…');
        $redisTest = $this->testRedis($redisConfig);
        if (!$redisTest['success']) {
            $emit('redis_test', 'error', $redisTest['message'], ['detail' => $redisTest['detail'] ?? null]);
            return $this->buildFailureResponse('redis_test', $redisTest['message'], $steps, $redisTest['detail'] ?? null);
        }
        $emit('redis_test', 'success', $redisTest['message'], [
            'db'        => $redisTest['db'] ?? $redisConfig['db'],
            'key_count' => $redisTest['key_count'] ?? 0,
            'is_empty'  => $redisTest['is_empty'] ?? false,
        ]);

        $emit('write_env', 'running', '正在写入后端运行配置…');
        try {
            // 注意：
            // - SITE_URL 写入 env 作为 **静态副本**，便于运维通过 grep / docker exec printenv
            //   查看当前实例站点域名。后台改 mb_setting.site_url 后 env SITE_URL 不会自动同步
            //   （需重新安装或手动改），属于已知取舍。
            // - JWT_SECRET 保留在 env（敏感，DB 泄露不会连带泄露 Token 签名密钥）
            $envData = [
                'DB_TYPE'                => 'mysql',
                'DB_HOST'                => $dbConfig['host'],
                'DB_NAME'                => $dbConfig['name'],
                'DB_USER'                => $dbConfig['user'],
                'DB_PASS'                => $dbConfig['pass'],
                'DB_PORT'                => (string) $dbConfig['port'],
                'DB_CHARSET'             => 'utf8mb4',
                'REDIS_HOST'             => $redisConfig['host'],
                'REDIS_PORT'             => (string) $redisConfig['port'],
                'REDIS_CACHE_DB'         => (string) $redisConfig['db'],
                'REDIS_PASSWORD'         => $redisConfig['password'],
                'CACHE_DRIVER'           => 'redis',
                'CRON_ENABLE'            => $cronEnable ? 'true' : 'false',
                'SWOOLE_QUEUE_ENABLE'    => $swooleQueueEnable ? 'true' : 'false',
                'JWT_SECRET'             => $jwtSecret,
                'JWT_EXPIRE'             => (string) env('JWT_EXPIRE', 7200),
                'JWT_REFRESH_EXPIRE'     => (string) env('JWT_REFRESH_EXPIRE', 2592000),
                'INSTALL_RUNTIME_MARKER' => $runtimeMarker,
                'SITE_URL'               => $siteUrl,
            ];
            $this->writeEnvFile($envData);
            $this->writeProjectRootEnvFile($envData);
            $this->applyRuntimeConfig($envData);
        } catch (\Throwable $e) {
            $emit('write_env', 'error', '写入配置文件失败：' . $e->getMessage());
            return $this->buildFailureResponse('write_env', '写入配置文件失败：' . $e->getMessage(), $steps);
        }
        $emit('write_env', 'success', '配置文件已写入并已应用到当前安装进程');

        try {
            $emit('create_db', 'running', '正在创建数据库…');
            $pdo = $this->createDatabase($dbConfig);
            $emit('create_db', 'success', '数据库已就绪');
        } catch (PDOException $e) {
            $message = '创建数据库失败：' . $this->normalizeDatabaseError($e->getMessage());
            $emit('create_db', 'error', $message, ['detail' => $e->getMessage()]);
            return $this->buildFailureResponse('create_db', $message, $steps, $e->getMessage());
        }

        try {
            $emit('import_sql', 'running', '正在导入表结构…');
            $this->importSqlFiles($pdo);
            $emit('import_sql', 'success', '表结构导入完成');
        } catch (\Throwable $e) {
            $emit('import_sql', 'error', '导入表结构失败：' . $e->getMessage());
            return $this->buildFailureResponse('import_sql', '导入表结构失败：' . $e->getMessage(), $steps);
        }

        try {
            $emit('create_admin', 'running', '正在创建管理员账号…');
            $this->createSuperAdmin($pdo, $adminUser, $adminPass);
            $emit('create_admin', 'success', '管理员账号已创建');
        } catch (\Throwable $e) {
            $emit('create_admin', 'error', '创建管理员失败：' . $e->getMessage());
            return $this->buildFailureResponse('create_admin', '创建管理员失败：' . $e->getMessage(), $steps);
        }

        if ($importDemo) {
            try {
                $emit('import_demo', 'running', '正在导入演示数据…');
                $this->importDemoData($pdo);
                $emit('import_demo', 'success', '演示数据导入完成');
            } catch (\Throwable $e) {
                $emit('import_demo', 'error', '导入演示数据失败：' . $e->getMessage());
                return $this->buildFailureResponse('import_demo', '导入演示数据失败：' . $e->getMessage(), $steps);
            }

            try {
                $emit('copy_demo_static', 'running', '正在拷贝演示静态资源…');
                $copyResult = $this->copyDemoStatics();
                if (!empty($copyResult['source_missing'])) {
                    $emit('copy_demo_static', 'warning', '未找到演示静态资源源目录，已跳过拷贝', ['detail' => $copyResult]);
                } elseif (!empty($copyResult['errors'])) {
                    $emit(
                        'copy_demo_static',
                        'warning',
                        sprintf(
                            '演示静态资源部分就绪（新增 %d，已存在 %d，失败 %d）',
                            $copyResult['copied'],
                            $copyResult['existing'],
                            count($copyResult['errors'])
                        ),
                        ['detail' => $copyResult]
                    );
                } elseif ($copyResult['copied'] > 0 || $copyResult['existing'] > 0) {
                    $emit(
                        'copy_demo_static',
                        'success',
                        sprintf(
                            '演示静态资源就绪（新增 %d，已存在 %d）',
                            $copyResult['copied'],
                            $copyResult['existing']
                        ),
                        ['detail' => $copyResult]
                    );
                } else {
                    $emit('copy_demo_static', 'warning', '演示静态资源源目录为空，已跳过拷贝', ['detail' => $copyResult]);
                }
            } catch (\Throwable $e) {
                $emit('copy_demo_static', 'warning', '拷贝演示静态资源异常（不影响安装）：' . $e->getMessage());
            }
        } else {
            $emit('import_demo', 'skipped', '已跳过演示数据导入');
            $emit('copy_demo_static', 'skipped', '已跳过演示静态资源拷贝');
        }

        $pdo = null;

        try {
            $emit('sync_permissions', 'running', '正在同步路由权限与菜单…');
            $this->syncRoutePermissionsInCliProcess();
            $emit('sync_permissions', 'success', '路由权限已同步');
        } catch (\Throwable $e) {
            $message = '权限同步失败：' . $e->getMessage();
            $emit('sync_permissions', 'error', $message);
            return $this->buildFailureResponse('sync_permissions', $message, $steps);
        }

        try {
            $emit('sync_setting_permissions', 'running', '正在同步系统设置菜单…');
            app()->make(SettingService::class)->rebuildAllPermissions();
            $emit('sync_setting_permissions', 'success', '系统设置菜单已同步');
        } catch (\Throwable $e) {
            $message = '系统设置菜单同步失败：' . $e->getMessage();
            $emit('sync_setting_permissions', 'error', $message);
            return $this->buildFailureResponse('sync_setting_permissions', $message, $steps);
        }

        try {
            $emit('seed_role_permissions', 'running', '正在初始化默认角色权限…');
            $this->seedDefaultRolePermissions();
            $emit('seed_role_permissions', 'success', '默认角色权限已初始化');
        } catch (\Throwable $e) {
            $message = '默认角色权限初始化失败：' . $e->getMessage();
            $emit('seed_role_permissions', 'error', $message);
            return $this->buildFailureResponse('seed_role_permissions', $message, $steps);
        }

        try {
            $emit('import_regions', 'running', '正在导入地区数据…');
            $regionProgress = [
                'processed' => 0,
                'total'     => 0,
                'imported'  => 0,
                'updated'   => 0,
                'percent'   => 0,
            ];
            $imported = app()->make(RegionImportService::class)
                ->importFromFile(
                    $this->installDataPath('region') . DIRECTORY_SEPARATOR . 'pcas-code.json',
                    false,
                    function (array $progress) use (&$regionProgress, $emit): void {
                        $regionProgress = $progress;
                        $processed = (int) ($progress['processed'] ?? 0);
                        $total = (int) ($progress['total'] ?? 0);
                        $percent = (int) ($progress['percent'] ?? 0);
                        $importedCount = (int) ($progress['imported'] ?? 0);
                        $updatedCount = (int) ($progress['updated'] ?? 0);
                        $message = $total > 0
                            ? sprintf(
                                '正在导入地区数据：%d/%d（%d%%），新增 %d，更新 %d',
                                $processed,
                                $total,
                                $percent,
                                $importedCount,
                                $updatedCount
                            )
                            : '正在导入地区数据…';

                        $emit('import_regions', 'running', $message, ['progress' => $progress]);
                    }
                );
            $processed = (int) ($regionProgress['processed'] ?? 0);
            $updated = (int) ($regionProgress['updated'] ?? 0);
            $total = (int) ($regionProgress['total'] ?? 0);
            $emit(
                'import_regions',
                'success',
                sprintf('地区数据已导入（共处理 %d/%d 条，新增 %d，更新 %d）', $processed, $total, $imported, $updated),
                ['progress' => array_merge($regionProgress, ['percent' => 100])]
            );
        } catch (\Throwable $e) {
            $message = '地区数据导入失败：' . $e->getMessage();
            $emit('import_regions', 'error', $message);
            return $this->buildFailureResponse('import_regions', $message, $steps);
        }

        try {
            $emit('seed_site_url', 'running', '正在写入站点域名至 mb_setting.site_url…');
            $this->seedSiteUrl($siteUrl);
            $emit('seed_site_url', 'success', '站点域名已写入：' . $siteUrl);
        } catch (\Throwable $e) {
            $message = '写入站点域名失败：' . $e->getMessage();
            $emit('seed_site_url', 'error', $message);
            return $this->buildFailureResponse('seed_site_url', $message, $steps);
        }

        try {
            $emit('verify_default_assets', 'running', '正在检查默认静态资源…');
            $missing = $this->verifyDefaultAssets();
            if (empty($missing)) {
                $emit('verify_default_assets', 'success', '默认静态资源齐全');
            } else {
                // 资源缺失不阻断安装，仅提示
                $emit('verify_default_assets', 'warning', '部分默认素材缺失：' . implode('、', $missing), [
                    'missing' => $missing,
                ]);
            }
        } catch (\Throwable $e) {
            // 检查本身出错也不阻断
            $emit('verify_default_assets', 'warning', '默认静态资源检查异常：' . $e->getMessage());
        }

        try {
            $emit('write_lock', 'running', '正在写入安装锁…');
            $this->writeLockFile();
            $emit('write_lock', 'success', '安装锁写入完成');
        } catch (\Throwable $e) {
            $emit('write_lock', 'error', '写入安装锁失败：' . $e->getMessage());
            return $this->buildFailureResponse('write_lock', '写入安装锁失败：' . $e->getMessage(), $steps);
        }

        $result = [
            'success'  => true,
            'step'     => 'write_lock',
            'message'  => '安装完成',
            'steps'    => array_values($steps),
            'redirect' => true,
        ];

        if ($progress !== null) {
            $progress([
                'event'   => 'complete',
                'success' => true,
                'message' => '安装完成',
                'result'  => $result,
            ]);
        }

        return $result;
    }

    public function checkEntryReady(string $target = 'admin'): array
    {
        $target = $this->normalizeEntryTarget($target);
        $entryMeta = $this->entryTargetMeta($target);

        if (!$this->isInstalled()) {
            return [
                'ready'   => false,
                'message' => '系统尚未安装完成，请先完成安装流程',
                'target'  => $target,
            ];
        }

        if (!$entryMeta['exists']) {
            return [
                'ready'   => false,
                'message' => sprintf('未检测到%s构建产物，请先确认 %s 已存在', $entryMeta['label'], $entryMeta['path']),
                'target'  => $target,
                'path'    => $entryMeta['path'],
            ];
        }

        $envValues = $this->readEnvFile();
        $fileMarker = trim((string) ($envValues['INSTALL_RUNTIME_MARKER'] ?? ''));
        $runtimeMarker = trim((string) env('INSTALL_RUNTIME_MARKER', ''));

        if ($fileMarker === '') {
            return [
                'ready'   => false,
                'message' => sprintf('未检测到运行态标记，请先重启 Swoole 后再进入%s', $entryMeta['label']),
                'target'  => $target,
            ];
        }

        if ($runtimeMarker === '' || !hash_equals($fileMarker, $runtimeMarker)) {
            return [
                'ready'   => false,
                'message' => sprintf('检测到 Swoole 尚未重启，当前运行进程还没有加载最新配置，请先重启 Swoole 后再进入%s', $entryMeta['label']),
                'target'  => $target,
            ];
        }

        return [
            'ready'   => true,
            'message' => sprintf('已检测到最新运行态配置，可以进入%s', $entryMeta['label']),
            'target'  => $target,
            'path'    => $entryMeta['path'],
        ];
    }

    private function normalizeEntryTarget(string $target): string
    {
        $target = strtolower(trim($target));

        return in_array($target, ['admin', 'client'], true) ? $target : 'admin';
    }

    /**
     * @return array{label:string,path:string,exists:bool}
     */
    private function entryTargetMeta(string $target): array
    {
        $publicRoot = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public';

        if ($target === 'client') {
            $path = $publicRoot . DIRECTORY_SEPARATOR . 'client' . DIRECTORY_SEPARATOR . 'index.html';

            return [
                'label'  => '客户端',
                'path'   => $path,
                'exists' => is_file($path),
            ];
        }

        $path = $publicRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.html';

        return [
            'label'  => '后台管理',
            'path'   => $path,
            'exists' => is_file($path),
        ];
    }

    private function buildFailureResponse(string $step, string $message, array $steps, ?string $detail = null): array
    {
        return [
            'success'  => false,
            'step'     => $step,
            'message'  => $message,
            'detail'   => $detail,
            'steps'    => array_values($steps),
            'redirect' => false,
        ];
    }

    /**
     * 安装完成后，默认 super_admin 角色拥有所有已同步的菜单和按钮权限。
     */
    private function seedDefaultRolePermissions(): void
    {
        $roleId = Db::name('role')->where('code', 'super_admin')->value('id');
        if (empty($roleId)) {
            return;
        }

        Db::name('admin_role')->where('admin_id', 1)->delete();

        $prefix = (string) config('database.connections.mysql.prefix', 'mb_');
        $roleTable = $prefix . 'role';
        $permissionTable = $prefix . 'permission';
        $rolePermissionTable = $prefix . 'role_permission';

        Db::execute(
            "INSERT IGNORE INTO `{$rolePermissionTable}` (`role_id`, `permission_id`, `create_time`)
            SELECT `r`.`id`, `p`.`id`, NOW()
            FROM `{$roleTable}` AS `r`
            INNER JOIN `{$permissionTable}` AS `p`
            WHERE `r`.`code` = 'super_admin'
              AND `p`.`type` IN (1, 2)
              AND `p`.`status` = 1"
        );
    }

    /**
     * @param array<string, string> $envData
     */
    private function writeEnvFile(array $envData): void
    {
        $templatePath = app()->getRootPath() . '.example.env';
        (new BackendEnvFileStore())->write($this->resolveBackendEnvPath(), $templatePath, $envData);
    }

    /**
     * 安装时沿用当前配置源中的 JWT 密钥，避免 Docker 再次派生运行配置后密钥回退。
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $envValues
     */
    private function resolveJwtSecret(array $params, array $envValues): string
    {
        $jwtSecret = trim((string) ($params['jwt_secret'] ?? ''));
        if ($jwtSecret === '') {
            $jwtSecret = trim((string) ($envValues['JWT_SECRET'] ?? ''));
        }

        if ($jwtSecret === '' || $jwtSecret === self::ENV_SECRET_PLACEHOLDER) {
            return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        }

        return $jwtSecret;
    }

    protected function resolveBackendEnvPath(): string
    {
        $configuredPath = trim((string) getenv('MALLBASE_BACKEND_ENV_PATH'));

        return $configuredPath !== '' ? $configuredPath : app()->getRootPath() . '.env';
    }

    private function syncRoutePermissionsInCliProcess(): void
    {
        $backendRoot = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR);
        app()->make(InstallCommandRunner::class)->runThinkCommand(
            $backendRoot,
            ['sync:permissions'],
            self::PERMISSION_SYNC_TIMEOUT_MS,
        );
    }

    /**
     * 本地安装时同步根目录 .env；容器内不可写或无项目根模板时静默跳过。
     *
     * @param array<string, string> $envData
     */
    private function writeProjectRootEnvFile(array $envData): void
    {
        $backendRoot = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($backendRoot);
        $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        $templatePath = $projectRoot . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'docker' . DIRECTORY_SEPARATOR . '.example.env';

        if (!is_file($templatePath)) {
            return;
        }
        if (is_file($envPath) && !is_writable($envPath)) {
            return;
        }
        if (!is_file($envPath) && !is_writable($projectRoot)) {
            return;
        }

        $rootKeys = [
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'REDIS_HOST',
            'REDIS_PORT',
            'REDIS_CACHE_DB',
            'REDIS_PASSWORD',
            'CACHE_DRIVER',
            'JWT_SECRET',
            'JWT_EXPIRE',
            'JWT_REFRESH_EXPIRE',
            'SITE_URL',
        ];

        (new BackendEnvFileStore())->write(
            $envPath,
            $templatePath,
            array_intersect_key($envData, array_flip($rootKeys)),
        );
    }

    /**
     * @param array<string, string> $envData
     */
    private function applyRuntimeConfig(array $envData): void
    {
        foreach ($envData as $key => $value) {
            if ($key === 'INSTALL_RUNTIME_MARKER') {
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $runtimeEnvData = $envData;
        unset($runtimeEnvData['INSTALL_RUNTIME_MARKER']);
        app()->env->set($runtimeEnvData);

        $database = Config::get('database', []);
        $database['default'] = 'mysql';
        $database['connections']['mysql'] = array_merge($database['connections']['mysql'] ?? [], [
            'type'     => $envData['DB_TYPE'] ?? 'mysql',
            'hostname' => $envData['DB_HOST'] ?? '127.0.0.1',
            'database' => $envData['DB_NAME'] ?? '',
            'username' => $envData['DB_USER'] ?? 'root',
            'password' => $envData['DB_PASS'] ?? '',
            'hostport' => $envData['DB_PORT'] ?? '3306',
            'charset'  => $envData['DB_CHARSET'] ?? 'utf8mb4',
            'prefix'   => $envData['DB_PREFIX'] ?? 'mb_',
        ]);
        Config::set($database, 'database');

        $cache = Config::get('cache', []);
        $cache['default'] = $envData['CACHE_DRIVER'] ?? 'redis';
        $cache['stores']['redis'] = array_merge($cache['stores']['redis'] ?? [], [
            'type'       => 'redis',
            'host'       => $envData['REDIS_HOST'] ?? '127.0.0.1',
            'port'       => (int) ($envData['REDIS_PORT'] ?? 6379),
            'password'   => $envData['REDIS_PASSWORD'] ?? '',
            'select'     => (int) ($envData['REDIS_CACHE_DB'] ?? 0),
            'timeout'    => (int) ($envData['REDIS_TIMEOUT'] ?? 0),
            'persistent' => filter_var($envData['REDIS_PERSISTENT'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'prefix'     => $envData['CACHE_PREFIX'] ?? '',
            'expire'     => (int) ($envData['CACHE_EXPIRE'] ?? 0),
            'tag_prefix' => $envData['CACHE_TAG_PREFIX'] ?? 'tag:',
            'serialize'  => [],
        ]);
        Config::set($cache, 'cache');

        $jwt = Config::get('jwt', []);
        $jwt['secret'] = $envData['JWT_SECRET'] ?? '';
        $jwt['expire'] = (int) ($envData['JWT_EXPIRE'] ?? 7200);
        $jwt['refresh_expire'] = (int) ($envData['JWT_REFRESH_EXPIRE'] ?? 2592000);
        Config::set($jwt, 'jwt');

        $cron = Config::get('cron', []);
        $cron['enable'] = $this->boolParam($envData['CRON_ENABLE'] ?? false);
        Config::set($cron, 'cron');

        $swoole = Config::get('swoole', []);
        $swoole['queue']['enable'] = $this->boolParam($envData['SWOOLE_QUEUE_ENABLE'] ?? false);
        Config::set($swoole, 'swoole');

        $cacheManager = app()->make('cache');
        if (method_exists($cacheManager, 'forgetDriver')) {
            $cacheManager->forgetDriver(['redis', 'file']);
        }

        app()->delete('think\\DbManager');
        Db::connect(null, true);
    }

    private function boolParam(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'on', 'yes'], true);
    }

    private function createDatabase(array $config): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']);
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);

        $dbName = $config['name'];
        $this->createTargetDatabase($pdo, $dbName);
        $pdo->exec('USE ' . $this->quoteIdentifier($dbName));

        return $pdo;
    }

    private function createTargetDatabase(PDO $pdo, string $dbName): void
    {
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $this->quoteIdentifier($dbName)
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    private function probeInstallDatabasePrivileges(PDO $pdo, string $dbName): void
    {
        $tableName = '_mb_install_probe_' . bin2hex(random_bytes(8));
        $qualifiedTable = $this->quoteIdentifier($dbName) . '.' . $this->quoteIdentifier($tableName);
        $created = false;

        try {
            $pdo->exec(
                'CREATE TABLE ' . $qualifiedTable
                . ' (`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(32) NOT NULL)'
                . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $created = true;
            $pdo->exec("INSERT INTO {$qualifiedTable} (`id`, `value`) VALUES (1, 'ok')");
        } finally {
            if ($created) {
                $pdo->exec('DROP TABLE ' . $qualifiedTable);
            }
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function importSqlFiles(PDO $pdo): void
    {
        $sqlDir = $this->installDataPath('schema');
        $files = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false || $files === []) {
            throw new \RuntimeException('schema 目录下未找到任何 .sql 文件: ' . $sqlDir);
        }
        sort($files);

        foreach ($files as $filePath) {
            $sql = file_get_contents($filePath);
            if ($sql === false || trim($sql) === '') {
                continue;
            }
            $pdo->exec($sql);
        }
    }

    private function createSuperAdmin(PDO $pdo, string $username, string $password): void
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');
        $avatar = self::DEFAULT_AVATAR_PATH;

        $stmt = $pdo->prepare(
            "INSERT INTO `mb_admin` (`id`, `username`, `nickname`, `password`, `avatar`, `status`, `password_changed_at`, `create_time`, `update_time`)
             VALUES (1, :username, :nickname, :password, :avatar, 1, :password_changed_at, :create_time, :update_time)
             ON DUPLICATE KEY UPDATE `username` = :username2, `password` = :password2, `password_changed_at` = :password_changed_at2, `update_time` = :update_time2"
        );

        $stmt->execute([
            ':username'             => $username,
            ':nickname'             => '超级管理员',
            ':password'             => $hashedPassword,
            ':avatar'               => $avatar,
            ':password_changed_at'  => $now,
            ':create_time'          => $now,
            ':update_time'          => $now,
            ':username2'            => $username,
            ':password2'            => $hashedPassword,
            ':password_changed_at2' => $now,
            ':update_time2'         => $now,
        ]);
    }

    /**
     * 把安装表单提交的站点域名写入 mb_setting.site_url
     * 直接走 ThinkPHP 的 Db facade（sync_permissions 步骤已触发框架引导，此时连接池就绪）
     */
    private function seedSiteUrl(string $siteUrl): void
    {
        if ($siteUrl === '') {
            // 安装期已在 execute() 入口校验过一次；此处再次防御，明确报错便于定位
            throw new \RuntimeException('site_url 为空，无法写入 mb_setting。请检查安装表单或 SITE_URL 环境变量');
        }

        $updated = Db::table('mb_setting')
            ->where('code', 'site_url')
            ->update([
                'value'       => $siteUrl,
                'update_time' => date('Y-m-d H:i:s'),
            ]);

        // 若 setting 表中还没有 site_url 记录（seed 未执行的兜底），拒绝静默失败
        if ($updated === 0) {
            $exists = Db::table('mb_setting')->where('code', 'site_url')->count();
            if ($exists === 0) {
                throw new \RuntimeException('mb_setting 未包含 site_url seed 数据，请确认 03_mb_setting.sql 导入成功');
            }
        }

        // 清理 Redis 设置缓存，防止旧 site_url 还被业务读到
        try {
            Cache::delete('setting:value:site_url');
            Cache::delete('setting:group:SystemBasic');
            Cache::delete('setting:group:system_read:SystemBasic');
            Cache::delete('setting:all');
        } catch (\Throwable $e) {
            // Redis 未就绪时忽略（安装流程前面的 redis_test 已保证通过，这里仅防御）
        }
    }

    /**
     * 检查默认静态资源是否齐全，返回缺失文件清单（相对 backend/public）
     *
     * @return array<int, string>
     */
    private function verifyDefaultAssets(): array
    {
        $publicRoot = public_path();
        $missing = [];
        foreach ($this->defaultAssets as $relative) {
            $full = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($full)) {
                $missing[] = $relative;
            }
        }
        return $missing;
    }

    private function importDemoData(PDO $pdo): void
    {
        $demoDir = $this->installDataPath('demo');
        if (!is_dir($demoDir)) {
            return;
        }

        $files = glob($demoDir . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false || $files === []) {
            return;
        }
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                throw new \RuntimeException(basename($file) . '：' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * 把演示用静态图（backend/install/static/demo/*）拷贝到 backend/public/static/demo/。
     *
     * 行为：
     * - 源目录不存在：返回 ['source_missing' => true]，调用方按 warning 提示。
     * - 目标目录不存在：自动创建（0755）。
     * - 同名文件已存在：跳过，避免覆盖用户已替换的图。
     * - 单文件拷贝失败：记录到 errors 数组并继续，整体不抛异常。
     *
     * @return array{copied:int,skipped:int,existing:int,source_missing:bool,errors:array<int,string>}
     */
    private function copyDemoStatics(): array
    {
        $result = [
            'copied'         => 0,
            'skipped'        => 0,
            'existing'       => 0,
            'source_missing' => false,
            'errors'         => [],
        ];

        $sourceDir = $this->installStaticPath('demo');
        if (!is_dir($sourceDir)) {
            $result['source_missing'] = true;
            return $result;
        }

        $targetDir = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'static' . DIRECTORY_SEPARATOR . 'demo';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $result['errors'][] = '目标目录创建失败：' . $targetDir;
            return $result;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = ltrim(substr($item->getPathname(), strlen($sourceDir)), DIRECTORY_SEPARATOR);
            if ($relative === '' || str_starts_with($relative, '.')) {
                continue;
            }
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                    $result['errors'][] = '子目录创建失败：' . $relative;
                }
                continue;
            }

            if (is_file($targetPath)) {
                $result['skipped']++;
                $result['existing']++;
                continue;
            }

            $parent = dirname($targetPath);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                $result['errors'][] = '父目录创建失败：' . $relative;
                continue;
            }

            if (@copy($item->getPathname(), $targetPath)) {
                @chmod($targetPath, 0644);
                $result['copied']++;
            } else {
                $err = error_get_last();
                $result['errors'][] = $relative . ' → ' . ($err['message'] ?? '未知原因');
            }
        }

        return $result;
    }

    private function writeLockFile(): void
    {
        app()->make(InstallLockService::class)->writeInstalledLock();
    }

    private function lockFilePath(): string
    {
        return app()->make(InstallLockService::class)->lockFilePath();
    }

    private function releaseFilePath(): string
    {
        $backendRoot = rtrim(root_path(), DIRECTORY_SEPARATOR);

        return dirname($backendRoot) . DIRECTORY_SEPARATOR . '.version';
    }

    private function installDataPath(string $subdir): string
    {
        return root_path() . 'install' . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . $subdir;
    }

    private function installStaticPath(string $subdir): string
    {
        return root_path() . 'install' . DIRECTORY_SEPARATOR . 'static'
            . DIRECTORY_SEPARATOR . $subdir;
    }

    private function normalizeDatabaseError(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'dns lookup resolve failed') || str_contains($lower, 'php_network_getaddresses')) {
            return '数据库主机无法解析，请检查主机地址是否填写正确';
        }
        if (str_contains($lower, 'host') && str_contains($lower, 'is not allowed to connect')) {
            return '数据库已拒绝当前来源主机，请检查 MySQL 用户授权或白名单';
        }
        if (str_contains($lower, 'access denied')) {
            if (
                str_contains($lower, 'create command denied')
                || str_contains($lower, 'insert command denied')
                || str_contains($lower, 'drop command denied')
                || str_contains($lower, 'to database')
            ) {
                return '数据库账号权限不足，请授予目标库完整权限后重试';
            }

            return '数据库用户名或密码错误，请重新检查账号密码';
        }
        if (str_contains($lower, 'connection refused')) {
            return '数据库连接被拒绝，请确认数据库服务已启动且端口可达';
        }
        if (str_contains($lower, 'timed out')) {
            return '数据库连接超时，请检查网络、防火墙或安全组设置';
        }

        return '数据库连接失败：' . $message;
    }

    private function normalizeRedisError(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'php_network_getaddresses')) {
            return 'Redis 主机无法解析，请检查主机地址是否填写正确';
        }
        if (str_contains($lower, 'connection refused')) {
            return 'Redis 连接被拒绝，请确认 Redis 已启动并允许当前连接来源';
        }
        if (str_contains($lower, 'timed out')) {
            return 'Redis 连接超时，请检查网络、防火墙或安全组设置';
        }
        if (str_contains($lower, 'noauth') || str_contains($lower, 'authentication required')) {
            return 'Redis 需要密码认证，请填写正确的 Redis 密码';
        }
        if (str_contains($lower, 'protected mode')) {
            return 'Redis 当前处于保护模式，请检查 bind/protected-mode 或改用本机可达地址';
        }

        return 'Redis 连接失败：' . $message;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(): array
    {
        return array_merge($this->readBackendEnvFile(), $this->readRootEnvFile());
    }
}
