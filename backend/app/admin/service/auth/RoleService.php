<?php

declare (strict_types=1);

namespace app\admin\service\auth;

use app\admin\model\auth\Role as RoleModel;
use app\admin\model\auth\RolePermission;
use app\admin\model\auth\Permission;
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
        $keyword = $where['keyword'] ?? '';
        $status = $where['status'] ?? null;

        $query = $this->model()->with(['permissions']);

        // 关键字搜索
        if ($keyword) {
            $query->whereLike('name|code', "%{$keyword}%");
        }

        // 状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)->select();

        return compact('total', 'list');
    }

    /**
     * 获取角色详情
     */
    public function getInfo(int $id): array
    {
        $role = $this->model()->with(['permissions'])->find($id);

        if (!$role) {
            throw new BusinessException('角色不存在');
        }

        $info = $role->toArray();

        // 获取权限ID列表
        $info['permission_ids'] = array_column($role['permissions'] ?? [], 'id');

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

            // 分配权限
            if (!empty($data['permission_ids'])) {
                $this->assignPermissions($role->id, $data['permission_ids']);
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
        return $this->transaction(function () use ($id, $data) {
            $this->model()->updateById($id, $data);
            // 重新分配权限
            if (!empty($data['permission_ids'])) {
                $this->assignPermissions($id, $data['permission_ids']);
            }

            return true;
        });
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
                    'permission_id' => $permissionId,
                    'create_time' => date('Y-m-d H:i:s'),
                ];
            }
            $this->model(RolePermission::class)->insertAll($insertData);
        }
    }
}
