<?php

declare(strict_types=1);

namespace app\command;

use app\service\upgrade\StorageCutoverTargetGateSnapshotService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class StorageCutoverTargetSnapshot extends Command
{
    protected function configure(): void
    {
        $this->setName('upgrade:storage-cutover-target-snapshot')
            ->setDescription('输出隔离目标校验所需的维护闸门快照')
            ->addOption('job-id', null, Option::VALUE_REQUIRED, '升级任务 ID');
    }

    protected function execute(Input $input, Output $output): int
    {
        if ((string) getenv('MALLBASE_RUNTIME_ROLE') !== 'target-verify') {
            $output->writeln('STORAGE_CUTOVER_TARGET_ROLE_INVALID');
            return 1;
        }
        try {
            $snapshot = app()->make(StorageCutoverTargetGateSnapshotService::class)->snapshot(
                (string) $input->getOption('job-id'),
            );
            $output->writeln(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            $output->writeln('STORAGE_CUTOVER_TARGET_SNAPSHOT_FAILED');
            return 1;
        }

        return 0;
    }
}
