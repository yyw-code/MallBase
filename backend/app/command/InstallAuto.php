<?php

declare(strict_types=1);

namespace app\command;

use app\service\install\InstallService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;

/**
 * 可选 CLI 安装入口命令
 *
 * 使用场景：
 * - 无人值守安装或本地手动执行
 * - 复用统一 InstallService 主流程，不单独维护第二套安装逻辑
 *
 * 幂等保证：
 * - install.lock 存在则直接退出 0，不重复执行安装
 *
 * 参数来源：
 * - 不接受命令行参数
 * - 优先读取当前进程 env，其次读取项目根 .env，最后兜底 backend/.env
 *
 * 使用示例：
 * ```bash
 * cd backend
 * php think install:auto
 * ```
 */
class InstallAuto extends Command
{
    /**
     * @var array<int, string>
     */
    private const INSTALL_ENV_KEYS = [
        'DB_TYPE',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'DB_CHARSET',
        'DB_PREFIX',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_CACHE_DB',
        'REDIS_PASSWORD',
        'CACHE_DRIVER',
        'SITE_URL',
        'SWOOLE_HTTP_PORT',
        'SWOOLE_WORKER_NUM',
    ];

    /**
     * @var array<int, string>
     */
    private const PROGRESS_STEPS = [
        'db_test',
        'redis_test',
        'write_env',
        'create_db',
        'import_sql',
        'create_admin',
        'import_demo',
        'copy_demo_static',
        'sync_permissions',
        'sync_setting_permissions',
        'import_regions',
        'seed_site_url',
        'verify_default_assets',
        'write_lock',
    ];

    protected function configure(): void
    {
        $this->setName('install:auto')
            ->setDescription('使用 env 中的连接信息调用统一安装主流程（可选 CLI 安装入口）')
            ->addOption('demo', null, Option::VALUE_NONE, '导入演示数据和演示静态资源');
    }

    protected function execute(Input $input, Output $output): int
    {
        $envSourceSummary = $this->installEnvSourceSummary();
        $this->bootstrapInstallEnv();

        $service = new InstallService();

        if ($service->isInstalled()) {
            $output->writeln('<info>[install:auto] install.lock 已存在，跳过</info>');
            return 0;
        }

        $output->writeln('<info>[install:auto] 开始自动安装…</info>');
        $output->writeln('<comment>[install:auto] env 来源：' . $envSourceSummary . '</comment>');

        $params = $service->buildParamsFromEnv();
        $params['import_demo'] = (bool) $input->getOption('demo');

        $missing = [];
        $requiredLabels = [
            'db_host'    => 'DB_HOST',
            'db_user'    => 'DB_USER',
            'db_name'    => 'DB_NAME',
            'redis_host' => 'REDIS_HOST',
            'site_url'   => 'SITE_URL',
        ];
        foreach ($requiredLabels as $key => $label) {
            if ($params[$key] === '' || $params[$key] === null) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $output->writeln('<error>[install:auto] 缺少必要 env 变量：' . implode(', ', $missing) . '</error>');
            $output->writeln('<error>[install:auto] 请检查项目根 .env 或 backend/.env 是否包含安装所需配置</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '<comment>[install:auto] DB=%s@%s:%s/%s Redis=%s:%s Admin=%s Demo=%s</comment>',
            $params['db_user'],
            $params['db_host'],
            $params['db_port'],
            $params['db_name'],
            $params['redis_host'],
            $params['redis_port'],
            $params['admin_user'],
            $params['import_demo'] ? 'yes' : 'no'
        ));

        try {
            $result = $service->execute($params, function (array $event) use ($output): void {
                $line = $this->formatProgressEvent($event);
                if ($line !== null) {
                    $output->writeln($line);
                }
            });
        } catch (\Throwable $e) {
            $output->writeln('<error>[install:auto] 执行异常: ' . $e->getMessage() . '</error>');
            return 1;
        }

        if (empty($result['success'])) {
            $step = $result['step'] ?? 'unknown';
            $message = $result['message'] ?? '未知错误';
            $output->writeln("<error>[install:auto] 失败（step={$step}）: {$message}</error>");
            return 1;
        }

        $this->printSuccessSummary($params, $output);
        return 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function printSuccessSummary(array $params, Output $output): void
    {
        $siteUrl = rtrim((string) ($params['site_url'] ?? ''), '/');
        $adminUrl = $siteUrl !== '' ? $siteUrl . '/admin/' : '';
        $clientUrl = $siteUrl !== '' ? $siteUrl . '/client/' : '';
        $demoStatus = !empty($params['import_demo']) ? '已安装' : '未安装';

        $output->writeln('<info>[install:auto] 安装完成</info>');
        $output->writeln('<info>[install:auto] 基本信息：</info>');
        $output->writeln('<info>[install:auto] - 管理员账号：' . (string) ($params['admin_user'] ?? '') . '</info>');
        $output->writeln('<info>[install:auto] - 管理员密码：' . (string) ($params['admin_pass'] ?? '') . '</info>');
        $output->writeln('<info>[install:auto] - 演示数据：' . $demoStatus . '</info>');
        $output->writeln('<info>[install:auto] - 站点地址：' . $siteUrl . '</info>');
        if ($adminUrl !== '') {
            $output->writeln('<info>[install:auto] - 管理后台：' . $adminUrl . '</info>');
        }
        if ($clientUrl !== '') {
            $output->writeln('<info>[install:auto] - 客户端入口：' . $clientUrl . '</info>');
        }
        $output->writeln(sprintf(
            '<info>[install:auto] - 数据库：%s@%s:%s/%s</info>',
            (string) ($params['db_user'] ?? ''),
            (string) ($params['db_host'] ?? ''),
            (string) ($params['db_port'] ?? ''),
            (string) ($params['db_name'] ?? '')
        ));
        $output->writeln(sprintf(
            '<info>[install:auto] - Redis：%s:%s DB %s</info>',
            (string) ($params['redis_host'] ?? ''),
            (string) ($params['redis_port'] ?? ''),
            (string) ($params['redis_db'] ?? '')
        ));
        $output->writeln('<comment>[install:auto] 安装完成后请尽快修改默认管理员密码。</comment>');
        $output->writeln('<comment>[install:auto] 安装完成后请重启 Swoole，让新配置和安装锁生效。</comment>');
    }

    /**
     * @param array<string, mixed> $event
     */
    private function formatProgressEvent(array $event): ?string
    {
        if (($event['event'] ?? '') === 'complete') {
            return null;
        }

        $step = (string) ($event['step'] ?? '');
        if ($step === '') {
            return null;
        }

        $position = array_search($step, self::PROGRESS_STEPS, true);
        $index = $position === false ? '--' : str_pad((string) ($position + 1), 2, '0', STR_PAD_LEFT);
        $total = str_pad((string) count(self::PROGRESS_STEPS), 2, '0', STR_PAD_LEFT);
        $status = $this->progressStatusLabel((string) ($event['status'] ?? ''));
        $title = (string) ($event['title'] ?? $step);
        $message = (string) ($event['message'] ?? '');

        return sprintf('[install:auto] [%s/%s] %s %s：%s', $index, $total, $status, $title, $message);
    }

    private function progressStatusLabel(string $status): string
    {
        return match ($status) {
            'running' => '进行中',
            'success' => '完成',
            'warning' => '提醒',
            'skipped' => '跳过',
            'error' => '失败',
            default => $status !== '' ? $status : '状态未知',
        };
    }

    private function bootstrapInstallEnv(): void
    {
        $backendRoot = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($backendRoot);
        $rootValues = $this->parseEnvFile($projectRoot . DIRECTORY_SEPARATOR . '.env');
        $backendValues = $this->parseEnvFile($backendRoot . DIRECTORY_SEPARATOR . '.env');
        $inContainer = is_file('/.dockerenv');

        $values = [];
        foreach (self::INSTALL_ENV_KEYS as $key) {
            $value = $this->resolveInstallEnvValue($key, $rootValues, $backendValues, $inContainer);
            if ($value === '') {
                continue;
            }

            $values[$key] = $value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        if ($values !== []) {
            app()->env->set($values);
            $this->applyRuntimeConfig($values);
        }
    }

    private function installEnvSourceSummary(): string
    {
        $backendRoot = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($backendRoot);
        $sources = [];

        if ($this->processEnvHasInstallValues()) {
            $sources[] = '当前进程 env';
        }

        if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env')) {
            $sources[] = '项目根 .env';
        }

        if (is_file($backendRoot . DIRECTORY_SEPARATOR . '.env')) {
            $sources[] = 'backend/.env';
        }

        if ($sources === []) {
            return '未发现安装 env 文件，将仅使用主机和端口默认值';
        }

        return implode('、', $sources);
    }

    private function processEnvHasInstallValues(): bool
    {
        foreach (self::INSTALL_ENV_KEYS as $key) {
            $value = getenv($key);
            if ($value !== false && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $values
     */
    private function applyRuntimeConfig(array $values): void
    {
        $database = Config::get('database', []);
        $database['default'] = 'mysql';
        $database['connections']['mysql'] = array_merge($database['connections']['mysql'] ?? [], [
            'type'     => $values['DB_TYPE'] ?? 'mysql',
            'hostname' => $values['DB_HOST'] ?? '127.0.0.1',
            'database' => $values['DB_NAME'] ?? '',
            'username' => $values['DB_USER'] ?? 'root',
            'password' => $values['DB_PASS'] ?? '',
            'hostport' => $values['DB_PORT'] ?? '3306',
            'charset'  => $values['DB_CHARSET'] ?? 'utf8mb4',
            'prefix'   => $values['DB_PREFIX'] ?? 'mb_',
        ]);
        Config::set($database, 'database');

        $cache = Config::get('cache', []);
        $cache['default'] = $values['CACHE_DRIVER'] ?? 'redis';
        $cache['stores']['redis'] = array_merge($cache['stores']['redis'] ?? [], [
            'type'       => 'redis',
            'host'       => $values['REDIS_HOST'] ?? '127.0.0.1',
            'port'       => (int) ($values['REDIS_PORT'] ?? 6379),
            'password'   => $values['REDIS_PASSWORD'] ?? '',
            'select'     => (int) ($values['REDIS_CACHE_DB'] ?? 0),
            'timeout'    => 0,
            'persistent' => false,
            'prefix'     => '',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ]);
        Config::set($cache, 'cache');
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

    /**
     * @param array<string, string> $rootValues
     * @param array<string, string> $backendValues
     */
    private function resolveInstallEnvValue(string $name, array $rootValues, array $backendValues, bool $inContainer): string
    {
        $processValue = getenv($name);
        if ($processValue !== false && trim((string) $processValue) !== '') {
            return trim((string) $processValue);
        }

        if ($name === 'DB_HOST') {
            return $this->resolveInstallHostValue(
                $rootValues[$name] ?? '',
                $backendValues[$name] ?? '',
                '127.0.0.1',
                $inContainer,
                $rootValues !== []
            );
        }

        if ($name === 'REDIS_HOST') {
            return $this->resolveInstallHostValue(
                $rootValues[$name] ?? '',
                $backendValues[$name] ?? '',
                '127.0.0.1',
                $inContainer,
                $rootValues !== []
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

    private function resolveInstallHostValue(
        string $rootValue,
        string $backendValue,
        string $default,
        bool $inContainer,
        bool $hasRootEnv
    ): string {
        $rootValue = trim($rootValue);
        if ($rootValue !== '') {
            if (!$inContainer && in_array($rootValue, ['mysql', 'redis'], true)) {
                return $default;
            }

            return $rootValue;
        }

        if (!$inContainer && $hasRootEnv) {
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
}
