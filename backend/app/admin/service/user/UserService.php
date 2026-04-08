<?php

declare(strict_types=1);

namespace app\admin\service\user;

use app\admin\model\user\User;
use app\admin\model\user\UserGroup;
use app\admin\model\user\UserGroupRelation;
use app\admin\model\user\UserTag;
use app\admin\model\user\UserTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 前台用户管理服务（后台管理用）
 */
class UserService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = User::class;

    /**
     * 获取用户列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 15): array
    {
        $query = $this->buildListQuery($where);
        $list = $query->order('id', 'desc')->page($page, $limit)->select();

        // 计算总数（复用查询条件）
        $total = $this->buildListQuery($where)->count();

        // 批量获取分组和标签信息（避免 N+1 查询）
        $userIds = array_column($list->toArray(), 'id');
        $groupMap = $this->batchGetUserGroups($userIds);
        $tagMap = $this->batchGetUserTags($userIds);

        $listArray = $list->toArray();
        foreach ($listArray as &$user) {
            $user['groups'] = $groupMap[$user['id']] ?? [];
            $user['tags'] = $tagMap[$user['id']] ?? [];
        }

        return [
            'list' => $listArray,
            'total' => $total,
        ];
    }

    /**
     * 构建列表查询条件
     */
    protected function buildListQuery(array $where)
    {
        $query = $this->model();

        // 关键词搜索
        if (!empty($where['keyword'])) {
            $query->whereLike('mobile|email|nickname', "%{$where['keyword']}%");
        }

        // 状态筛选
        if (isset($where['status']) && $where['status'] !== null && $where['status'] !== '') {
            $query->where('status', $where['status']);
        }

        // 注册方式筛选
        if (!empty($where['register_type'])) {
            $query->where('register_type', $where['register_type']);
        }

        // 分组筛选（子查询方式，兼容 ThinkPHP 8）
        if (!empty($where['group_ids']) && is_array($where['group_ids'])) {
            $groupUserIds = $this->model(UserGroupRelation::class)
                ->whereIn('group_id', $where['group_ids'])
                ->column('user_id');
            $query->whereIn('id', array_unique($groupUserIds) ?: [0]);
        }

        // 标签筛选（子查询方式，兼容 ThinkPHP 8）
        if (!empty($where['tag_ids']) && is_array($where['tag_ids'])) {
            $tagUserIds = $this->model(UserTagRelation::class)
                ->whereIn('tag_id', $where['tag_ids'])
                ->column('user_id');
            $query->whereIn('id', array_unique($tagUserIds) ?: [0]);
        }

        return $query;
    }

    /**
     * 批量获取用户分组（避免 N+1）
     *
     * @param array<int> $userIds
     * @return array<int, array> 以 user_id 为 key 的分组列表
     */
    protected function batchGetUserGroups(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $relations = $this->model(UserGroupRelation::class)
            ->whereIn('user_id', $userIds)
            ->select();

        if ($relations->isEmpty()) {
            return [];
        }

        $groupedRelations = [];
        $groupIds = [];
        foreach ($relations->toArray() as $relation) {
            $groupedRelations[$relation['user_id']][] = $relation['group_id'];
            $groupIds[] = $relation['group_id'];
        }

        $groupIds = array_unique($groupIds);
        $groups = $this->model(UserGroup::class)
            ->where('status', 1)
            ->whereIn('id', $groupIds)
            ->select()
            ->toArray();

        $groupMap = array_column($groups, null, 'id');

        $result = [];
        foreach ($groupedRelations as $userId => $gIds) {
            $userGroups = [];
            foreach ($gIds as $gid) {
                if (isset($groupMap[$gid])) {
                    $userGroups[] = $groupMap[$gid];
                }
            }
            $result[$userId] = $userGroups;
        }

        return $result;
    }

    /**
     * 批量获取用户标签（避免 N+1）
     *
     * @param array<int> $userIds
     * @return array<int, array> 以 user_id 为 key 的标签列表
     */
    protected function batchGetUserTags(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $relations = $this->model(UserTagRelation::class)
            ->whereIn('user_id', $userIds)
            ->select();

        if ($relations->isEmpty()) {
            return [];
        }

        $groupedRelations = [];
        $tagIds = [];
        foreach ($relations->toArray() as $relation) {
            $groupedRelations[$relation['user_id']][] = $relation['tag_id'];
            $tagIds[] = $relation['tag_id'];
        }

        $tagIds = array_unique($tagIds);
        $tags = $this->model(UserTag::class)
            ->where('status', 1)
            ->whereIn('id', $tagIds)
            ->select()
            ->toArray();

        $tagMap = array_column($tags, null, 'id');

        $result = [];
        foreach ($groupedRelations as $userId => $tIds) {
            $userTags = [];
            foreach ($tIds as $tid) {
                if (isset($tagMap[$tid])) {
                    $userTags[] = $tagMap[$tid];
                }
            }
            $result[$userId] = $userTags;
        }

        return $result;
    }

    /**
     * 获取用户详情
     */
    public function getInfo(int $id): array
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $result = $user->toArray();
        $result['groups'] = $this->getUserGroups($id);
        $result['tags'] = $this->getUserTags($id);

        return $result;
    }

    /**
     * 获取用户的所有分组
     */
    public function getUserGroups(int $userId): array
    {
        $groupService = app()->make(UserGroupService::class);
        return $groupService->getUserGroups($userId);
    }

    /**
     * 获取用户的所有标签
     */
    public function getUserTags(int $userId): array
    {
        $tagService = app()->make(UserTagService::class);
        return $tagService->getUserTags($userId);
    }

    /**
     * 创建用户（事务保护）
     */
    public function create(array $data): int
    {
        return $this->transaction(function () use ($data) {
            // 检查手机号是否已存在
            if (!empty($data['mobile'])) {
                $exists = $this->model()->where('mobile', $data['mobile'])->find();
                if ($exists) {
                    throw new BusinessException('该手机号已被注册');
                }
            }

            // 检查邮箱是否已存在
            if (!empty($data['email'])) {
                $exists = $this->model()->where('email', $data['email'])->find();
                if ($exists) {
                    throw new BusinessException('该邮箱已被注册');
                }
            }

            $user = $this->model();
            $user->save($data);

            // 处理分组关联
            if (!empty($data['group_ids']) && is_array($data['group_ids'])) {
                $this->syncUserGroups($user->id, $data['group_ids']);
            }

            // 处理标签关联
            if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
                $this->syncUserTags($user->id, $data['tag_ids']);
            }

            return $user->id;
        });
    }

    /**
     * 更新用户（事务保护）
     */
    public function update(int $id, array $data): bool
    {
        return $this->transaction(function () use ($id, $data) {
            $user = $this->model()->find($id);

            if (!$user) {
                throw new BusinessException('用户不存在');
            }

            // 检查手机号是否已被其他人使用
            if (!empty($data['mobile'])) {
                $exists = $this->model()
                    ->where('mobile', $data['mobile'])
                    ->where('id', '<>', $id)
                    ->find();
                if ($exists) {
                    throw new BusinessException('该手机号已被使用');
                }
            }

            // 检查邮箱是否已被其他人使用
            if (!empty($data['email'])) {
                $exists = $this->model()
                    ->where('email', $data['email'])
                    ->where('id', '<>', $id)
                    ->find();
                if ($exists) {
                    throw new BusinessException('该邮箱已被使用');
                }
            }

            $user->save($data);

            // 处理分组关联（传入字段则同步，空数组则清空）
            if (array_key_exists('group_ids', $data) && is_array($data['group_ids'])) {
                $this->syncUserGroups($id, $data['group_ids']);
            }

            // 处理标签关联
            if (array_key_exists('tag_ids', $data) && is_array($data['tag_ids'])) {
                $this->syncUserTags($id, $data['tag_ids']);
            }

            return true;
        });
    }

    /**
     * 删除用户（事务保护，清理关联数据）
     */
    public function delete(int $id): bool
    {
        return $this->transaction(function () use ($id) {
            $user = $this->model()->find($id);

            if (!$user) {
                throw new BusinessException('用户不存在');
            }

            // 清理分组关联
            $this->model(UserGroupRelation::class)->where('user_id', $id)->delete();

            // 清理标签关联
            $this->model(UserTagRelation::class)->where('user_id', $id)->delete();

            return $user->delete();
        });
    }

    /**
     * 更新用户状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $user->save(['status' => $status]);

        return true;
    }

    /**
     * 重置密码
     */
    public function resetPassword(int $id, string $password): bool
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $user->save(['password' => $password]);

        return true;
    }

    /**
     * 同步用户分组（先删后批量写入）
     */
    protected function syncUserGroups(int $userId, array $groupIds): void
    {
        $this->model(UserGroupRelation::class)->where('user_id', $userId)->delete();

        if (!empty($groupIds)) {
            $data = array_map(fn(int $groupId) => [
                'user_id' => $userId,
                'group_id' => $groupId,
            ], $groupIds);
            $this->model(UserGroupRelation::class)->saveAll($data);
        }
    }

    /**
     * 同步用户标签（先删后批量写入）
     */
    protected function syncUserTags(int $userId, array $tagIds): void
    {
        $this->model(UserTagRelation::class)->where('user_id', $userId)->delete();

        if (!empty($tagIds)) {
            $data = array_map(fn(int $tagId) => [
                'user_id' => $userId,
                'tag_id' => $tagId,
            ], $tagIds);
            $this->model(UserTagRelation::class)->saveAll($data);
        }
    }
}
