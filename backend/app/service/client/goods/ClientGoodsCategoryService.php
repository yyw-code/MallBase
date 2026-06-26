<?php

declare(strict_types=1);

namespace app\service\client\goods;

use app\model\goods\GoodsCategory;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;

/**
 * 客户端(C 端)商品分类服务
 *
 * 仅做读;过滤"启用 + 未删除"。
 *
 * @extends BaseService<GoodsCategory>
 */
class ClientGoodsCategoryService extends BaseService
{
    protected string $modelClass = GoodsCategory::class;

    /**
     * 分类树(扁平 → 树)
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        $rows = $this->model()
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $rows = app()->make(AssetHydrator::class)->hydrateFields($rows, [
            'image' => 'image_full_url',
        ]);

        return $this->buildTree($rows, 0);
    }

    /**
     * 扁平分类列表(全部启用项)
     *
     * @return array<int, array<string, mixed>>
     */
    public function flatList(): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return app()->make(AssetHydrator::class)->hydrateFields($list, [
            'image' => 'image_full_url',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $rows, int $parentId): array
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int) $row['pid'] !== $parentId) {
                continue;
            }
            $children = $this->buildTree($rows, (int) $row['id']);
            if ($children !== []) {
                $row['children'] = $children;
            }
            $tree[] = $row;
        }
        return $tree;
    }
}
