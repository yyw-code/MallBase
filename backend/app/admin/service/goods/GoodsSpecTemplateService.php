<?php
declare(strict_types=1);

namespace app\admin\service\goods;

use app\admin\model\goods\GoodsSpecTemplate;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品规格模板服务
 */
class GoodsSpecTemplateService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = GoodsSpecTemplate::class;

    /**
     * 获取规格模板分页列表
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
     * 获取规格模板详情
     *
     * @param int $id 模板 ID
     * @return array
     * @throws BusinessException
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('规格模板不存在');
        }

        return $info->toArray();
    }

    /**
     * 获取所有启用规格模板（供商品表单下拉使用）
     *
     * @return array
     */
    public function getAll(): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->order('sort', 'asc')
            ->select();

        return $list->toArray();
    }

    /**
     * 创建规格模板
     *
     * @param array $data 模板数据
     * @return int 新创建的模板 ID
     * @throws BusinessException
     */
    public function create(array $data): int
    {
        $this->validateNameUnique($data['name']);

        $template = $this->model()->create($data);

        return $template->id;
    }

    /**
     * 更新规格模板
     *
     * @param int $id 模板 ID
     * @param array $data 更新数据
     * @return bool
     * @throws BusinessException
     */
    public function update(int $id, array $data): bool
    {
        // 业务校验（事务外）
        $template = $this->model()->find($id);

        if (!$template) {
            throw new BusinessException('规格模板不存在');
        }

        // 如果修改了名称，校验名称唯一（排除自身）
        if (isset($data['name']) && $data['name'] !== $template->name) {
            $this->validateNameUnique($data['name'], $id);
        }

        $template->save($data);

        return true;
    }

    /**
     * 删除规格模板
     *
     * @param int $id 模板 ID
     * @return bool
     * @throws BusinessException
     */
    public function delete(int $id): bool
    {
        // 业务校验（事务外）
        $template = $this->model()->find($id);

        if (!$template) {
            throw new BusinessException('规格模板不存在');
        }

        $template->delete();

        return true;
    }

    /**
     * 更新规格模板状态
     *
     * @param int $id 模板 ID
     * @param int $status 状态（1=启用，0=禁用）
     * @return bool
     * @throws BusinessException
     */
    public function updateStatus(int $id, int $status): bool
    {
        $template = $this->model()->find($id);

        if (!$template) {
            throw new BusinessException('规格模板不存在');
        }

        $template->save(['status' => $status]);

        return true;
    }

    /**
     * 校验模板名称唯一
     *
     * @param string $name 模板名称
     * @param int $excludeId 排除的 ID（更新时使用）
     * @throws BusinessException
     */
    protected function validateNameUnique(string $name, int $excludeId = 0): void
    {
        $query = $this->model()->where('name', $name);

        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        $exists = $query->find();

        if ($exists) {
            throw new BusinessException('规格模板名称已存在');
        }
    }
}
