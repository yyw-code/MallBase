<?php

declare(strict_types=1);

namespace app\command;

use app\service\upgrade\BootstrapRetentionFinalizeService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class UpgradeBootstrapRetentionFinalize extends Command
{
    protected function configure(): void
    {
        $this->setName('upgrade:bootstrap-retention-finalize')
            ->setDescription('完成隔离 bootstrap 保留数据的目标确认')
            ->addOption('retention-id', null, Option::VALUE_REQUIRED, 'bootstrap 保留操作 ID');
    }

    protected function execute(Input $input, Output $output): int
    {
        if ((string) getenv('MALLBASE_RUNTIME_ROLE') !== 'bootstrap-retention-finalize') {
            $output->writeln('BOOTSTRAP_RETENTION_TARGET_ROLE_INVALID');
            return 1;
        }
        try {
            $result = app()->make(BootstrapRetentionFinalizeService::class)->finalize(
                (string) $input->getOption('retention-id'),
            );
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            $output->writeln('BOOTSTRAP_RETENTION_TARGET_FINALIZE_FAILED');
            return 1;
        }

        return 0;
    }
}
