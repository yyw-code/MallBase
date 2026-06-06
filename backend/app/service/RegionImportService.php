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
     * @param callable(array{processed:int,total:int,imported:int,updated:int,percent:int}):void|null $progress
     * @throws \RuntimeException
     */
    public function importFromFile(string $file, bool $truncate = false, ?callable $progress = null): int
    {
        if (!is_file($file)) {
            throw new \RuntimeException("地区数据文件不存在：{$file}");
        }

        $json = file_get_contents($file);
        $data = json_decode($json ?: '', true);
        if (!is_array($data)) {
            throw new \RuntimeException('地区数据文件格式错误');
        }

        $total = $this->countNodes($data);

        return $this->transaction(function () use ($data, $truncate, $progress, $total): int {
            if ($truncate) {
                $this->model()->whereRaw('1=1')->delete(true);
            }

            $imported = 0;
            $updated = 0;
            $processed = 0;
            $lastProgressAt = 0.0;
            $emitProgress = function (bool $force = false) use (
                $progress,
                $total,
                &$processed,
                &$imported,
                &$updated,
                &$lastProgressAt
            ): void {
                if ($progress === null) {
                    return;
                }

                $now = microtime(true);
                if (
                    !$force
                    && $processed < $total
                    && $processed % 500 !== 0
                    && ($now - $lastProgressAt) < 1.0
                ) {
                    return;
                }

                $lastProgressAt = $now;
                $progress([
                    'processed' => $processed,
                    'total'     => $total,
                    'imported'  => $imported,
                    'updated'   => $updated,
                    'percent'   => $total > 0 ? (int) floor($processed * 100 / $total) : 100,
                ]);
            };

            $emitProgress(true);
            $this->importNodes($data, 0, [], 1, $imported, $updated, $processed, $total, $emitProgress);
            $emitProgress(true);

            return $imported;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, string> $pathCodes
     */
    protected function importNodes(
        array $nodes,
        int $parentId,
        array $pathCodes,
        int $level,
        int &$imported,
        int &$updated,
        int &$processed,
        int $total,
        callable $progress
    ): void {
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
                $updated++;
            } else {
                /** @var Region $created */
                $created = $this->model()->create($record);
                $id = (int) $created->id;
                $imported++;
            }

            $processed++;
            $progress($processed >= $total);

            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->importNodes(
                    $children,
                    $id,
                    $currentPathCodes,
                    $level + 1,
                    $imported,
                    $updated,
                    $processed,
                    $total,
                    $progress
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function countNodes(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            $code = (string) ($node['code'] ?? '');
            $name = (string) ($node['name'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }

            $count++;

            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $count += $this->countNodes($children);
            }
        }

        return $count;
    }
}
