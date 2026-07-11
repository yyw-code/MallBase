<?php

declare(strict_types=1);

namespace app\command;

use app\service\user\UserPointsAccountService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 释放到期冻结积分
 */
class PointsReleaseCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('points:release')
            ->setDescription('释放到期冻结积分')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '单次最大处理量', '500');
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, min(2000, (int) $input->getOption('limit')));

        try {
            $result = app()->make(UserPointsAccountService::class)->releaseDueRewards($limit);
            $output->writeln(sprintf(
                '<info>[%s] 冻结积分释放完成：扫描 %d 条，释放 %d 积分</info>',
                date('Y-m-d H:i:s'),
                $result['scanned'],
                $result['released'],
            ));
            return 0;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>冻结积分释放失败：%s</error>', $e->getMessage()));
            return 1;
        }
    }
}
