<?php
declare(strict_types=1);

namespace app\admin\service\goods;

use app\admin\model\goods\GoodsTag;
use app\admin\model\goods\GoodsTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品标签服务
 */
class GoodsTagService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = GoodsTag::class;

    /**
     * 获取标签列表
     *
     * @param array $where 搜索条件（支持 name、status）
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{list: array, total: int}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $searchWhere = array_filter($where, function ($value) {
            return $value !== '' && $value !== null;
        });

        $list = $this->model()
            ->withSearch(['name', 'status'], $searchWhere)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();

        $total = $this->model()
            ->withSearch(['name', 'status'], $searchWhere)
            ->count();

        $list = $list->toArray();

        return compact('total', 'list');
    }

    /**
     * 获取标签详情
     *
     * @param int $id 标签 ID
     * @return array 标签详情
     * @throws BusinessException 标签不存在时抛出
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('标签不存在');
        }

        return $info->toArray();
    }

    /**
     * 获取所有启用标签
     *
     * @return array 启用状态的标签列表
     */
    public function getAllTags(): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select();

        return $list->toArray();
    }

    /**
     * 创建标签
     *
     * @param array $data 标签数据
     * @return int 新创建的标签 ID
     * @throws BusinessException 标签名称已存在时抛出
     */
    public function create(array $data): int
    {
        // 校验名称唯一（事务外）
        $this->validateUniqueName($data['name']);

        $tag = $this->model()->create($data);

        return $tag->id;
    }

    /**
     * 更新标签
     *
     * @param int $id 标签 ID
     * @param array $data 标签数据
     * @return bool 更新成功返回 true
     * @throws BusinessException 标签不存在或名称已存在时抛出
     */
    public function update(int $id, array $data): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        // 校验名称唯一
        if (isset($data['name'])) {
            $this->validateUniqueName($data['name'], $id);
        }

        $tag->save($data);

        return true;
    }

    /**
     * 删除标签
     *
     * @param int $id 标签 ID
     * @return bool 删除成功返回 true
     * @throws BusinessException 标签不存在或有关联商品时抛出
     */
    public function delete(int $id): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        // 检查是否有关联商品
        $goodsCount = $this->model(GoodsTagRelation::class)
            ->where('tag_id', $id)
            ->count();

        if ($goodsCount > 0) {
            throw new BusinessException('该标签下还有关联商品，无法删除');
        }

        $tag->delete();

        return true;
    }

    /**
     * 更新标签状态
     *
     * @param int $id 标签 ID
     * @param int $status 状态（1=启用，0=禁用）
     * @return bool 更新成功返回 true
     * @throws BusinessException 标签不存在时抛出
     */
    public function updateStatus(int $id, int $status): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        $tag->save(['status' => $status]);

        return true;
    }

    /**
     * 校验标签名称唯一
     *
     * @param string $name 标签名称
     * @param int $excludeId 排除的标签 ID
     * @throws BusinessException 名称已存在时抛出
     */
    protected function validateUniqueName(string $name, int $excludeId = 0): void
    {
        $query = $this->model()->where('name', $name);

        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        $exists = $query->find();
        if ($exists) {
            throw new BusinessException('标签名称已存在');
        }
    }
}
