<?php

declare(strict_types=1);

namespace app\command;

use app\common\enum\RefundOrderStatus;
use app\model\order\RefundOrder;
use app\service\admin\order\RefundOrderAdminService;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 微信退款主动查询 + 补状态
 *
 * 适用场景：微信退款回调未配置或丢失，售后单停留在 REFUNDING。
 *
 * 用法：
 *  php think order:recover-refund R2605270000000001
 *  php think order:recover-refund R2605270000000001 --confirm
 */
class OrderRecoverRefund extends Command
{
    protected function configure(): void
    {
        $this->setName('order:recover-refund')
            ->setDescription('微信退款查单 + 补售后退款状态')
            ->addArgument('sn', Argument::REQUIRED, '售后单号')
            ->addOption('confirm', null, Option::VALUE_NONE, '加此标志才真正执行补状态，否则仅查单');
    }

    protected function execute(Input $input, Output $output): int
    {
        $sn = trim((string) $input->getArgument('sn'));
        $dryRun = !$input->getOption('confirm');

        $output->writeln(sprintf('<info>[%s] 开始处理退款 SN=%s %s</info>', date('H:i:s'), $sn, $dryRun ? '(dry-run)' : '(confirm)'));

        /** @var RefundOrder|null $refund */
        $refund = RefundOrder::where('sn', $sn)->whereNull('delete_time')->find();
        if ($refund === null) {
            $output->writeln('<error>未找到售后单</error>');
            return 1;
        }

        $output->writeln(sprintf('  local_status: %s(%d)', RefundOrderStatus::textOf((int) $refund->status), (int) $refund->status));
        $output->writeln(sprintf('  refund_amount: %s', (string) $refund->refund_amount));

        /** @var WechatPayFactory $factory */
        $factory = app()->make(WechatPayFactory::class);
        /** @var WechatPayClient $client */
        $client = app()->make(WechatPayClient::class);

        try {
            $result = $client->queryRefundByOutRefundNo($factory->build(), $sn);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>微信退款查询失败: %s</error>', $e->getMessage()));
            return 1;
        }

        $status = (string) ($result['status'] ?? '');
        $amount = (int) ($result['amount']['refund'] ?? $result['amount']['payer_refund'] ?? 0);
        $successTime = (string) ($result['success_time'] ?? '');

        $output->writeln(sprintf('  wechat_status: %s', $status));
        $output->writeln(sprintf('  refund_amount: %d 分', $amount));
        if ($successTime !== '') {
            $output->writeln(sprintf('  success_time: %s', $successTime));
        }

        if ($status !== 'SUCCESS') {
            $output->writeln(sprintf('<comment>微信侧状态为 %s，不执行补状态</comment>', $status !== '' ? $status : 'UNKNOWN'));
            return 0;
        }

        if ($dryRun) {
            $output->writeln('<comment>  [dry-run] 若要真正补状态，请加 --confirm 参数</comment>');
            return 0;
        }

        /** @var RefundOrderAdminService $refundService */
        $refundService = app()->make(RefundOrderAdminService::class);
        try {
            $refundService->completeWechatRefund($sn, $amount, $successTime);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>补状态失败: %s</error>', $e->getMessage()));
            return 1;
        }

        $output->writeln('<info>  补状态完成</info>');
        return 0;
    }
}
