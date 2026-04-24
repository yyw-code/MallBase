<?php

declare(strict_types=1);

namespace app\service;

use app\model\region\Region;
use mall_base\base\BaseService;

/**
 * 地区数据导入服务
 *
 * @extends BaseService<Region>
 */
class RegionImportService extends BaseService
{
    protected string $modelClass = Region::class;

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

        return $this->transaction(function () use ($data, $truncate): int {
            if ($truncate) {
                $this->model()->whereRaw('1=1')->delete(true);
            }

            $imported = 0;
            $this->importNodes($data, 0, [], 1, $imported);

            return $imported;
        });
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

            $exists = $this->model()->where('code', $code)->find();
            if ($exists !== null) {
                $this->model()->where('id', (int) $exists->id)->update($record);
                $id = (int) $exists->id;
            } else {
                /** @var Region $created */
                $created = $this->model()->create($record);
                $id = (int) $created->id;
                $imported++;
            }

            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->importNodes($children, $id, $currentPathCodes, $level + 1, $imported);
            }
        }
    }
}
