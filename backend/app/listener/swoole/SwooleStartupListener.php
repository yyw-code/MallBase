<?php
declare (strict_types = 1);

namespace app\listener\swoole;

use think\facade\Config;

/**
 * Swoole 启动信息监听器
 */
class SwooleStartupListener
{
    public function handle()
    {
        $httpConfig = Config::get('swoole.http', []);
        $swooleConfig = Config::get('swoole', []);
        
        // 基本启动信息
        $environment = app()->isDebug() ? 'Development' : 'Production';
        $phpVersion = PHP_VERSION;
        $swooleVersion = swoole_version();
        $thinkphpVersion = \think\App::VERSION;
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
        echo "环境: {$environment}\n";
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
        echo "=========================================\n\n";
    }
}
