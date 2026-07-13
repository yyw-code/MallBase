<?php
declare (strict_types=1);

namespace app\listener\swoole;

use app\process\UpgradeRuntimeHeartbeatProcess;
use app\service\upgrade\UpgradeRuntimeLifecycle;
use Composer\InstalledVersions;
use RuntimeException;
use Swoole\Event;
use Swoole\Timer;
use think\facade\Config;
use think\swoole\Manager;
use Throwable;

/**
 * Swoole 启动信息监听器
 */
class SwooleStartupListener
{
    public function handle(Manager $manager): void
    {
        $httpConfig = Config::get('swoole.http', []);
        $swooleConfig = Config::get('swoole', []);
        [$upgradeRuntimeActive, $upgradeRuntimeStatus] = $this->configureUpgradeRuntime(
            $manager,
            $swooleConfig['upgrade_runtime'] ?? [],
        );

        // 基本启动信息
        $APP_DEBUG = app()->isDebug() ? 'Development' : 'Production';
        $phpVersion = PHP_VERSION;
        $swooleVersion = swoole_version();
        $thinkphpVersion = InstalledVersions::getPrettyVersion('topthink/framework');
        $workerNum = $httpConfig['worker_num'] ?? swoole_cpu_num();
        $host = $httpConfig['host'] ?? '0.0.0.0';
        $port = $httpConfig['port'] ?? 8080;

        // 功能开关
        $features = [];
        if ($httpConfig['enable'] ?? false) {
            $features[] = 'HTTP Server';
        }
        if ($swooleConfig['websocket']['enable'] ?? false) {
            $features[] = 'WebSocket';
        }
        if ($swooleConfig['rpc']['server']['enable'] ?? false) {
            $features[] = 'RPC Server';
        }
        if ($swooleConfig['queue']['enable'] ?? false) {
            $features[] = 'Queue Worker';
        }
        if ($upgradeRuntimeActive) {
            $features[] = 'Upgrade Runtime Heartbeat';
        }

        // 连接池配置
        $poolConfig = $swooleConfig['pool'] ?? [];
        $pools = [];
        if (!empty($poolConfig['db']['enable'] ?? false)) {
            $pools[] = sprintf('DB (max_active=%d)', $poolConfig['db']['max_active'] ?? 3);
        }
        if (!empty($poolConfig['cache']['enable'] ?? false)) {
            $pools[] = sprintf('Cache (max_active=%d)', $poolConfig['cache']['max_active'] ?? 3);
        }

        // 其他配置
        $hotUpdate = $swooleConfig['hot_update']['enable'] ?? false;
        $ipcType = $swooleConfig['ipc']['type'] ?? 'unix_socket';

        // 输出启动信息
        echo "\n";
        echo "=========================================\n";
        echo "     Swoole 服务启动成功\n";
        echo "=========================================\n";
        echo "APP_DEBUG: {$APP_DEBUG}\n";
        echo "PHP 版本: {$phpVersion}\n";
        echo "Swoole 版本: {$swooleVersion}\n";
        echo "ThinkPHP 版本: {$thinkphpVersion}\n";
        echo "Worker 数量: {$workerNum}\n";
        echo "监听地址: {$host}:{$port}\n";

        if (!empty($features)) {
            echo "已启用功能: " . implode(', ', $features) . "\n";
        }

        if (!empty($pools)) {
            echo "连接池: " . implode(', ', $pools) . "\n";
        }

        echo "热更新: " . ($hotUpdate ? '启用' : '禁用') . "\n";
        echo "IPC 类型: {$ipcType}\n";
        echo "自动升级运行设施: {$upgradeRuntimeStatus}\n";
        echo "=========================================\n\n";
    }

    /**
     * @param array<string,mixed> $configuration
     * @return array{bool,string}
     */
    private function configureUpgradeRuntime(Manager $manager, array $configuration): array
    {
        $enabled = ($configuration['enable'] ?? false) === true;
        $blocksCommercialStartup = ($configuration['failure_blocks_commercial_startup'] ?? false) === true;
        $interval = $configuration['heartbeat_interval_milliseconds'] ?? null;
        if (!$enabled) {
            return [false, '禁用（未安装或配置关闭）'];
        }
        if ($interval !== 5000) {
            throw new RuntimeException('UPGRADE_RUNTIME_HEARTBEAT_INTERVAL_INVALID');
        }
        if (!app()->bound(UpgradeRuntimeLifecycle::class)) {
            if ($blocksCommercialStartup) {
                throw new RuntimeException('UPGRADE_RUNTIME_LIFECYCLE_UNAVAILABLE');
            }
            fwrite(STDERR, "[MallBase Upgrade] 未绑定运行生命周期服务，自动升级已禁用；商业服务继续启动。\n");
            defined('MALLBASE_AUTOMATIC_UPGRADE_DISABLED')
                || define('MALLBASE_AUTOMATIC_UPGRADE_DISABLED', true);

            return [false, '不可用（自动升级已禁用，商业服务继续）'];
        }

        $manager->addWorker(function () use ($manager, $blocksCommercialStartup): void {
            try {
                $application = $manager->getApplication();
                if ($application === null || !$application->bound(UpgradeRuntimeLifecycle::class)) {
                    throw new RuntimeException('UPGRADE_RUNTIME_LIFECYCLE_UNAVAILABLE');
                }
                $application->make(UpgradeRuntimeHeartbeatProcess::class)->run($blocksCommercialStartup);
            } catch (Throwable $exception) {
                fwrite(STDERR, "[MallBase Upgrade] 独立运行心跳进程不可用，自动升级已禁用。\n");
                if ($blocksCommercialStartup) {
                    throw new RuntimeException('UPGRADE_RUNTIME_HEARTBEAT_PROCESS_UNAVAILABLE', 0, $exception);
                }

                Timer::tick(60_000, static function (): void {
                });
                Event::wait();
            }
        }, 'upgrade runtime heartbeat');

        return [true, '启用（独立 5 秒心跳进程）'];
    }
}
