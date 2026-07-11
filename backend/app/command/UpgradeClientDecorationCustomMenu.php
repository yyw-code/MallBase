<?php

declare(strict_types=1);

namespace app\command;

use app\service\admin\client\ClientDecorationSchemeService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 收敛历史个人中心 customMenu 装修组件。
 *
 * 使用示例：
 * php think upgrade:client-decoration-custom-menu
 * php think upgrade:client-decoration-custom-menu --confirm
 */
class UpgradeClientDecorationCustomMenu extends Command
{
    protected function configure(): void
    {
        $this->setName('upgrade:client-decoration-custom-menu')
            ->setDescription('收敛历史个人中心 customMenu 装修组件为 serviceMenu')
            ->addOption('confirm', null, Option::VALUE_NONE, '加此标志才真正写入，否则仅预览（dry-run）');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $dryRun = !$input->getOption('confirm');
            $result = app()->make(ClientDecorationSchemeService::class)
                ->migrateLegacyProfileCustomMenu($dryRun);
            $output->info(sprintf(
                '%s：扫描 %d 个方案，%s %d 个，跳过 %d 个',
                $dryRun ? '预览完成' : '升级完成',
                $result['scanned'],
                $dryRun ? '可更新' : '更新',
                $result['updated'],
                $result['skipped']
            ));
            if ($dryRun) {
                $output->comment('确认无误后，添加 --confirm 参数执行写入');
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error('升级失败：' . $e->getMessage());
            return 1;
        }
    }
}
