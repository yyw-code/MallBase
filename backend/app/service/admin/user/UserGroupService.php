<?php
declare(strict_types=1);

namespace app\service\admin\user;

use app\model\user\UserGroup;
use app\model\user\UserGroupRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 用户分组服务
 * @extends BaseService<UserGroup>
 */
class UserGroupService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = UserGroup::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['name'] ?? null) !== null && $where['name'] !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['name'] . '%');
            })
            ->when(($where['code'] ?? null) !== null && $where['code'] !== '', function ($q) use ($where) {
                $q->whereLike('code', '%' . $where['code'] . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            });
    }

    /**
     * 获取分组列表
     *
     * @param array $where 搜索条件（支持 name、code、status）
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{list: array, total: int} 返回分组列表和总数
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
     * 获取分组详情
     *
     * @param int $id 分组 ID
     * @return array 分组详情
     * @throws BusinessException 分组不存在时抛出
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('分组不存在');
        }

        return $info->toArray();
    }

    /**
     * 创建分组
     *
     * @param array{code: string, name: string, description?: string, color?: string, sort?: int, status?: int} $data 分组数据
     * @return int 新创建的分组 ID
     * @throws BusinessException 分组编码已存在时抛出
     */
    public function create(array $data): int
    {
        // 检查编码是否重复
        $exists = $this->model()
            ->where('code', $data['code'])
            ->find();

        if ($exists) {
            throw new BusinessException('分组编码已存在');
        }

        $group = $this->model()->create($data);

        return $group->id;
    }

    /**
     * 更新分组
     *
     * @param int $id 分组 ID
     * @param array $data 要更新的数据
     * @return bool 更新成功返回 true
     * @throws BusinessException 分组不存在或编码已存在时抛出
     */
    public function update(int $id, array $data): bool
    {
        $group = $this->model()->find($id);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        // 如果修改了编码，检查是否重复
        if (isset($data['code']) && $data['code'] !== $group->code) {
            $exists = $this->model()
                ->where('code', $data['code'])
                ->where('id', '<>', $id)
                ->find();

            if ($exists) {
                throw new BusinessException('分组编码已存在');
            }
        }

        $group->save($data);

        return true;
    }

    /**
     * 删除分组
     *
     * @param int $id 分组 ID
     * @return bool 删除成功返回 true
     * @throws BusinessException 分组不存在或该分组下还有用户时抛出
     */
    public function delete(int $id): bool
    {
        $group = $this->model()->find($id);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        // 检查是否有用户关联
        $userCount = $this->getUserCount($id);

        if ($userCount > 0) {
            throw new BusinessException('该分组下还有用户，无法删除');
        }

        $group->delete();

        return true;
    }

    /**
     * 获取分组下的用户数
     *
     * @param int $groupId 分组 ID
     * @return int 用户数量
     */
    public function getUserCount(int $groupId): int
    {
        return $this->model(UserGroupRelation::class)->where('group_id', $groupId)->count();
    }

    /**
     * 批量设置用户分组
     *
     * @param int $groupId 分组 ID
     * @param array<int> $userIds 用户 ID 数组
     * @return bool 设置成功返回 true
     * @throws BusinessException 分组不存在时抛出
     */
    public function batchSetUsers(int $groupId, array $userIds): bool
    {
        $group = $this->model()->find($groupId);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $count = 0;
        foreach ($userIds as $userId) {
            $relation = $this->model(UserGroupRelation::class)->where('user_id', $userId)
                ->where('group_id', $groupId)
                ->find();

            if (!$relation) {
                $this->model(UserGroupRelation::class)->create([
                    'user_id' => $userId,
                    'group_id' => $groupId,
                ]);
                $count++;
            }
        }

        return true;
    }

    /**
     * 移除用户分组
     *
     * @param int $groupId 分组 ID
     * @param int $userId 用户 ID
     * @return bool 移除成功返回 true
     * @throws BusinessException 用户不在该分组中时抛出
     */
    public function removeUser(int $groupId, int $userId): bool
    {
        $relation = $this->model(UserGroupRelation::class)->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->find();

        if (!$relation) {
            throw new BusinessException('用户不在该分组中');
        }

        $relation->delete();

        return true;
    }

    /**
     * 获取用户的所有分组
     *
     * @param int $userId 用户 ID
     * @return array<int, array> 用户所属的分组列表（仅返回启用状态的分组）
     */
    public function getUserGroups(int $userId): array
    {
        $relations = $this->model(UserGroupRelation::class)->where('user_id', $userId)
            ->select();

        $groupIds = array_column($relations->toArray(), 'group_id');

        if (empty($groupIds)) {
            return [];
        }

        $groups = $this->model()
            ->where('status', 1)
            ->whereIn('id', $groupIds)
            ->select();

        return $groups->toArray();
    }

    /**
     * 更新分组状态
     *
     * @param int $id 分组 ID
     * @param int $status 状态（1=启用，0=禁用）
     * @return bool 更新成功返回 true
     * @throws BusinessException 分组不存在时抛出
     */
    public function updateStatus(int $id, int $status): bool
    {
        $group = $this->model()->find($id);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $group->save(['status' => $status]);

        return true;
    }
}
