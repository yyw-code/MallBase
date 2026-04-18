<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

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

        if (!is_file($file)) {
            $output->writeln("<error>地区数据文件不存在：{$file}</error>");
            return 1;
        }

        $json = file_get_contents($file);
        $data = json_decode($json ?: '', true);
        if (!is_array($data)) {
            $output->writeln('<error>地区数据文件格式错误</error>');
            return 1;
        }

        Db::startTrans();
        try {
            if ($truncate) {
                Db::name('region')->delete(true);
            }

            $imported = 0;
            $this->importNodes($data, 0, [], 1, $imported);

            Db::commit();
            $output->writeln("<info>地区导入完成，共导入 {$imported} 条</info>");
            return 0;
        } catch (\Throwable $e) {
            Db::rollback();
            $output->writeln("<error>地区导入失败：{$e->getMessage()}</error>");
            return 1;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, string> $pathCodes
     */
    protected function importNodes(array $nodes, int $parentId, array $pathCodes, int $level, int &$imported): void
    {
        foreach ($nodes as $index => $node) {
            $code = (string) ($node['code'] ?? '');
            $name = (string) ($node['name'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }

            $currentPathCodes = array_merge($pathCodes, [$code]);
            $record = [
                'parent_id' => $parentId,
                'code' => $code,
                'name' => $name,
                'level' => $level,
                'path_codes' => implode(',', $currentPathCodes),
                'status' => 1,
                'sort' => $index,
            ];

            $exists = Db::name('region')->where('code', $code)->find();
            if ($exists) {
                Db::name('region')->where('id', $exists['id'])->update($record);
                $id = (int) $exists['id'];
            } else {
                $id = (int) Db::name('region')->insertGetId($record);
                $imported++;
            }

            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->importNodes($children, $id, $currentPathCodes, $level + 1, $imported);
            }
        }
    }
}
