<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class RegionImportService
{
    /**
     * 从 JSON 文件导入地区数据
     *
     * @throws \RuntimeException
     */
    public function importFromFile(string $file, bool $truncate = false): int
    {
        if (!is_file($file)) {
            throw new \RuntimeException("地区数据文件不存在：{$file}");
        }

        $json = file_get_contents($file);
        $data = json_decode($json ?: '', true);
        if (!is_array($data)) {
            throw new \RuntimeException('地区数据文件格式错误');
        }

        Db::startTrans();
        try {
            if ($truncate) {
                Db::name('region')->delete(true);
            }

            $imported = 0;
            $this->importNodes($data, 0, [], 1, $imported);

            Db::commit();

            return $imported;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
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
