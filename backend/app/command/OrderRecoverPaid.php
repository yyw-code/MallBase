<?php

declare(strict_types=1);

namespace app\command;

use app\model\order\PaymentLog;
use app\service\client\order\OrderService;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

/**
 * 微信支付主动查单 + 补单
 *
 * 适用场景：notify 丢失或处理异常，微信已支付但订单停留在 PENDING_PAY
 *
 * 用法：
 *  php think order:recover-paid 2605200000000007             # dry-run（默认）
 *  php think order:recover-paid 2605200000000007 --confirm   # 真实执行
 */
class OrderRecoverPaid extends Command
{
    protected function configure(): void
    {
        $this->setName('order:recover-paid')
            ->setDescription('微信查单 + 补单（notify 丢失时手动恢复）')
            ->addArgument('sn', Argument::REQUIRED, '订单 SN')
            ->addOption('confirm', null, Option::VALUE_NONE, '加此标志才真正执行补单，否则仅查单（dry-run）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $sn      = (string) $input->getArgument('sn');
        $dryRun  = !$input->getOption('confirm');

        $output->writeln(sprintf('<info>[%s] 开始处理 SN=%s %s</info>', date('H:i:s'), $sn, $dryRun ? '(dry-run)' : '(confirm)'));

        $prepay = PaymentLog::where('order_sn', $sn)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->order('id', 'desc')
            ->find();

        if ($prepay === null) {
            $output->writeln('<error>未找到该订单的 PREPAY 记录</error>');
            return 1;
        }

        $outTradeNo = (string) $prepay->out_trade_no;
        $output->writeln(sprintf('  out_trade_no: %s', $outTradeNo));
        $output->writeln(sprintf('  pay_method: %d, scene: %d, amount_cents: %d', $prepay->pay_method, $prepay->scene, $prepay->amount_cents));

        /** @var WechatPayFactory $factory */
        $factory = app()->make(WechatPayFactory::class);
        $app     = $factory->build();
        $mchId   = trim((string) getSystemSetting('pay_wechat_mchid', ''));

        /** @var WechatPayClient $client */
        $client = app()->make(WechatPayClient::class);

        try {
            $result = $client->queryByOutTradeNo($app, $mchId, $outTradeNo);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>微信查单失败: %s</error>', $e->getMessage()));
            return 1;
        }

        $tradeState    = (string) ($result['trade_state'] ?? '');
        $transactionId = (string) ($result['transaction_id'] ?? '');
        $amountTotal   = (int)    ($result['amount']['total'] ?? 0);

        $output->writeln(sprintf('  trade_state: %s', $tradeState));
        $output->writeln(sprintf('  transaction_id: %s', $transactionId));
        $output->writeln(sprintf('  amount.total: %d 分', $amountTotal));

        if ($tradeState !== 'SUCCESS') {
            $output->writeln(sprintf('<comment>微信侧状态为 %s，不执行补单</comment>', $tradeState));
            return 0;
        }

        if ($transactionId === '') {
            $output->writeln('<error>微信查单未返回 transaction_id，拒绝补单</error>');
            return 1;
        }

        if ($amountTotal !== (int) $prepay->amount_cents) {
            $output->writeln(sprintf(
                '<error>金额不一致！PREPAY=%d分, 微信=%d分，拒绝补单</error>',
                (int) $prepay->amount_cents,
                $amountTotal
            ));
            return 1;
        }

        $output->writeln('<info>  金额校验通过，微信已确认收款</info>');

        if ($dryRun) {
            $output->writeln('<comment>  [dry-run] 若要真正补单，请加 --confirm 参数</comment>');
            return 0;
        }

        /** @var OrderService $orderService */
        $orderService = app()->make(OrderService::class);

        try {
            [$ret, $paidLogCreated] = Db::transaction(function () use ($orderService, $prepay, $result, $transactionId, $tradeState, $sn): array {
                $paidLogCreated = $this->persistPaidLog($prepay, $result, $transactionId, $tradeState);

                $ret = $orderService->confirmPaid(
                    sn: $sn,
                    transactionId: $transactionId,
                    payMethod: (int) $prepay->pay_method,
                    payScene: (int) $prepay->scene,
                );

                return [$ret, $paidLogCreated];
            });
            $output->writeln($paidLogCreated ? '<info>  PAID 流水已补写</info>' : '<comment>  PAID 流水已存在，跳过重复写入</comment>');
            $output->writeln(sprintf('<info>  补单完成：order_id=%d, status=%d</info>', $ret['order_id'], $ret['status']));
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>补单失败: %s</error>', $e->getMessage()));
            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function persistPaidLog(PaymentLog $prepay, array $result, string $transactionId, string $tradeState): bool
    {
        $successTime = (string) ($result['success_time'] ?? '');
        $successTimestamp = $successTime !== '' ? strtotime($successTime) : false;
        $payerOpenid = (string) ($result['payer']['openid'] ?? $prepay->payer_openid ?? '');

        try {
            $paidLog = new PaymentLog();
            $paidLog->order_id       = (int) $prepay->order_id;
            $paidLog->order_sn       = (string) $prepay->order_sn;
            $paidLog->out_trade_no   = $this->derivedPaidOutTradeNo((string) $prepay->out_trade_no);
            $paidLog->transaction_id = $transactionId;
            $paidLog->pay_method     = (int) $prepay->pay_method;
            $paidLog->scene          = (int) $prepay->scene;
            $paidLog->event_type     = PaymentLog::EVENT_PAID;
            $paidLog->trade_state    = $tradeState;
            $paidLog->amount_cents   = (int) $prepay->amount_cents;
            $paidLog->payer_openid   = $payerOpenid !== '' ? $payerOpenid : $prepay->payer_openid;
            $paidLog->raw_notify     = $result;
            $paidLog->paid_at        = $successTimestamp !== false ? date('Y-m-d H:i:s', $successTimestamp) : date('Y-m-d H:i:s');
            $paidLog->save();
        } catch (Throwable $e) {
            if ($this->isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    private function derivedPaidOutTradeNo(string $original): string
    {
        return mb_substr($original . '#PAID', 0, 32);
    }

    private function isDuplicateKey(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry');
    }
}
