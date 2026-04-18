<?php
declare(strict_types=1);

namespace app\admin\service\region;

use app\model\region\Region;
use app\admin\service\setting\FreightTemplateService;
use app\admin\service\user\UserAddressService;
use app\service\RegionResolverService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * @extends BaseService<Region>
 */
class RegionService extends BaseService
{
    protected string $modelClass = Region::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', '%' . $where['keyword'] . '%');
            })
            ->when(($where['level'] ?? null) !== null && $where['level'] !== '', function ($q) use ($where) {
                $q->where('level', $where['level']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(($where['parent_id'] ?? null) !== null && $where['parent_id'] !== '', function ($q) use ($where) {
                $q->where('parent_id', $where['parent_id']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = $this->buildListQuery($where)->count();
        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);
        if (!$info) {
            throw new BusinessException('地区不存在');
        }

        $data = $info->toArray();
        $data['path'] = app()->make(RegionResolverService::class)->getPath($id);
        return $data;
    }

    public function getChildren(int $parentId): array
    {
        return app()->make(RegionResolverService::class)->getChildren($parentId);
    }

    public function getPath(int $id): array
    {
        return app()->make(RegionResolverService::class)->getPath($id);
    }

    public function create(array $data): int
    {
        $this->assertParentAndLevel($data);
        $exists = $this->model()->where('code', $data['code'])->find();
        if ($exists) {
            throw new BusinessException('地区编码已存在');
        }

        $data['path_codes'] = $this->buildPathCodes((int) $data['parent_id'], (string) $data['code']);
        $region = $this->model()->create($data);
        return (int) $region->id;
    }

    public function update(int $id, array $data): bool
    {
        $region = $this->model()->find($id);
        if (!$region) {
            throw new BusinessException('地区不存在');
        }

        $this->assertParentAndLevel($data, $id);
        $exists = $this->model()->where('code', $data['code'])->where('id', '<>', $id)->find();
        if ($exists) {
            throw new BusinessException('地区编码已存在');
        }

        $data['path_codes'] = $this->buildPathCodes((int) $data['parent_id'], (string) $data['code']);
        $region->save($data);
        return true;
    }

    public function updateStatus(int $id, int $status): bool
    {
        $region = $this->model()->find($id);
        if (!$region) {
            throw new BusinessException('地区不存在');
        }

        $region->save(['status' => $status]);
        return true;
    }

    public function delete(int $id): bool
    {
        $region = $this->model()->find($id);
        if (!$region) {
            throw new BusinessException('地区不存在');
        }

        $subtreeIds = $this->collectSubtreeIds($id);

        return $this->transaction(function () use ($subtreeIds) {
            app()->make(UserAddressService::class)
                ->invalidateByRegionIds($subtreeIds, '关联地区已删除，请执行更新失效数据');
            app()->make(FreightTemplateService::class)
                ->invalidateRulesByRegionIds($subtreeIds, '关联地区已删除，请执行更新失效数据');

            return (bool) $this->model()->whereIn('id', $subtreeIds)->delete();
        });
    }

    protected function assertParentAndLevel(array $data, ?int $currentId = null): void
    {
        $parentId = (int) ($data['parent_id'] ?? 0);
        $level = (int) ($data['level'] ?? 0);

        if ($level === 1 && $parentId !== 0) {
            throw new BusinessException('省级地区父级必须为0');
        }
        if ($level > 1 && $parentId <= 0) {
            throw new BusinessException('非省级地区必须选择父级');
        }

        if ($parentId > 0) {
            $parent = $this->model()->find($parentId);
            if (!$parent) {
                throw new BusinessException('父级地区不存在');
            }
            if ($currentId !== null && $parentId === $currentId) {
                throw new BusinessException('父级地区不能是自身');
            }
            if ((int) $parent->level !== $level - 1) {
                throw new BusinessException('父级地区层级不正确');
            }
        }
    }

    protected function buildPathCodes(int $parentId, string $code): string
    {
        if ($parentId <= 0) {
            return $code;
        }

        $parent = $this->model()->find($parentId);
        if (!$parent) {
            throw new BusinessException('父级地区不存在');
        }

        return trim((string) $parent->path_codes . ',' . $code, ',');
    }

    /**
     * @return array<int, int>
     */
    protected function collectSubtreeIds(int $rootId): array
    {
        $allIds = [$rootId];
        $queue = [$rootId];

        while ($queue !== []) {
            $children = $this->model()
                ->whereIn('parent_id', $queue)
                ->column('id');

            $queue = array_values(array_unique(array_map('intval', $children)));
            if ($queue === []) {
                continue;
            }

            $allIds = array_values(array_unique(array_merge($allIds, $queue)));
        }

        return $allIds;
    }
}
