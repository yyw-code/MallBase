<?php

declare(strict_types=1);

namespace app\service\admin\demo;

use app\service\RegionImportService;
use app\service\admin\setting\SettingService;
use app\service\cache\PermissionCacheService;
use app\service\cache\SettingCacheService;
use mall_base\base\BaseModel;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use PDO;
use think\facade\Cache;
use think\facade\Console;
use think\facade\Config;

/**
 * 演示站数据恢复服务
 *
 * @extends BaseService<BaseModel>
 */
class DemoResetService extends BaseService
{
    protected string $modelClass = BaseModel::class;

    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_PASSWORD = 'admin123';

    /**
     * 恢复演示站数据到安装演示状态。
     *
     * @return array<string, mixed>
     */
    public function reset(): array
    {
        $startedAt = time();
        $pdo = $this->pdo();

        try {
            $this->importSqlDir($pdo, $this->installDataPath('schema'));
            $this->resetDemoAdmin($pdo);
            $this->importSqlDir($pdo, $this->installDataPath('demo'), required: false);
            $staticResult = $this->copyDemoStatics();

            Console::call('sync:permissions');
            app()->make(SettingService::class)->rebuildAllPermissions();

            $regionFile = $this->installDataPath('region') . DIRECTORY_SEPARATOR . 'pcas-code.json';
            $regions = app()->make(RegionImportService::class)->importFromFile($regionFile);

            $this->clearRuntimeCache();

            return [
                'admin_username' => self::ADMIN_USERNAME,
                'duration' => time() - $startedAt,
                'regions' => $regions,
                'static' => $staticResult,
            ];
        } catch (\Throwable $e) {
            throw new BusinessException('恢复演示数据失败：' . $e->getMessage());
        }
    }

    private function pdo(): PDO
    {
        $config = Config::get('database.connections.mysql', []);
        $database = trim((string) ($config['database'] ?? ''));
        if ($database === '') {
            throw new \RuntimeException('数据库名为空，请先完成安装或检查 backend/.env');
        }

        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string) ($config['hostname'] ?? '127.0.0.1'),
            (string) ($config['hostport'] ?? '3306'),
            $database,
            $charset
        );

        return new PDO($dsn, (string) ($config['username'] ?? 'root'), (string) ($config['password'] ?? ''), [
            PDO::ATTR_TIMEOUT            => 5,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ]);
    }

    private function importSqlDir(PDO $pdo, string $dir, bool $required = true): void
    {
        if (!is_dir($dir)) {
            if ($required) {
                throw new \RuntimeException("SQL 目录不存在：{$dir}");
            }
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false || $files === []) {
            if ($required) {
                throw new \RuntimeException("SQL 目录下未找到 .sql 文件：{$dir}");
            }
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

    private function resetDemoAdmin(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $password = password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "UPDATE `mb_admin`
             SET `username` = :username,
                 `nickname` = '演示管理员',
                 `password` = :password,
                 `status` = 1,
                 `password_changed_at` = :password_changed_at,
                 `update_time` = :update_time
             WHERE `id` = 1"
        );
        $stmt->execute([
            ':username' => self::ADMIN_USERNAME,
            ':password' => $password,
            ':password_changed_at' => $now,
            ':update_time' => $now,
        ]);
    }

    /**
     * @return array{copied:int,overwritten:int,source_missing:bool,errors:array<int,string>}
     */
    private function copyDemoStatics(): array
    {
        $result = [
            'copied' => 0,
            'overwritten' => 0,
            'source_missing' => false,
            'errors' => [],
        ];

        $sourceDir = $this->installStaticPath('demo');
        if (!is_dir($sourceDir)) {
            $result['source_missing'] = true;
            return $result;
        }

        $targetDir = rtrim(public_path(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'demo';
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
            $target = $targetDir . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    $result['errors'][] = '目录创建失败：' . $target;
                }
                continue;
            }

            $targetParent = dirname($target);
            if (!is_dir($targetParent) && !mkdir($targetParent, 0755, true) && !is_dir($targetParent)) {
                $result['errors'][] = '目录创建失败：' . $targetParent;
                continue;
            }

            $exists = is_file($target);
            if (!copy($item->getPathname(), $target)) {
                $result['errors'][] = '文件拷贝失败：' . $relative;
                continue;
            }

            $exists ? $result['overwritten']++ : $result['copied']++;
        }

        return $result;
    }

    private function clearRuntimeCache(): void
    {
        app()->make(SettingCacheService::class)->clearAll();
        app()->make(PermissionCacheService::class)->clearAll();

        try {
            Cache::clear();
        } catch (\Throwable) {
            // 部分缓存驱动不支持全量清理时，前面的业务缓存清理已经覆盖关键路径。
        }
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

    private function installStaticPath(string $subdir): string
    {
        $projectRoot = dirname(rtrim(root_path(), DIRECTORY_SEPARATOR));
        $deployPath = $projectRoot . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'static'
            . DIRECTORY_SEPARATOR . $subdir;
        if (is_dir($deployPath)) {
            return $deployPath;
        }

        return root_path() . 'install' . DIRECTORY_SEPARATOR . 'static'
            . DIRECTORY_SEPARATOR . $subdir;
    }
}
