<?php

declare (strict_types=1);

namespace app\service\admin\auth;

use app\model\auth\Admin as AdminModel;
use app\model\auth\AdminRole;
use app\service\cache\PermissionCacheService;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\service\JwtCacheService;
use mall_base\service\JwtService;
use think\facade\Request;

/**
 * 管理员服务
 * @extends BaseService<AdminModel>
 */
class AdminService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = AdminModel::class;

    /**
     * 管理员登录
     */
    public function login(string $username, string $password): array
    {
        $admin = $this->model()->where('username', $username)
            ->where('status', 1)
            ->find();

        if (!$admin) {
            throw new BusinessException('账号不存在或已禁用');
        }

        if (!$admin->checkPassword($password)) {
            throw new BusinessException('密码错误');
        }
        // 更新登录信息
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->last_login_ip = Request::ip();
        $admin->save();

        // 生成 JWT Token（encode 自动生成 access_token + refresh_token）
        $jwtService = app()->make(JwtService::class);
        $token = $jwtService->encode([
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
        ]);

        // 存储 refresh_token 到 Redis
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $admin->id,
            $jwtService->getRefreshExpire()
        );

        return $token;
    }

    /**
     * 获取管理员列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('username|nickname|mobile|email', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null, function ($q) use ($where) {
                $q->where('status', $where['status']);
            });

        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)->select()
            ->toArray();

        // 批量加载管理员角色，避免 N+1 查询
        $adminIds = array_column($list, 'id');
        $rolesByAdminId = [];
        if (!empty($adminIds)) {
            $rows = $this->model(AdminRole::class)
                ->alias('ar')
                ->leftJoin('role r', 'ar.role_id = r.id')
                ->whereIn('ar.admin_id', $adminIds)
                ->field('ar.id as pivot_id, ar.admin_id, ar.role_id, ar.create_time as pivot_create_time, r.*')
                ->order('ar.id', 'asc')
                ->select()
                ->toArray();

            foreach ($rows as $item) {
                if (empty($item['id'])) {
                    continue;
                }
                $adminId = (int) $item['admin_id'];
                $role = $item;
                $role['pivot'] = [
                    'id' => $item['pivot_id'],
                    'admin_id' => $item['admin_id'],
                    'role_id' => $item['role_id'],
                    'create_time' => $item['pivot_create_time'],
                ];
                unset($role['pivot_id'], $role['admin_id'], $role['pivot_create_time']);
                $rolesByAdminId[$adminId][] = $role;
            }
        }

        foreach ($list as &$admin) {
            unset($admin['password']);
            $adminRoles = $rolesByAdminId[(int) $admin['id']] ?? [];
            $admin['roles'] = $adminRoles;
            $admin['role_ids'] = array_column($adminRoles, 'id');
        }
        unset($admin);

        $list = app()->make(AssetHydrator::class)->hydrateFields($list, [
            'avatar' => 'avatar_full_url',
        ]);

        return compact('total', 'list');
    }

    /**
     * 获取管理员详情
     */
    public function getInfo(int $id): array
    {
        $admin = $this->model()->find($id);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        $info = $admin->toArray();
        unset($info['password']);
        $rows = app()->make(AssetHydrator::class)->hydrateFields([$info], [
            'avatar' => 'avatar_full_url',
        ]);
        $info = $rows[0] ?? $info;

        // 手动加载角色列表
        $admin['roles'] = [];
        $admin['role_ids'] = [];

        $adminRoles = $this->model(AdminRole::class)
            ->alias('ar')
            ->leftJoin('role r', 'ar.role_id = r.id')
            ->where('ar.admin_id', $id)
            ->field('ar.id as pivot_id, ar.admin_id, ar.role_id, ar.create_time as pivot_create_time, r.*')
            ->order('ar.id', 'asc')
            ->select()
            ->toArray();

        foreach ($adminRoles as $item) {
            if (!empty($item['id'])) {
                $role = $item;
                $role['pivot'] = [
                    'id' => $item['pivot_id'],
                    'admin_id' => $item['admin_id'],
                    'role_id' => $item['role_id'],
                    'create_time' => $item['pivot_create_time'],
                ];
                unset($role['pivot_id'], $role['admin_id'], $role['pivot_create_time']);
                $info['roles'][] = $role;
            }
        }

        // 获取角色ID列表
        $info['role_ids'] = array_column($info['roles'] ?? [], 'id');
        $info['home_path'] = app()->make(PermissionService::class)->getMenu($id)['home_path'] ?? '/workspace';

        return $info;
    }

    /**
     * 创建管理员
     */
    public function create(array $data): int
    {
        // 检查用户名是否存在
        if ($this->model()->where('username', $data['username'])->find()) {
            throw new BusinessException('用户名已存在');
        }

        return $this->transaction(function () use ($data) {
            $admin = $this->model();
            $admin->save([
                'username' => $data['username'],
                'password' => $data['password'],
                'nickname' => $data['nickname'] ?? '',
                'avatar' => $data['avatar'] ?? '',
                'email' => $data['email'] ?? '',
                'mobile' => $data['mobile'] ?? '',
                'status' => $data['status'] ?? 1,
                'password_changed_at' => date('Y-m-d H:i:s'),
                'remark' => $data['remark'] ?? '',
            ]);

            // 分配角色
            if (!empty($data['role_ids'])) {
                $this->assignRoles($admin->id, $data['role_ids']);
            }

            return $admin->id;
        });
    }

    /**
     * 更新管理员
     */
    public function update(int $id, array $data): bool
    {
        $admin = $this->model()->find($id);
        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        if ($id === $this->model()::SUPER_ADMIN_ID) {
            throw new BusinessException('系统管理员不允许在管理员管理中修改');
        }

        // 检查用户名是否重复
        if (!empty($data['username']) && $data['username'] !== $admin->username) {
            $exists = $this->model()
                ->where('username', $data['username'])
                ->where('id', '<>', $id)
                ->value('id');

            if ($exists) {
                throw new BusinessException('用户名已存在');
            }
        }
        return $this->transaction(function () use ($id, $data) {
            $role_ids = $data['role_ids'] ?? [];
            unset($data['role_ids']);
            $this->model()->updateById($id, $data);
            // 重新分配角色
            $this->assignRoles($id, $role_ids);

            // 清除该用户的权限缓存
            $this->clearUserPermissionCache($id);

            return true;
        });
    }

    /**
     *  修改管理员信息
     */
    public function adminUpdate(int $id, array $data): bool
    {
        $admin = $this->model()->find($id);
        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        $this->model()->updateById($id, $data);

        return true;
    }

    public function changeStatus(int $id, int $status): bool
    {
        $role = $this->model()->find($id);
        if (!$role) {
            throw new BusinessException('管理员不存在');
        }

        if ($id === $this->model()::SUPER_ADMIN_ID && $status !== 1) {
            throw new BusinessException('不能禁用超级管理员');
        }

        $this->model()->updateById($id, ['status' => $status]);
        return true;
    }

    /**
     * 删除管理员
     */
    public function delete(int $id): bool
    {
        // 不允许删除超级管理员
        if ($id === $this->model()::SUPER_ADMIN_ID) {
            throw new BusinessException('不能删除超级管理员');
        }

        $admin = $this->model()->find($id);
        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 删除角色关联
        $this->model(AdminRole::class)->where('admin_id', $id)->delete();

        // 删除管理员
        $admin->delete();

        // 清除该用户的权限缓存
        $this->clearUserPermissionCache($id);

        return true;
    }

    /**
     * 分配角色（内部方法，不使用事务，由调用方控制）
     */
    public function assignRoles(int $adminId, array $roleIds): void
    {
        // 删除原有角色
        $this->model(AdminRole::class)->where('admin_id', $adminId)->delete();

        if ($adminId === $this->model()::SUPER_ADMIN_ID) {
            $this->clearUserPermissionCache($adminId);
            return;
        }

        // 批量分配新角色
        if (!empty($roleIds)) {
            $insertData = [];
            foreach ($roleIds as $roleId) {
                $insertData[] = [
                    'admin_id' => $adminId,
                    'role_id' => $roleId,
                    'create_time' => date('Y-m-d H:i:s'),
                ];
            }
            $this->model(AdminRole::class)->insertAll($insertData);
        }

        // 清除该用户的权限缓存
        $this->clearUserPermissionCache($adminId);
    }

    /**
     * 清除指定用户的权限缓存
     *
     * @param int $adminId 管理员ID
     */
    protected function clearUserPermissionCache(int $adminId): void
    {
        $cacheService = app()->make(PermissionCacheService::class);
        $cacheService->clearUser($adminId);
    }


    /**
     * 重置密码
     */
    public function resetPassword(int $id, array|string $data): bool
    {
        $admin = $this->model()->find($id);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        if ($id === $this->model()::SUPER_ADMIN_ID && is_string($data)) {
            throw new BusinessException('系统管理员不允许在管理员管理中重置密码');
        }

        if (is_array($data) && !$admin->checkPassword($data['old_password'])) {
            throw new BusinessException('旧密码错误');
        }
        // 使用模型属性赋值，会触发 setPasswordAttr 修改器
        $admin->password = is_array($data) ? $data['password'] : $data;
        // 写入最近改密时间；后续主动改密也统一刷新该字段。
        $admin->password_changed_at = date('Y-m-d H:i:s');
        return $admin->save();
    }

    /**
     * 构建菜单树
     */
    protected function buildMenuTree(array $menus, int $parentId = 0): array
    {
        $tree = [];
        foreach ($menus as $menu) {
            if ($menu['parent_id'] == $parentId) {
                $menu['children'] = $this->buildMenuTree($menus, $menu['id']);
                $tree[] = $menu;
            }
        }
        return $tree;
    }

    /**
     * 将权限转换为路由格式
     */
    protected function convertToRoute(array $permission): array
    {
        return [
            'path' => $permission['path'] ?? '',
            'name' => $permission['code'] ?? '',
            'component' => $permission['component'] ?? '',
            'meta' => [
                'title' => $permission['name'] ?? '',
                'icon' => $permission['icon'] ?? '',
                'order' => $permission['sort'] ?? 0,
                'show' => $permission['is_show'] == 1,
            ],
        ];
    }


    /**
     * 登出
     */
    public function logout(int $adminId): void
    {
        // 撤销 refresh_token
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->revokeRefreshToken($adminId);

        // 清除该用户的权限缓存
        $this->clearUserPermissionCache($adminId);
    }

    /**
     * 刷新 Token
     */
    public function refreshToken(string $refreshToken): array
    {
        $jwtService = app()->make(JwtService::class);

        // 解析 refresh_token
        try {
            $decoded = $jwtService->decode($refreshToken);
            $payload = $decoded->data;
        } catch (\Exception $e) {
            throw new BusinessException('刷新令牌无效或已过期');
        }

        // 验证是否为 refresh 类型的 token
        if (!isset($payload->type) || $payload->type !== 'refresh') {
            throw new BusinessException('刷新令牌类型错误');
        }

        // 验证用户是否存在且启用
        $admin = $this->model()
            ->where('id', $payload->admin_id)
            ->where('status', 1)
            ->find();

        if (!$admin) {
            throw new BusinessException('用户不存在或已禁用');
        }

        // 验证 refresh_token 是否在 Redis 中（防止已登出的 token 被复用）
        $jwtCacheService = app()->make(JwtCacheService::class);
        if (!$jwtCacheService->verifyRefreshToken($refreshToken, $admin->id)) {
            throw new BusinessException('刷新令牌已失效');
        }

        // 撤销旧的 refresh_token
        $jwtCacheService->revokeRefreshToken($admin->id);

        // 生成新的 token 对
        $token = $jwtService->encode([
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
        ]);

        // 存储新的 refresh_token 到 Redis
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $admin->id,
            $jwtService->getRefreshExpire()
        );

        return $token;
    }
}
