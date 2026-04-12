<?php

declare (strict_types=1);

namespace app\admin\service\auth;

use app\admin\model\auth\Role as RoleModel;
use app\admin\model\auth\RolePermission;
use app\admin\model\auth\Permission;
use app\admin\service\cache\PermissionCacheService;
use app\admin\model\auth\Permission as PermissionModel;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 角色服务
 * @extends BaseService<RoleModel>
 */
class RoleService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = RoleModel::class;

    /**
     * 获取角色列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null, function ($q) use ($where) {
                $q->where('status', $where['status']);
            });

        $total = $query->count();
        $list = $query->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)->select()
            ->toArray();

        // 批量加载角色权限，避免 N+1 查询
        $roleIds = array_column($list, 'id');
        $permissionsByRoleId = [];
        if (!empty($roleIds)) {
            $rows = $this->model(RolePermission::class)
                ->alias('rp')
                ->leftJoin('permission p', 'rp.permission_id = p.id')
                ->whereIn('rp.role_id', $roleIds)
                ->field('rp.id as pivot_id, rp.role_id, rp.permission_id, rp.create_time as pivot_create_time, p.*')
                ->order('rp.id', 'asc')
                ->select()
                ->toArray();

            foreach ($rows as $item) {
                if (empty($item['id'])) {
                    continue;
                }
                $roleId = (int) $item['role_id'];
                $permission = $item;
                $permission['pivot'] = [
                    'id' => $item['pivot_id'],
                    'role_id' => $item['role_id'],
                    'permission_id' => $item['permission_id'],
                    'create_time' => $item['pivot_create_time'],
                ];
                unset($permission['pivot_id'], $permission['role_id'], $permission['pivot_create_time']);
                $permissionsByRoleId[$roleId][] = $permission;
            }
        }

        foreach ($list as &$role) {
            $rolePermissions = $permissionsByRoleId[(int) $role['id']] ?? [];
            $role['permissions'] = $rolePermissions;
            $role['permission_ids'] = array_column($rolePermissions, 'id');
        }

        return compact('total', 'list');
    }

    /**
     * 获取角色详情
     */
    public function getInfo(int $id): array
    {
        // 获取角色基本信息
        $role = $this->model()->find($id);
        if (!$role) {
            throw new BusinessException('角色不存在');
        }

        $info = $role->toArray();

        // 以 role_permission 为主表，使用 left join 查询获取权限列表
        $rolePermissions = $this->model(RolePermission::class)
            ->alias('rp')
            ->leftJoin('permission p', 'rp.permission_id = p.id')
            ->where('rp.role_id', $id)
            ->field('p.*')
            ->order('rp.id', 'asc')
            ->select()
            ->toArray();

        // 组装 pivot 数据
        $menuPermissions = [];
        // 按钮权限
        $buttonPermissions = [];
        // 接口权限
        $apiPermissions = [];
        foreach ($rolePermissions as $item) {
            if (!empty($item['id'])) {
                $permission = $item;
                unset($permission['pivot_id'], $permission['role_id'], $permission['pivot_create_time']);
                switch ($item['type']) {
                    case PermissionModel::TYPE_MENU:
                        $menuPermissions[] = $permission;
                        break;
                    case PermissionModel::TYPE_BUTTON:
                        $buttonPermissions[] = $permission;
                        break;
                    case PermissionModel::TYPE_API:
                        $apiPermissions[] = $permission;
                        break;
                }

            }
        }

        $info['menu_permission_ids'] = array_column($menuPermissions, 'id');
        $info['button_permission_ids'] = array_column($buttonPermissions, 'id');
        $info['api_permission_ids'] = array_column($apiPermissions, 'id');

        return $info;
    }

    /**
     * 获取所有角色（不分页）
     */
    public function getAll(): array
    {
        return $this->model()->where('status', 1)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 创建角色
     */
    public function create(array $data): int
    {
        // 检查角色编码是否存在
        if ($this->model()->where('code', $data['code'])->find()) {
            throw new BusinessException('角色编码已存在');
        }

        return $this->transaction(function () use ($data) {
            $role = $this->model();
            $role->save([
                'name' => $data['name'],
                'code' => $data['code'],
                'remark' => $data['remark'] ?? '',
                'status' => $data['status'] ?? 1,
                'sort' => $data['sort'] ?? 0,
            ]);

            $permissionIds = array_merge(
                $data['menu_permission_ids'] ?? [],
                $data['button_permission_ids'] ?? [],
                $data['api_permission_ids'] ?? []
            );
            // 分配权限
            if (!empty($permissionIds)) {
                $this->assignPermissions($role->id, $permissionIds);
            }

            return $role->id;
        });
    }

    /**
     * 更新角色
     */
    public function update(int $id, array $data): bool
    {
        $role = $this->model()->find($id);
        if (!$role) {
            throw new BusinessException('角色不存在');
        }

        // 检查角色编码是否重复
        if (isset($data['code']) && $data['code'] !== $role->code) {
            if ($this->model()->where('code', $data['code'])->where('id', '<>', $id)->find()) {
                throw new BusinessException('角色编码已存在');
            }
        }

        // 重新分配权限
        $permissionIds = array_merge(
            $data['menu_permission_ids'] ?? [],
            $data['button_permission_ids'] ?? [],
            $data['api_permission_ids'] ?? []
        );
        unset($data['menu_permission_ids'], $data['button_permission_ids'], $data['api_permission_ids']);
        return $this->transaction(function () use ($id, $data, $permissionIds) {

            $this->assignPermissions($id, $permissionIds);

            $this->model()->updateById($id, $data);

            // 清除拥有该角色的用户权限缓存
            $this->clearRoleUsersCache($id);

            return true;
        });
    }

    public function changeStatus(int $id, int $status): bool
    {
        $role = $this->model()->find($id);
        if (!$role) {
            throw new BusinessException('角色不存在');
        }

        $this->model()->updateById($id, ['status' => $status]);
        return true;
    }

    /**
     * 删除角色
     */
    public function delete(int $id): bool
    {
        // 不允许删除超级管理员角色
        if ($id === 1) {
            throw new BusinessException('不能删除超级管理员角色');
        }

        $role = $this->model()->find($id);
        if (!$role) {
            throw new BusinessException('角色不存在');
        }

        // 删除权限关联
        $this->model(RolePermission::class)->where('role_id', $id)->delete();

        // 删除角色
        $role->delete();

        // 清除拥有该角色的用户权限缓存
        $this->clearRoleUsersCache($id);

        return true;
    }

    /**
     * 分配权限（内部方法，不使用事务，由调用方控制）
     */
    public function assignPermissions(int $roleId, array $permissionIds): void
    {
        // 删除原有权限
        $this->model(RolePermission::class)->where('role_id', $roleId)->delete();

        // 批量分配新权限
        if (!empty($permissionIds)) {
            $insertData = [];
            foreach ($permissionIds as $permissionId) {
                $insertData[] = [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId
                ];
            }
            $this->model(RolePermission::class)->insertAll($insertData);
        }

        // 清除拥有该角色的用户权限缓存
        $this->clearRoleUsersCache($roleId);
    }

    /**
     * 清除拥有指定角色的所有用户权限缓存
     *
     * @param int $roleId 角色ID
     */
    protected function clearRoleUsersCache(int $roleId): void
    {
        // 获取拥有该角色的所有用户ID
        $adminIds = $this->model(\app\admin\model\auth\AdminRole::class)
            ->where('role_id', $roleId)
            ->column('admin_id');

        if (!empty($adminIds)) {
            $cacheService = new PermissionCacheService();
            $cacheService->clearUsers($adminIds);
        }
    }
}
