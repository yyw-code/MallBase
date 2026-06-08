<?php
declare(strict_types=1);

namespace app\service\admin\upload;

use app\model\upload\UploadAsset;
use app\model\upload\UploadAssetCategory;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台素材分类服务。
 *
 * @extends BaseService<UploadAssetCategory>
 */
class UploadAssetCategoryAdminService extends BaseService
{
    protected string $modelClass = UploadAssetCategory::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['name'] ?? '') !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . trim((string) $where['name']) . '%');
            })
            ->when(($where['pid'] ?? '') !== '', function ($q) use ($where) {
                $q->where('pid', (int) $where['pid']);
            })
            ->when(($where['status'] ?? '') !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function tree(array $where = []): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->buildTree($list);
    }

    public function create(array $data): int
    {
        $data['pid'] = (int) ($data['pid'] ?? 0);
        $this->validateParent($data['pid']);
        $this->validateUniqueCode((string) ($data['code'] ?? ''));

        $category = $this->model()->create([
            'pid' => $data['pid'],
            'name' => (string) $data['name'],
            'code' => (string) $data['code'],
            'sort' => (int) ($data['sort'] ?? 0),
            'is_system' => 0,
            'status' => (int) ($data['status'] ?? 1),
        ]);

        return (int) $category->id;
    }

    public function update(int $id, array $data): bool
    {
        $category = $this->findCategory($id);
        if ((int) $category->is_system === 1 && isset($data['code'])) {
            unset($data['code']);
        }
        if (isset($data['pid']) && (int) $data['pid'] === $id) {
            throw new BusinessException('不能将自己设为父级分类');
        }
        if (isset($data['pid'])) {
            $this->validateParent((int) $data['pid']);
        }
        if (isset($data['code'])) {
            $this->validateUniqueCode((string) $data['code'], $id);
        }

        $payload = [];
        foreach (['pid', 'name', 'code', 'sort', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if ($payload !== []) {
            $category->save($payload);
        }

        return true;
    }

    public function delete(int $id): bool
    {
        $category = $this->findCategory($id);
        if ((int) $category->is_system === 1) {
            throw new BusinessException('系统分类不能删除');
        }
        $childCount = $this->model()->where('pid', $id)->count();
        if ((int) $childCount > 0) {
            throw new BusinessException('该分类下还有子分类，不能删除');
        }
        $assetCount = $this->model(UploadAsset::class)->where('category_id', $id)->count();
        if ((int) $assetCount > 0) {
            throw new BusinessException('该分类下还有素材，不能删除');
        }

        $category->delete();
        return true;
    }

    private function findCategory(int $id): UploadAssetCategory
    {
        $category = $this->model()->find($id);
        if ($category === null) {
            throw new BusinessException('素材分类不存在');
        }

        return $category;
    }

    private function validateParent(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $parent = $this->model()->find($pid);
        if ($parent === null) {
            throw new BusinessException('父级分类不存在');
        }
    }

    private function validateUniqueCode(string $code, int $excludeId = 0): void
    {
        $code = trim($code);
        if ($code === '') {
            throw new BusinessException('分类编码不能为空');
        }

        $query = $this->model()->where('code', $code);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->count() > 0) {
            throw new BusinessException('分类编码已存在');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $list
     * @return array<int,array<string,mixed>>
     */
    private function buildTree(array $list, int $pid = 0): array
    {
        $tree = [];
        foreach ($list as $item) {
            if ((int) ($item['pid'] ?? 0) !== $pid) {
                continue;
            }
            $item['children'] = $this->buildTree($list, (int) $item['id']);
            $tree[] = $item;
        }

        return $tree;
    }
}
