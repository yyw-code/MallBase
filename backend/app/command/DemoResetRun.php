<?php

declare(strict_types=1);

namespace app\command;

use app\service\admin\demo\DemoResetService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class DemoResetRun extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:reset-run')
            ->setDescription('执行演示站数据恢复任务')
            ->addOption('job-id', null, Option::VALUE_REQUIRED, '恢复任务 ID');
    }

    protected function execute(Input $input, Output $output): int
    {
        $jobId = trim((string) $input->getOption('job-id'));
        if ($jobId === '') {
            $output->writeln('<error>缺少 job-id</error>');
            return 1;
        }

        /** @var DemoResetService $service */
        $service = app()->make(DemoResetService::class);

        try {
            $service->runQueuedReset($jobId);
            $output->writeln('<info>演示数据恢复完成</info>');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>演示数据恢复失败：' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}
