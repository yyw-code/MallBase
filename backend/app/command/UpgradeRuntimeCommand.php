<?php

declare(strict_types=1);

namespace app\command;

use app\service\upgrade\SimpleUpgradeCliService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use Throwable;

final class UpgradeRuntimeCommand extends Command
{
    private const MAXIMUM_STDIN_BYTES = 8192;

    protected function configure(): void
    {
        $this->setName('upgrade:runtime')
            ->setDescription('执行固定的本地升级运行时 JSON 操作');
    }

    protected function execute(Input $input, Output $output): int
    {
        $stdin = stream_get_contents(STDIN, self::MAXIMUM_STDIN_BYTES + 1);
        if (!is_string($stdin)) {
            $stdin = '';
        }
        try {
            /** @var SimpleUpgradeCliService $service */
            $service = app()->make(SimpleUpgradeCliService::class);
            $result = $service->handle($stdin);
        } catch (Throwable) {
            $result = [
                'exit_code' => 1,
                'stdout' => '{"schema_version":1,"ok":false,"operation":null,"job_id":null,"data":null,"error":{"code":"UPGRADE_RUNTIME_FAILED"}}' . "\n",
            ];
        }
        $output->setDecorated(false);
        $output->write($result['stdout']);

        return $result['exit_code'];
    }
}
