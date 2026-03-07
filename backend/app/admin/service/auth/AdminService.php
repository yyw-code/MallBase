<?php

declare (strict_types=1);

namespace app\admin\service\auth;

use app\admin\model\auth\Admin as AdminModel;
use app\admin\model\auth\AdminRole;
use app\admin\model\auth\Role;
use mall_base\base\BaseService;
use mall_base\exception\AuthException;
use mall_base\exception\BusinessException;
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

        // 生成 JWT Token
        $jwtService = new JwtService();

        // 生成 access_token（短有效期）
        $accessToken = $jwtService->encode([
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
            'type' => 'access',
        ]);

        // 生成 refresh_token（长有效期，30天）
        $refreshToken = $jwtService->encodeWithExpire([
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
            'type' => 'refresh',
        ], 30 * 24 * 3600); // 30天

        // 获取过期时间（秒）
        $expiresIn = config('jwt.expire', 7200);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * 获取管理员列表
     */
    public function getList(array $params = [], int $page = 1, int $limit = 10): array
    {
        $keyword = $params['keyword'] ?? '';
        $status = $params['status'] ?? null;

        $query = $this->model()->with(['roles']);

        // 关键字搜索
        if ($keyword) {
            $query->whereLike('username|nickname|mobile|email', "%{$keyword}%");
        }

        // 状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)->select();

        // 隐藏密码
        foreach ($list as $item) {
            unset($item['password']);
        }

        return compact('total', 'list');
    }

    /**
     * 获取管理员详情
     */
    public function getInfo(int $id): array
    {
        $admin = $this->model()->with(['roles'])->find($id);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        $info = $admin->toArray();
        unset($info['password']);

        // 获取角色ID列表
        $info['role_ids'] = array_column($info['roles'] ?? [], 'id');
        $info['home_path'] = '/workspace';

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
            $this->model()->updateById($id, $data);
            // 重新分配角色
            if (!empty($data['role_ids'])) {
                $this->assignRoles($id, $data['role_ids']);
            }

            return true;
        });
    }

    /**
     * 删除管理员
     */
    public function delete(int $id): bool
    {
        // 不允许删除超级管理员
        if ($id === 1) {
            throw new BusinessException('不能删除超级管理员');
        }

        $admin = $this->model()->find($id);
        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 删除角色关联
        think\facade\Db::name('admin_role')->where('admin_id', $id)->delete();

        // 删除管理员
        $admin->delete();

        return true;
    }

    /**
     * 分配角色（内部方法，不使用事务，由调用方控制）
     */
    public function assignRoles(int $adminId, array $roleIds): void
    {
        // 删除原有角色
        think\facade\Db::name('admin_role')->where('admin_id', $adminId)->delete();

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
            think\facade\Db::name('admin_role')->insertAll($insertData);
        }
    }

    /**
     * 生成 JWT Token
     */
    protected function generateToken(AdminModel $admin): string
    {
        $jwtService = new JwtService();

        $payload = [
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
        ];

        return $jwtService->encode($payload);
    }

    /**
     * 重置密码
     */
    public function resetPassword(int $id, string $newPassword): bool
    {
        $admin = $this->model()->find($id);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 使用模型属性赋值，会触发 setPasswordAttr 修改器
        $admin->password = $newPassword;
        return $admin->save();
    }

    /**
     * 获取管理员权限（权限码列表）
     */
    public function getAccessCodes(int $adminId): array
    {
        $admin = $this->model()->with(['roles.permissions'])->find($adminId);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        $codes = [];
        foreach ($admin['roles'] ?? [] as $role) {
            foreach ($role['permissions'] ?? [] as $permission) {
                $codes[] = $permission['code'];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * 获取管理员菜单
     */
    public function getAccessMenus(int $adminId): array
    {
        $admin = $this->model()->with(['roles.permissions'])->find($adminId);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 获取所有菜单类型权限
        $menus = [];
        foreach ($admin['roles'] ?? [] as $role) {
            foreach ($role['permissions'] ?? [] as $permission) {
                if ($permission['status'] == 1 && $permission['type'] == 1) {
                    $menus[] = $permission;
                }
            }
        }

        // 构建树形结构
        return $this->buildMenuTree($menus);
    }

    /**
     * 获取管理员路由
     */
    public function getAccessRoutes(int $adminId): array
    {
        $admin = $this->model()->with(['roles.permissions'])->find($adminId);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 获取所有路由权限
        $routes = [];
        foreach ($admin['roles'] ?? [] as $role) {
            foreach ($role['permissions'] ?? [] as $permission) {
                if ($permission['status'] == 1) {
                    $routes[] = $this->convertToRoute($permission);
                }
            }
        }

        return $routes;
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
     * 获取管理员所有权限信息（权限码、菜单、路由）
     */
    public function getAccessInfo(int $adminId): array
    {
        return [
            'access_codes' => $this->getAccessCodes($adminId),
            'access_menus' => $this->getAccessMenus($adminId),
            'access_routes' => $this->getAccessRoutes($adminId),
        ];
    }

    /**
     * 刷新 Token
     */
    public function refreshToken(string $refreshToken): array
    {
        $jwtService = new JwtService();

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

        // 生成新的 access_token
        $newAccessToken = $jwtService->encode([
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
            'type' => 'access',
        ]);

        // 生成新的 refresh_token
        $newRefreshToken = $jwtService->encodeWithExpire([
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
            'type' => 'refresh',
        ], 30 * 24 * 3600); // 30天

        // 获取过期时间（秒）
        $expiresIn = config('jwt.expire', 7200);

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => $expiresIn,
        ];
    }
}
