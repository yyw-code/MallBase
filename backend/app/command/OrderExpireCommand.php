<?php

declare(strict_types=1);

namespace app\command;

use app\admin\service\order\OrderAdminService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 扫描并关闭超时未支付订单
 *
 * 用法：
 *  php think order:expire                # 默认单次处理 500 条
 *  php think order:expire --limit=200    # 自定义批次大小
 *
 * 建议接入 crontab 每分钟跑一次：
 *  * * * * * cd /app && php think order:expire >> runtime/log/order-expire.log 2>&1
 *
 * 业务细节由 OrderAdminService::closeExpired 内部处理：
 *  - 每条订单独立事务，单条异常不中断整批
 *  - 状态机流转 CLOSED + 写 OrderLog + StockService::restoreBatch
 */
class OrderExpireCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('order:expire')
            ->setDescription('关闭超时未支付订单（status=PENDING_PAY 且 expire_at<now）')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '单次最大处理量，防止长事务', '500');
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));

        try {
            /** @var OrderAdminService $service */
            $service = app()->make(OrderAdminService::class);
            $result  = $service->closeExpired($limit);

            $output->writeln(sprintf(
                '<info>[%s] 超时关单扫描完成：扫描 %d 条，关闭 %d 条</info>',
                date('Y-m-d H:i:s'),
                $result['scanned'],
                $result['closed'],
            ));
            return 0;
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<error>[%s] 超时关单执行失败：%s</error>',
                date('Y-m-d H:i:s'),
                $e->getMessage(),
            ));
            return 1;
        }
    }
}
