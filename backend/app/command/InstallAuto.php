<?php

declare(strict_types=1);

namespace app\command;

use app\service\install\InstallService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

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
 * - 全部从进程 env 读取，不接受命令行参数
 * - 所需变量通常由 ensure-env 脚本写入 backend/.env，再由 ThinkPHP 运行时读取
 *
 * 使用示例：
 * ```bash
 * # 已启动 backend 容器后手动执行
 * PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
 * docker exec ${PREFIX}-dev php think install:auto
 * ```
 */
class InstallAuto extends Command
{
    protected function configure(): void
    {
        $this->setName('install:auto')
            ->setDescription('使用 env 中的连接信息调用统一安装主流程（可选 CLI 安装入口）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new InstallService();

        if ($service->isInstalled()) {
            $output->writeln('<info>[install:auto] install.lock 已存在，跳过</info>');
            return 0;
        }

        $output->writeln('<info>[install:auto] 开始自动安装…</info>');

        $params = $service->buildParamsFromEnv();

        $missing = [];
        foreach (['db_host', 'db_user', 'db_name', 'redis_host'] as $key) {
            if ($params[$key] === '' || $params[$key] === null) {
                $missing[] = strtoupper(str_replace('_', '_', $key));
            }
        }
        if ($missing !== []) {
            $output->writeln('<error>[install:auto] 缺少必要 env 变量：' . implode(', ', $missing) . '</error>');
            $output->writeln('<error>[install:auto] 请检查 ensure-env 是否已生成 backend/.env</error>');
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
            $result = $service->execute($params);
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

        $output->writeln('<info>[install:auto] done</info>');
        return 0;
    }
}
