<?php
declare(strict_types=1);

namespace app\admin\service\goods;

use app\model\goods\GoodsBrand;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品品牌服务
 * @extends BaseService<GoodsBrand>
 */
class GoodsBrandService extends BaseService
{
    protected string $modelClass = GoodsBrand::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['name'] ?? null) !== null && $where['name'] !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['name'] . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            });
    }

    /**
     * 获取品牌列表
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();

        $total = $this->buildListQuery($where)->count();

        $list = $list->toArray();

        return compact('total', 'list');
    }

    /**
     * 获取所有启用品牌（供商品表单使用）
     */
    public function getAllBrands(): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select();

        return $list->toArray();
    }

    /**
     * 获取品牌详情
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('品牌不存在');
        }

        return $info->toArray();
    }

    /**
     * 创建品牌
     */
    public function create(array $data): int
    {
        // 校验名称唯一（事务外）
        $this->validateUniqueName($data['name']);

        $brand = $this->model()->create($data);

        return $brand->id;
    }

    /**
     * 更新品牌
     */
    public function update(int $id, array $data): bool
    {
        $brand = $this->model()->find($id);

        if (!$brand) {
            throw new BusinessException('品牌不存在');
        }

        // 校验名称唯一
        if (isset($data['name'])) {
            $this->validateUniqueName($data['name'], $id);
        }

        $brand->save($data);

        return true;
    }

    /**
     * 删除品牌
     */
    public function delete(int $id): bool
    {
        $brand = $this->model()->find($id);

        if (!$brand) {
            throw new BusinessException('品牌不存在');
        }

        // 检查是否有关联商品
        $goodsCount = $this->getGoodsCount($id);
        if ($goodsCount > 0) {
            throw new BusinessException('该品牌下还有商品，无法删除');
        }

        $brand->delete();

        return true;
    }

    /**
     * 更新品牌状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $brand = $this->model()->find($id);

        if (!$brand) {
            throw new BusinessException('品牌不存在');
        }

        $brand->save(['status' => $status]);

        return true;
    }

    /**
     * 获取品牌下的商品数
     */
    public function getGoodsCount(int $brandId): int
    {
        return $this->model(\app\model\goods\Goods::class)
            ->where('brand_id', $brandId)
            ->count();
    }

    /**
     * 校验品牌名称唯一
     */
    protected function validateUniqueName(string $name, int $excludeId = 0): void
    {
        $query = $this->model()->where('name', $name);

        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        $exists = $query->find();
        if ($exists) {
            throw new BusinessException('品牌名称已存在');
        }
    }
}
