<?php

declare(strict_types=1);

namespace app\command;

use app\service\RegionImportService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class ImportRegions extends Command
{
    protected function configure(): void
    {
        $this->setName('region:import')
            ->setDescription('导入中国省市区街道地区库')
            ->addOption('file', 'f', Option::VALUE_OPTIONAL, '地区 JSON 文件路径', root_path() . 'install' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'region' . DIRECTORY_SEPARATOR . 'pcas-code.json')
            ->addOption('truncate', 't', Option::VALUE_NONE, '导入前清空地区表');
    }

    protected function execute(Input $input, Output $output): int
    {
        $file = (string) $input->getOption('file');
        $truncate = (bool) $input->getOption('truncate');

        try {
            /** @var RegionImportService $service */
            $service = app()->make(RegionImportService::class);
            $imported = $service->importFromFile($file, $truncate);
            $output->writeln("<info>地区导入完成，共导入 {$imported} 条</info>");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>地区导入失败：{$e->getMessage()}</error>");
            return 1;
        }
    }
}
