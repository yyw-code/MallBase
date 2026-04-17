<?php

declare(strict_types=1);

namespace app\install\service;

use PDO;
use PDOException;
use Redis;
use RedisException;

class InstallService
{
    public function isInstalled(): bool
    {
        return file_exists($this->lockFilePath());
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

            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $pdo = null;

            return [
                'success'   => true,
                'version'   => $version,
                'db_exists'  => $dbExists,
                'message'   => $dbExists ? '连接成功，数据库已存在' : '连接成功，数据库不存在（将自动创建）',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => '连接失败: ' . $e->getMessage(),
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
                (int)($config['port'] ?? 6379),
                3.0
            );

            if (!$connected) {
                return ['success' => false, 'message' => '连接失败'];
            }

            $password = $config['password'] ?? '';
            if ($password !== '') {
                $redis->auth($password);
            }

            $pong = $redis->ping();
            $info = $redis->info('server');
            $redis->close();

            return [
                'success' => true,
                'version' => $info['redis_version'] ?? 'unknown',
                'message' => '连接成功',
            ];
        } catch (RedisException $e) {
            return [
                'success' => false,
                'message' => '连接失败: ' . $e->getMessage(),
            ];
        }
    }

    public function execute(array $params): array
    {
        $dbConfig = [
            'host' => $params['db_host'],
            'port' => $params['db_port'] ?? 3306,
            'user' => $params['db_user'],
            'pass' => $params['db_pass'],
            'name' => $params['db_name'],
        ];

        $redisConfig = [
            'host'     => $params['redis_host'],
            'port'     => $params['redis_port'] ?? 6379,
            'password' => $params['redis_password'] ?? '',
        ];

        $dbTest = $this->testDatabase($dbConfig);
        if (!$dbTest['success']) {
            return ['success' => false, 'step' => 'db_connect', 'message' => $dbTest['message']];
        }

        $redisTest = $this->testRedis($redisConfig);
        if (!$redisTest['success']) {
            return ['success' => false, 'step' => 'redis_connect', 'message' => $redisTest['message']];
        }

        $jwtSecret = base64_encode(random_bytes(48));
        $corsOrigins = $params['cors_origins'] ?? '*';

        $this->writeEnvFile($dbConfig, $redisConfig, $jwtSecret, $corsOrigins);

        try {
            $pdo = $this->createDatabase($dbConfig);
        } catch (PDOException $e) {
            return ['success' => false, 'step' => 'create_db', 'message' => $e->getMessage()];
        }

        try {
            $this->importSqlFiles($pdo);
        } catch (\Throwable $e) {
            return ['success' => false, 'step' => 'import_sql', 'message' => $e->getMessage()];
        }

        try {
            $this->createSuperAdmin($pdo, $params['admin_user'], $params['admin_pass']);
        } catch (\Throwable $e) {
            return ['success' => false, 'step' => 'create_admin', 'message' => $e->getMessage()];
        }

        if (!empty($params['import_demo'])) {
            try {
                $this->importDemoData($pdo);
            } catch (\Throwable $e) {
                // 演示数据导入失败不阻断安装
            }
        }

        $pdo = null;

        $this->writeLockFile();

        return ['success' => true, 'message' => '安装完成'];
    }

    private function writeEnvFile(array $db, array $redis, string $jwtSecret, string $corsOrigins = '*'): void
    {
        $envPath = app()->getRootPath() . '.env';

        $content = <<<ENV
APP_DEBUG = false
CRON_ENABLE = false

DB_TYPE = mysql
DB_HOST = {$db['host']}
DB_NAME = {$db['name']}
DB_USER = {$db['user']}
DB_PASS = {$db['pass']}
DB_PORT = {$db['port']}
DB_CHARSET = utf8mb4
DB_PREFIX = mb_

DEFAULT_LANG = zh-cn

CACHE_DRIVER = redis
CACHE_PREFIX =
CACHE_EXPIRE = 0
CACHE_TAG_PREFIX = tag:

REDIS_HOST = {$redis['host']}
REDIS_PORT = {$redis['port']}
REDIS_PASSWORD = {$redis['password']}
REDIS_TIMEOUT = 0
REDIS_PERSISTENT = false
REDIS_CACHE_DB = 0

JWT_SECRET = {$jwtSecret}

SWOOLE_HTTP_HOST = 0.0.0.0
SWOOLE_HTTP_PORT = 8080
SWOOLE_WORKER_NUM = 0
SWOOLE_MAX_REQUEST = 2000
SWOOLE_RELOAD_ASYNC = true
SWOOLE_MAX_WAIT_TIME = 60
SWOOLE_HEARTBEAT_IDLE_TIME = 120
SWOOLE_HEARTBEAT_CHECK_INTERVAL = 60
SWOOLE_POOL_MAX_WAIT_TIME = 5
SWOOLE_DB_POOL_MAX_ACTIVE = 3
SWOOLE_CACHE_POOL_MAX_ACTIVE = 3
SWOOLE_REDIS_POOL_MAX_ACTIVE = 3

CORS_ALLOWED_ORIGINS = {$corsOrigins}
CORS_ALLOW_METHODS = GET,POST,PUT,DELETE,OPTIONS
CORS_ALLOW_HEADERS = Authorization,Content-Type,X-Requested-With

LOG_SINGLE = false
LOG_MAX_FILES = 30
ENV;

        file_put_contents($envPath, $content);
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
        $sqlDir = app_path('install' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'schema');

        $files = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql');
        sort($files);

        foreach ($files as $filePath) {
            $sql = file_get_contents($filePath);
            $pdo->exec($sql);
        }
    }

    private function createSuperAdmin(PDO $pdo, string $username, string $password): void
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            "INSERT INTO `mb_admin` (`id`, `username`, `nickname`, `password`, `avatar`, `status`, `create_time`, `update_time`)
             VALUES (1, :username, :nickname, :password, '', 1, :create_time, :update_time)
             ON DUPLICATE KEY UPDATE `username` = :username2, `password` = :password2, `update_time` = :update_time2"
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
        $demoDir = app_path('install' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'demo');

        if (!is_dir($demoDir)) {
            return;
        }

        $files = glob($demoDir . DIRECTORY_SEPARATOR . '*.sql');
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if (!empty(trim($sql))) {
                $pdo->exec($sql);
            }
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
        return app()->getRootPath() . 'install.lock';
    }
}
