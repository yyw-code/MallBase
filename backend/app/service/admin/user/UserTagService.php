<?php
declare(strict_types=1);

namespace app\service\admin\user;

use app\model\user\UserGroupRelation;
use app\model\user\UserTag;
use app\model\user\UserTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 用户标签服务
 * @extends BaseService<UserTag>
 */
class UserTagService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = UserTag::class;

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
     * 获取标签列表
     *
     * @param array $where 搜索条件（支持 name、status）
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{list: array, total: int} 返回标签列表和总数
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
     * 创建标签
     *
     * @param array{name: string, color: string, sort?: int, status?: int} $data 标签数据
     * @return int 新创建的标签 ID
     */
    public function create(array $data): int
    {
        $tag = $this->model()->create($data);

        return $tag->id;
    }

    /**
     * 更新标签
     *
     * @param int $id 标签 ID
     * @param array $data 要更新的数据
     * @return bool 更新成功返回 true
     * @throws BusinessException 标签不存在时抛出
     */
    public function update(int $id, array $data): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        $tag->save($data);

        return true;
    }

    /**
     * 删除标签
     *
     * @param int $id 标签 ID
     * @return bool 删除成功返回 true
     * @throws BusinessException 标签不存在或该标签下还有用户时抛出
     */
    public function delete(int $id): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        // 检查是否有用户关联
        $userCount = $this->getUserCount($id);

        if ($userCount > 0) {
            throw new BusinessException('该标签下还有用户，无法删除');
        }

        $tag->delete();

        return true;
    }

    /**
     * 获取标签下的用户数
     *
     * @param int $tagId 标签 ID
     * @return int 用户数量
     */
    public function getUserCount(int $tagId): int
    {
        return $this->model(UserTagRelation::class)->where('tag_id', $tagId)->count();
    }

    /**
     * 批量给用户打标签
     *
     * @param int $tagId 标签 ID
     * @param array<int> $userIds 用户 ID 数组
     * @return bool 设置成功返回 true
     * @throws BusinessException 标签不存在时抛出
     */
    public function batchSetUsers(int $tagId, array $userIds): bool
    {
        $tag = $this->model()->find($tagId);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        $count = 0;
        foreach ($userIds as $userId) {
            $relation = $this->model(UserTagRelation::class)->where('user_id', $userId)
                ->where('tag_id', $tagId)
                ->find();

            if (!$relation) {
                $this->model(UserTagRelation::class)->create([
                    'user_id' => $userId,
                    'tag_id' => $tagId,
                ]);
                $count++;
            }
        }

        return true;
    }

    /**
     * 移除用户标签
     *
     * @param int $tagId 标签 ID
     * @param int $userId 用户 ID
     * @return bool 移除成功返回 true
     * @throws BusinessException 用户没有该标签时抛出
     */
    public function removeUser(int $tagId, int $userId): bool
    {
        $relation = $this->model(UserTagRelation::class)->where('user_id', $userId)
            ->where('tag_id', $tagId)
            ->find();

        if (!$relation) {
            throw new BusinessException('用户没有该标签');
        }

        $relation->delete();

        return true;
    }

    /**
     * 获取用户的所有标签
     *
     * @param int $userId 用户 ID
     * @return array<int, array> 用户拥有的标签列表（仅返回启用状态的标签）
     */
    public function getUserTags(int $userId): array
    {
        $relations = $this->model(UserTagRelation::class)->where('user_id', $userId)
            ->select();

        $tagIds = array_column($relations->toArray(), 'tag_id');

        if (empty($tagIds)) {
            return [];
        }

        $tags = $this->model()
            ->where('status', 1)
            ->whereIn('id', $tagIds)
            ->select();

        return $tags->toArray();
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
}
