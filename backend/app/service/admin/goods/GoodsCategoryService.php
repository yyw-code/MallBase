<?php
declare(strict_types=1);

namespace app\service\admin\goods;

use app\model\goods\GoodsCategory;
use app\service\upload\AssetHydrator;
use app\service\upload\AssetIdNormalizer;
use app\service\upload\AssetService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品分类服务
 * @extends BaseService<GoodsCategory>
 */
class GoodsCategoryService extends BaseService
{
    protected string $modelClass = GoodsCategory::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['name'] ?? null) !== null && $where['name'] !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['name'] . '%');
            })
            ->when(($where['pid'] ?? null) !== null && $where['pid'] !== '', function ($q) use ($where) {
                $q->where('pid', $where['pid']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            });
    }

    /**
     * 获取分类列表
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();

        $total = $this->buildListQuery($where)->count();

        $list = app()->make(AssetHydrator::class)->hydrateFields($list->toArray(), [
            'image' => 'image_full_url',
        ]);

        return compact('total', 'list');
    }

    /**
     * 获取分类树（后台分类管理使用，包含启用/禁用）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTree(array $where = []): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $list = app()->make(AssetHydrator::class)->hydrateFields($list, [
            'image' => 'image_full_url',
        ]);

        return $this->buildTree($list);
    }

    /**
     * 获取所有启用分类（树形结构，供商品表单使用）
     */
    public function getAllCategories(): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select();

        $list = app()->make(AssetHydrator::class)->hydrateFields($list->toArray(), [
            'image' => 'image_full_url',
        ]);

        return $this->buildTree($list);
    }

    /**
     * 获取分类详情
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('分类不存在');
        }

        $rows = app()->make(AssetHydrator::class)->hydrateFields([$info->toArray()], [
            'image' => 'image_full_url',
        ]);
        return $rows[0] ?? $info->toArray();
    }

    /**
     * 创建分类
     */
    public function create(array $data): int
    {
        $data['image'] = $this->normalizeImageField($data['image'] ?? '');

        // 校验同级名称唯一（事务外）
        $this->validateUniqueName($data['name'], (int) $data['pid']);

        // 校验父级分类存在性
        if (!empty($data['pid'])) {
            $this->validateParentExists((int) $data['pid']);
        }

        $category = $this->model()->create($data);
        app()->make(AssetService::class)->syncUsage('goods_category', (int) $category->id, 'image', [$data['image']]);

        return $category->id;
    }

    /**
     * 更新分类
     */
    public function update(int $id, array $data): bool
    {
        $category = $this->model()->find($id);

        if (!$category) {
            throw new BusinessException('分类不存在');
        }

        if (array_key_exists('image', $data)) {
            $data['image'] = $this->normalizeImageField($data['image']);
        }

        // 校验同级名称唯一
        if (isset($data['name'])) {
            $this->validateUniqueName($data['name'], (int) ($data['pid'] ?? $category->pid), $id);
        }

        // 校验不能将自己设为子分类
        if (isset($data['pid']) && (int) $data['pid'] === $id) {
            throw new BusinessException('不能将自己设为父级分类');
        }

        // 校验父级分类存在性
        if (!empty($data['pid'])) {
            $this->validateParentExists((int) $data['pid']);
        }

        $category->save($data);
        app()->make(AssetService::class)->syncUsage('goods_category', $id, 'image', [$data['image'] ?? '']);

        return true;
    }

    /**
     * 删除分类
     */
    public function delete(int $id): bool
    {
        $category = $this->model()->find($id);

        if (!$category) {
            throw new BusinessException('分类不存在');
        }

        // 检查是否有子分类
        $childCount = $this->model()->where('pid', $id)->count();
        if ($childCount > 0) {
            throw new BusinessException('该分类下还有子分类，无法删除');
        }

        $category->delete();

        return true;
    }

    /**
     * 更新分类状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $category = $this->model()->find($id);

        if (!$category) {
            throw new BusinessException('分类不存在');
        }

        $category->save(['status' => $status]);

        return true;
    }

    /**
     * 校验同级名称唯一
     */
    protected function validateUniqueName(string $name, int $pid, int $excludeId = 0): void
    {
        $query = $this->model()->where('name', $name)->where('pid', $pid);

        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        $exists = $query->find();
        if ($exists) {
            throw new BusinessException('同级下已存在同名分类');
        }
    }

    /**
     * 校验父级分类存在
     */
    protected function validateParentExists(int $pid): void
    {
        $parent = $this->model()->find($pid);
        if (!$parent) {
            throw new BusinessException('父级分类不存在');
        }

        // 仅支持两级分类，父分类必须是一级分类
        if ($parent->pid != 0) {
            throw new BusinessException('仅支持两级分类，父级必须是一级分类');
        }
    }

    /**
     * 构建树形结构
     */
    protected function buildTree(array $list, int $pid = 0): array
    {
        $tree = [];
        $ids = array_flip(array_map(static fn (array $item): int => (int) $item['id'], $list));

        foreach ($list as $item) {
            $itemPid = (int) $item['pid'];
            $isRoot = $pid === 0 && ($itemPid === 0 || !isset($ids[$itemPid]));
            if ($isRoot || $itemPid === $pid) {
                $children = $this->buildTree($list, (int) $item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }

    protected function normalizeImageField(mixed $value): int|string
    {
        $normalized = app()->make(AssetIdNormalizer::class)->normalizeSingle($value);
        if (is_int($normalized)) {
            app()->make(AssetService::class)->assertUsableImageAssets([$normalized]);
        }

        return $normalized;
    }
}
