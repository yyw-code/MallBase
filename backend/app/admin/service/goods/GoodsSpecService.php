<?php
declare(strict_types=1);

namespace app\admin\service\goods;

use app\model\goods\GoodsSpec;
use app\model\goods\GoodsSpecValue;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品规格服务
 * @extends BaseService<GoodsSpec>
 */
class GoodsSpecService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = GoodsSpec::class;

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
     * 获取规格组分页列表
     *
     * @param array $where 搜索条件（支持 name、status）
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{list: array, total: int}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->with(['specValues' => function ($q) {
                $q->order('sort', 'asc');
            }])
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();

        $total = $this->buildListQuery($where)->count();

        $list = $this->normalizeSpecList($list->toArray());

        return compact('total', 'list');
    }

    /**
     * 获取规格详情（含规格值）
     *
     * @param int $id 规格 ID
     * @return array
     * @throws BusinessException
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()
            ->with(['specValues' => function ($q) {
                $q->order('sort', 'asc');
            }])
            ->find($id);

        if (!$info) {
            throw new BusinessException('规格不存在');
        }

        return $this->normalizeSpecItem($info->toArray());
    }

    /**
     * 获取所有启用规格（含规格值，供商品表单使用）
     *
     * @return array
     */
    public function getAllSpecs(): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->with(['specValues' => function ($q) {
                $q->order('sort', 'asc');
            }])
            ->order('sort', 'asc')
            ->select();

        return $this->normalizeSpecList($list->toArray());
    }

    /**
     * 统一规格值字段，前端固定使用 spec_values。
     */
    protected function normalizeSpecList(array $list): array
    {
        return array_map(fn(array $item) => $this->normalizeSpecItem($item), $list);
    }

    /**
     * 兼容 ThinkPHP 关联输出键名不稳定。
     */
    protected function normalizeSpecItem(array $item): array
    {
        $item['spec_values'] = $item['spec_values'] ?? $item['specValues'] ?? [];
        unset($item['specValues']);

        return $item;
    }

    /**
     * 创建规格组
     *
     * @param array $data 规格数据
     * @return int 新创建的规格 ID
     * @throws BusinessException
     */
    public function create(array $data): int
    {
        // 业务校验（事务外）
        $this->validateSpecNameUnique($data['name']);

        $spec = $this->model()->create($data);


        return $spec->id;
    }

    /**
     * 更新规格组
     *
     * @param int $id 规格 ID
     * @param array $data 更新数据
     * @return bool
     * @throws BusinessException
     */
    public function update(int $id, array $data): bool
    {
        // 业务校验（事务外）
        $spec = $this->model()->find($id);

        if (!$spec) {
            throw new BusinessException('规格不存在');
        }

        // 如果修改了名称，校验名称唯一
        if (isset($data['name']) && $data['name'] !== $spec->name) {
            $this->validateSpecNameUnique($data['name'], $id);
        }

        $spec->save($data);


        return true;
    }

    /**
     * 删除规格组
     *
     * @param int $id 规格 ID
     * @return bool
     * @throws BusinessException
     */
    public function delete(int $id): bool
    {
        // 业务校验（事务外）
        $spec = $this->model()->find($id);

        if (!$spec) {
            throw new BusinessException('规格不存在');
        }

        // 事务内只做写入
        return $this->transaction(function () use ($id, $spec) {
            // 删除关联的规格值
            $this->model(GoodsSpecValue::class)->where('spec_id', $id)->delete();

            $spec->delete();


            return true;
        });
    }

    /**
     * 更新规格状态
     *
     * @param int $id 规格 ID
     * @param int $status 状态（1=启用，0=禁用）
     * @return bool
     * @throws BusinessException
     */
    public function updateStatus(int $id, int $status): bool
    {
        $spec = $this->model()->find($id);

        if (!$spec) {
            throw new BusinessException('规格不存在');
        }

        $spec->save(['status' => $status]);


        return true;
    }

    /**
     * 添加规格值
     *
     * @param int $specId 规格 ID
     * @param string $value 规格值
     * @return int 新创建的规格值 ID
     * @throws BusinessException
     */
    public function createSpecValue(int $specId, string $value): int
    {
        // 业务校验（事务外）
        $spec = $this->model()->find($specId);

        if (!$spec) {
            throw new BusinessException('规格不存在');
        }

        // 校验同规格下值唯一
        $exists = $this->model(GoodsSpecValue::class)
            ->where('spec_id', $specId)
            ->where('value', $value)
            ->find();

        if ($exists) {
            throw new BusinessException('该规格下已存在相同的规格值');
        }

        $specValue = $this->model(GoodsSpecValue::class)->create([
            'spec_id' => $specId,
            'value' => $value,
        ]);


        return $specValue->id;
    }

    /**
     * 删除规格值
     *
     * @param int $valueId 规格值 ID
     * @return bool
     * @throws BusinessException
     */
    public function deleteSpecValue(int $valueId): bool
    {
        $specValue = $this->model(GoodsSpecValue::class)->find($valueId);

        if (!$specValue) {
            throw new BusinessException('规格值不存在');
        }

        $specValue->delete();


        return true;
    }

    /**
     * 批量添加规格值
     *
     * @param int $specId 规格 ID
     * @param array $values 规格值列表
     * @return array 新创建的规格值 ID 列表
     * @throws BusinessException
     */
    public function batchCreateSpecValues(int $specId, array $values): array
    {
        // 业务校验（事务外）
        $spec = $this->model()->find($specId);

        if (!$spec) {
            throw new BusinessException('规格不存在');
        }

        // 校验值不能为空
        foreach ($values as $val) {
            if (empty($val)) {
                throw new BusinessException('规格值不能为空');
            }
        }

        // 校验去重
        $uniqueValues = array_unique($values);
        if (count($uniqueValues) !== count($values)) {
            throw new BusinessException('规格值中存在重复值');
        }

        // 校验与已有值不重复
        $existingValues = $this->model(GoodsSpecValue::class)
            ->where('spec_id', $specId)
            ->whereIn('value', $uniqueValues)
            ->column('value');

        if (!empty($existingValues)) {
            throw new BusinessException('规格值已存在：' . implode('、', $existingValues));
        }

        // 事务内只做写入
        return $this->transaction(function () use ($specId, $uniqueValues) {
            $ids = [];
            foreach ($uniqueValues as $val) {
                $specValue = $this->model(GoodsSpecValue::class)->create([
                    'spec_id' => $specId,
                    'value' => $val,
                ]);
                $ids[] = $specValue->id;
            }

            return $ids;
        });
    }

    /**
     * 校验规格名称唯一
     *
     * @param string $name 规格名称
     * @param int $excludeId 排除的 ID（更新时使用）
     * @throws BusinessException
     */
    protected function validateSpecNameUnique(string $name, int $excludeId = 0): void
    {
        $query = $this->model()->where('name', $name);

        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        $exists = $query->find();

        if ($exists) {
            throw new BusinessException('规格名称已存在');
        }
    }
}
