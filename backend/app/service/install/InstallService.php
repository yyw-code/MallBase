<?php

declare(strict_types=1);

namespace app\service\install;

use PDO;
use PDOException;
use Redis;
use RedisException;
use think\facade\Config;
use think\facade\Console;
use think\facade\Db;

class InstallService
{
    /**
     * @var array<string, string>
     */
    private array $stepTitles = [
        'db_test'          => '校验数据库连接',
        'redis_test'       => '校验 Redis 连接',
        'write_env'        => '写入配置文件',
        'create_db'        => '创建数据库',
        'import_sql'       => '导入表结构',
        'create_admin'     => '创建管理员',
        'import_demo'      => '导入演示数据',
        'sync_permissions' => '同步权限数据',
        'write_lock'       => '写入安装锁',
    ];

    public function isInstalled(): bool
    {
        return file_exists($this->lockFilePath());
    }

    public function getLockInfo(): ?array
    {
        $path = $this->lockFilePath();
        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
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

        $runtimePath = app()->getRuntimePath();
        $runtimeWritable = is_dir($runtimePath) && is_writable($runtimePath);
        $items[] = [
            'name'     => 'runtime 目录可写',
            'required' => '可写',
            'current'  => $runtimeWritable ? '可写' : '不可写',
            'pass'     => $runtimeWritable,
        ];

        $publicPath = app()->getRootPath() . 'public';
        $publicWritable = is_dir($publicPath) && is_writable($publicPath);
        $items[] = [
            'name'     => 'public 目录可写',
            'required' => '可写',
            'current'  => $publicWritable ? '可写' : '不可写',
            'pass'     => $publicWritable,
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

    public function testDatabase(array $config): array
    {
        try {
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

            $dbName = $config['name'];
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($dbName));
            $dbExists = $stmt->fetchColumn() !== false;
            $tableCount = 0;
            $isEmpty = true;

            if ($dbExists) {
                $tableStmt = $pdo->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($dbName)
                );
                $tableCount = (int) $tableStmt->fetchColumn();
                $isEmpty = $tableCount === 0;
            }

            $version = $pdo->query('SELECT VERSION()')->fetchColumn();

            $pdo = null;

            $message = '连接成功，数据库不存在（将自动创建）';
            if ($dbExists && $isEmpty) {
                $message = '连接成功，目标数据库为空，可以继续安装';
            } elseif ($dbExists && !$isEmpty) {
                $message = sprintf('连接成功，但目标数据库已有 %d 张表，请切换到空数据库后再安装', $tableCount);
            }

            return [
                'success'     => !$dbExists || $isEmpty,
                'version'     => $version,
                'db_exists'   => $dbExists,
                'table_count' => $tableCount,
                'is_empty'    => !$dbExists || $isEmpty,
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
     * 从进程 env 构建安装参数（方式三 Docker 全套零向导使用）
     *
     * 仅在 env 完备的场景下调用，不尝试推测/填充缺省连接信息。
     * ADMIN_USER / ADMIN_PASS 允许缺省，INSTALL_DEMO 默认不导入。
     */
    public function buildParamsFromEnv(): array
    {
        $get = static function (string $name): string {
            $value = getenv($name);
            return $value === false ? '' : trim((string) $value);
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
            'admin_user'     => $get('ADMIN_USER') !== '' ? $get('ADMIN_USER') : 'admin',
            'admin_pass'     => $get('ADMIN_PASS') !== '' ? $get('ADMIN_PASS') : 'admin123',
            'import_demo'    => $get('INSTALL_DEMO') === '1',
            'cors_origins'   => $get('CORS_ALLOWED_ORIGINS') !== '' ? $get('CORS_ALLOWED_ORIGINS') : '*',
        ];
    }

    public function getFormDefaults(): array
    {
        return $this->buildParamsFromEnv();
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
        $corsOrigins = trim((string) ($params['cors_origins'] ?? '*'));
        $jwtSecret = trim((string) ($params['jwt_secret'] ?? ''));
        if ($jwtSecret === '') {
            $jwtSecret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        }
        $runtimeMarker = bin2hex(random_bytes(16));

        $emit('db_test', 'running', '正在校验数据库连接…');
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

        $emit('write_env', 'running', '正在写入 backend/.env…');
        try {
            $envData = [
                'DB_TYPE'              => 'mysql',
                'DB_HOST'              => $dbConfig['host'],
                'DB_NAME'              => $dbConfig['name'],
                'DB_USER'              => $dbConfig['user'],
                'DB_PASS'              => $dbConfig['pass'],
                'DB_PORT'              => (string) $dbConfig['port'],
                'DB_CHARSET'           => 'utf8mb4',
                'REDIS_HOST'           => $redisConfig['host'],
                'REDIS_PORT'           => (string) $redisConfig['port'],
                'REDIS_CACHE_DB'       => (string) $redisConfig['db'],
                'REDIS_PASSWORD'       => $redisConfig['password'],
                'CACHE_DRIVER'         => 'redis',
                'JWT_SECRET'           => $jwtSecret,
                'INSTALL_RUNTIME_MARKER' => $runtimeMarker,
                'CORS_ALLOWED_ORIGINS' => $corsOrigins !== '' ? $corsOrigins : '*',
                'ADMIN_USER'           => $adminUser,
                'ADMIN_PASS'           => $adminPass,
                'INSTALL_DEMO'         => $importDemo ? '1' : '0',
            ];
            $this->writeEnvFile($envData);
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
        } else {
            $emit('import_demo', 'skipped', '已跳过演示数据导入');
        }

        $pdo = null;

        try {
            $emit('sync_permissions', 'running', '正在同步权限与菜单数据…');
            Console::call('sync:permissions');
            $emit('sync_permissions', 'success', '权限与菜单数据已同步');
        } catch (\Throwable $e) {
            $message = '权限同步失败：' . $e->getMessage();
            $emit('sync_permissions', 'error', $message);
            return $this->buildFailureResponse('sync_permissions', $message, $steps);
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

    public function checkAdminReady(): array
    {
        if (!$this->isInstalled()) {
            return [
                'ready'   => false,
                'message' => '系统尚未安装完成，请先完成安装流程',
            ];
        }

        $envValues = $this->readEnvFile();
        $fileMarker = trim((string) ($envValues['INSTALL_RUNTIME_MARKER'] ?? ''));
        $runtimeMarker = trim((string) env('INSTALL_RUNTIME_MARKER', ''));

        if ($fileMarker === '') {
            return [
                'ready'   => false,
                'message' => '未检测到运行态标记，请先重启 Swoole 后再进入后台管理',
            ];
        }

        if ($runtimeMarker === '' || !hash_equals($fileMarker, $runtimeMarker)) {
            return [
                'ready'   => false,
                'message' => '检测到 Swoole 尚未重启，当前运行进程还没有加载最新配置，请先重启 Swoole 后再进入后台管理',
            ];
        }

        return [
            'ready'   => true,
            'message' => '已检测到最新运行态配置，可以进入后台管理',
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
     * @param array<string, string> $envData
     */
    private function writeEnvFile(array $envData): void
    {
        $envPath = app()->getRootPath() . '.env';
        $templatePath = app()->getRootPath() . '.example.env';
        $baseContent = '';

        if (is_file($envPath)) {
            $baseContent = (string) file_get_contents($envPath);
        } elseif (is_file($templatePath)) {
            $baseContent = (string) file_get_contents($templatePath);
        }

        if ($baseContent === '') {
            throw new \RuntimeException('找不到可写入的环境配置模板');
        }

        foreach ($envData as $key => $value) {
            $quoted = $this->formatEnvValue($value);
            $pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';
            if (preg_match($pattern, $baseContent) === 1) {
                $baseContent = (string) preg_replace($pattern, $key . '=' . $quoted, $baseContent, 1);
            } else {
                $baseContent = rtrim($baseContent, "\n") . PHP_EOL . $key . '=' . $quoted . PHP_EOL;
            }
        }

        file_put_contents($envPath, $baseContent);
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
        Config::set($jwt, 'jwt');

        app()->delete('think\\DbManager');
        Db::connect(null, true);
    }

    private function createDatabase(array $config): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']);
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);

        $dbName = $config['name'];
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        return $pdo;
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

        $stmt = $pdo->prepare(
            "INSERT INTO `mb_admin` (`id`, `username`, `nickname`, `password`, `avatar`, `status`, `password_changed_at`, `create_time`, `update_time`)
             VALUES (1, :username, :nickname, :password, '', 1, NULL, :create_time, :update_time)
             ON DUPLICATE KEY UPDATE `username` = :username2, `password` = :password2, `password_changed_at` = NULL, `update_time` = :update_time2"
        );

        $stmt->execute([
            ':username'     => $username,
            ':nickname'     => '超级管理员',
            ':password'     => $hashedPassword,
            ':create_time'  => $now,
            ':update_time'  => $now,
            ':username2'    => $username,
            ':password2'    => $hashedPassword,
            ':update_time2' => $now,
        ]);
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
            $pdo->exec($sql);
        }
    }

    private function writeLockFile(): void
    {
        $content = json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'version'      => '1.0.0',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        file_put_contents($this->lockFilePath(), $content);
    }

    private function lockFilePath(): string
    {
        return root_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
    }

    private function installDataPath(string $subdir): string
    {
        $projectRoot = dirname(rtrim(root_path(), DIRECTORY_SEPARATOR));
        $deployPath = $projectRoot . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . $subdir;
        if (is_dir($deployPath)) {
            return $deployPath;
        }

        return root_path() . 'install' . DIRECTORY_SEPARATOR . 'data'
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

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|=|,|"|\'/', $value) === 1) {
            return '"' . addcslashes($value, "\"\\") . '"';
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(): array
    {
        $envPath = app()->getRootPath() . '.env';
        if (!is_file($envPath)) {
            return [];
        }

        $data = parse_ini_file($envPath, false, INI_SCANNER_RAW);
        if (!is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $key => $value) {
            $values[(string) $key] = is_string($value) ? trim($value) : (string) $value;
        }

        return $values;
    }
}
