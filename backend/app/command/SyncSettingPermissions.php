<?php

/**
 * 设置模块权限同步命令
 *
 * 功能说明：
 * - 以 mb_setting_group 为真相源，修复/补齐派生的设置菜单权限
 * - 调用 SettingService::rebuildAllPermissions() 处理未同步分组、悬空 permission_id 与父子层级修复
 * - 幂等：重复执行不会重复插入已有权限
 *
 * 使用场景：
 * - 安装后的手动补同步
 * - Docker 开发全套 / CI 环境初始化后的修复
 * - seed 数据新增后手动补齐菜单
 * - 修复误删 permission 的情况
 *
 * 使用示例：
 * ```bash
 * php think settings:sync-permissions
 * ```
 */

declare(strict_types=1);

namespace app\command;

use app\service\admin\setting\SettingService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class SyncSettingPermissions extends Command
{
    protected function configure(): void
    {
        $this->setName('settings:sync-permissions')
            ->setDescription('修复/补齐设置分组菜单权限（幂等）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('<info>开始修复设置分组菜单权限...</info>');

        /** @var SettingService $service */
        $service = app()->make(SettingService::class);

        $startTime = microtime(true);

        try {
            $created = $service->rebuildAllPermissions();
        } catch (\Throwable $e) {
            $output->writeln('<error>修复失败：' . $e->getMessage() . '</error>');
            $output->writeln('<error>位置：' . $e->getFile() . ':' . $e->getLine() . '</error>');
            return 1;
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $output->writeln('<info>修复完成。新建权限条数：' . $created . '，耗时：' . $duration . 'ms</info>');

        return 0;
    }
}
