<?php

declare(strict_types=1);

namespace app\service\admin\user;

use app\model\user\User;
use app\model\user\UserGroup;
use app\model\user\UserGroupRelation;
use app\model\user\UserTag;
use app\model\user\UserTagRelation;
use app\model\user\UserWallet;
use app\service\admin\support\CsvExportService;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 前台用户管理服务（后台管理用）
 * @extends BaseService<User>
 */
class UserService extends BaseService
{
    private const EXPORT_LIMIT = 5000;

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
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        // 计算总数（复用查询条件）
        $total = $this->buildListQuery($where)->count();

        $list = $this->appendListDerivedFields($list);

        return compact('total', 'list');
    }

    /**
     * @return array{total:int,tabs:array<int,array{key:string,label:string,count:int}>}
     */
    public function stats(array $where = []): array
    {
        $baseWhere = $where;
        unset($baseWhere['status']);

        $total = (int) $this->buildListQuery($baseWhere)->count();
        $tabs = [[
            'key' => 'all',
            'label' => '全部',
            'count' => $total,
        ]];

        foreach ([1 => '启用', 0 => '禁用'] as $status => $label) {
            $statusWhere = $baseWhere;
            $statusWhere['status'] = $status;
            $tabs[] = [
                'key' => (string) $status,
                'label' => $label,
                'count' => (int) $this->buildListQuery($statusWhere)->count(),
            ];
        }

        return compact('total', 'tabs');
    }

    public function exportCsv(array $where = []): string
    {
        $rows = $this->buildListQuery($where)
            ->order('id', 'desc')
            ->limit(self::EXPORT_LIMIT)
            ->select()
            ->toArray();
        $rows = $this->appendListDerivedFields($rows);

        foreach ($rows as &$row) {
            $row['status_text'] = (int) ($row['status'] ?? 0) === 1 ? '启用' : '禁用';
            $row['groups_text'] = implode('、', array_column($row['groups'] ?? [], 'name'));
            $row['tags_text'] = implode('、', array_column($row['tags'] ?? [], 'name'));
            $row['balance'] = $row['wallet']['balance'] ?? '0.00';
        }
        unset($row);

        return app()->make(CsvExportService::class)->make([
            'id' => 'ID',
            'nickname' => '昵称',
            'mobile' => '手机号',
            'email' => '邮箱',
            'balance' => '余额',
            'register_type' => '注册方式',
            'status_text' => '状态',
            'groups_text' => '分组',
            'tags_text' => '标签',
            'last_login_time' => '最后登录',
            'create_time' => '注册时间',
        ], $rows);
    }

    /**
     * 构建列表查询条件
     */
    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->whereNull('delete_time')
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('mobile|email|nickname', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(!empty($where['register_type']), function ($q) use ($where) {
                $q->where('register_type', $where['register_type']);
            })
            ->when(!empty($where['group_ids']) && is_array($where['group_ids']), function ($q) use ($where) {
                $groupUserIds = $this->model(UserGroupRelation::class)
                    ->whereIn('group_id', $where['group_ids'])
                    ->column('user_id');
                $q->whereIn('id', array_unique($groupUserIds) ?: [0]);
            })
            ->when(!empty($where['tag_ids']) && is_array($where['tag_ids']), function ($q) use ($where) {
                $tagUserIds = $this->model(UserTagRelation::class)
                    ->whereIn('tag_id', $where['tag_ids'])
                    ->column('user_id');
                $q->whereIn('id', array_unique($tagUserIds) ?: [0]);
            });
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private function appendListDerivedFields(array $list): array
    {
        if ($list === []) {
            return [];
        }

        $userIds = array_column($list, 'id');
        $groupMap = $this->batchGetUserGroups($userIds);
        $tagMap = $this->batchGetUserTags($userIds);
        $walletMap = $this->batchGetWallets($userIds);

        $list = app()->make(AssetHydrator::class)->hydrateFields($list, [
            'avatar' => 'avatar_full_url',
        ]);

        foreach ($list as &$user) {
            $user['groups'] = $groupMap[$user['id']] ?? [];
            $user['tags'] = $tagMap[$user['id']] ?? [];
            $user['wallet'] = $walletMap[$user['id']] ?? [
                'balance' => '0.00',
                'frozen_amount' => '0.00',
            ];
        }
        unset($user);

        return $list;
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
     * @param array<int> $userIds
     * @return array<int, array{balance:string,frozen_amount:string}>
     */
    protected function batchGetWallets(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = $this->model(UserWallet::class)
            ->whereIn('user_id', $userIds)
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = [
                'balance' => number_format(((int) ($row['balance_cents'] ?? 0)) / 100, 2, '.', ''),
                'frozen_amount' => number_format(((int) ($row['frozen_cents'] ?? 0)) / 100, 2, '.', ''),
            ];
        }

        return $result;
    }

    /**
     * 获取用户详情
     */
    public function getInfo(int $id): array
    {
        $user = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $result = $user->toArray();
        $rows = app()->make(AssetHydrator::class)->hydrateFields([$result], [
            'avatar' => 'avatar_full_url',
        ]);
        $result = $rows[0] ?? $result;
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
     * 创建用户
     */
    public function create(array $data): int
    {
        // 业务校验（事务外）
        if (!empty($data['mobile'])) {
            $exists = $this->model()->where('mobile', $data['mobile'])->whereNull('delete_time')->find();
            if ($exists) {
                throw new BusinessException('该手机号已被注册');
            }
        }

        if (!empty($data['email'])) {
            $exists = $this->model()->where('email', $data['email'])->whereNull('delete_time')->find();
            if ($exists) {
                throw new BusinessException('该邮箱已被注册');
            }
        }

        // 事务内只做写入操作
        return $this->transaction(function () use ($data) {
            $user = $this->model();
            $user->save($data);

            if (!empty($data['group_ids']) && is_array($data['group_ids'])) {
                $this->syncUserGroups($user->id, $data['group_ids']);
            }

            if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
                $this->syncUserTags($user->id, $data['tag_ids']);
            }

            return $user->id;
        });
    }

    /**
     * 更新用户
     */
    public function update(int $id, array $data): bool
    {
        // 业务校验（事务外）
        $user = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        if (!empty($data['mobile'])) {
            $exists = $this->model()
                ->where('mobile', $data['mobile'])
                ->where('id', '<>', $id)
                ->whereNull('delete_time')
                ->find();
            if ($exists) {
                throw new BusinessException('该手机号已被使用');
            }
        }

        if (!empty($data['email'])) {
            $exists = $this->model()
                ->where('email', $data['email'])
                ->where('id', '<>', $id)
                ->whereNull('delete_time')
                ->find();
            if ($exists) {
                throw new BusinessException('该邮箱已被使用');
            }
        }

        // 事务内只做写入操作
        return $this->transaction(function () use ($id, $user, $data) {
            $user->save($data);

            if (array_key_exists('group_ids', $data) && is_array($data['group_ids'])) {
                $this->syncUserGroups($id, $data['group_ids']);
            }

            if (array_key_exists('tag_ids', $data) && is_array($data['tag_ids'])) {
                $this->syncUserTags($id, $data['tag_ids']);
            }

            return true;
        });
    }

    /**
     * 删除用户（清理关联数据）
     */
    public function delete(int $id): bool
    {
        // 业务校验（事务外）
        $user = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        // 事务内只做写入操作
        return $this->transaction(function () use ($id, $user) {
            $this->model(UserGroupRelation::class)->where('user_id', $id)->delete();
            $this->model(UserTagRelation::class)->where('user_id', $id)->delete();

            return $user->delete();
        });
    }

    /**
     * 更新用户状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $user = $this->model()->where('id', $id)->whereNull('delete_time')->find();

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
        $user = $this->model()->where('id', $id)->whereNull('delete_time')->find();

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
