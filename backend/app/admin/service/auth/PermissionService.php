<?php

declare (strict_types=1);

namespace app\admin\service\auth;

use app\model\auth\Admin;
use app\model\auth\AdminRole;
use app\model\auth\Permission as PermissionModel;
use app\model\auth\RolePermission;
use app\admin\service\cache\PermissionCacheService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 权限服务
 * @extends BaseService<PermissionModel>
 */
class PermissionService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = PermissionModel::class;

    /**
     * 获取权限树形列表
     */
    public function getTree(array $where = []): array
    {

        $list = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', "%{$where['keyword']}%");
            })
            ->when(($where['type'] ?? null) !== null, function ($q) use ($where) {
                $q->where('type', $where['type']);
            })
            ->when(($where['status'] ?? null) !== null, function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(($where['source'] ?? null) !== null, function ($q) use ($where) {
                $q->where('source', $where['source']);
            })
            ->when(!empty($where['component_empty']), function ($q) {
                $q->where(function ($q) {
                    $q->whereNull('component')->whereOr('component', '');
                });
            })
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return $this->buildTree($list);
    }

    public function getMenu(int $adminId): array
    {
        if ($adminId === Admin::SUPER_ADMIN_ID) {
            $query = $this->model()->alias('p')
                ->where(['status' => 1, 'type' => 1])->order(['sort' => 'asc', 'id' => 'asc']);
        } else {
            $query = $this->model()->alias('p')
                ->join('role_permission rp', 'rp.permission_id = p.id')
                ->join('admin_role ar', 'ar.role_id = rp.role_id')
                ->where('ar.admin_id', $adminId)
                ->where(['p.status' => 1, 'p.type' => 1])->order(['p.sort' => 'asc', 'p.id' => 'asc']);
        }

        $list = $query->field('p.*')->select()->toArray();
        $tree = $this->buildTree($list);
        $routes = $this->transformToRoutes($tree);

        // 递归查找第一个有 path 的菜单作为默认首页
        $homePath = $this->findFirstPath($routes);

        return [
            'home_path' => $homePath ?: '/workspace',
            'routes' => $routes,
        ];
    }


    /**
     * 获取管理员权限（权限码列表）
     */
    public function getAccessCodes(int $adminId): array
    {

        if ($adminId == Admin::SUPER_ADMIN_ID) {
            $codes = $this->model()->whereIn('type', [PermissionModel::TYPE_BUTTON, PermissionModel::TYPE_API])->where('status', 1)->column('code');
        } else {
            // 获取管理员的角色ID
            $roleIds = $this->model(AdminRole::class)
                ->where('admin_id', $adminId)
                ->column('role_id');

            if (empty($roleIds)) {
                return ['access_codes' => []];
            }

            // 获取这些角色的所有权限码
            $codes = $this->model()
                ->alias('p')
                ->leftJoin('role_permission rp', 'rp.permission_id = p.id')
                ->whereIn('rp.role_id', $roleIds)
                ->whereIn('p.type', [PermissionModel::TYPE_BUTTON, PermissionModel::TYPE_API])
                ->where('p.status', 1)
                ->column('p.code');
        }


        return ['access_codes' => array_values(array_unique($codes))];
    }

    /**
     * 获取权限列表（分页）
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    /**
     * 构建权限列表查询（list/total 条件同源）
     */
    protected function buildListQuery(array $where)
    {
        $query = $this->model();

        if (!empty($where['keyword'])) {
            $query->whereLike('name|code', "%{$where['keyword']}%");
        }

        if (($where['type'] ?? null) !== null) {
            $query->where('type', $where['type']);
        }

        if (array_key_exists('status', $where) && $where['status'] !== null && $where['status'] !== '') {
            $query->where('status', $where['status']);
        }

        return $query;
    }

    /**
     * 获取权限详情
     */
    public function getInfo(int $id): array
    {
        $permission = $this->model()->find($id);

        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        return $permission->toArray();
    }

    /**
     * 创建权限
     */
    public function create(array $data): int
    {
        // 检查权限编码是否存在
        if ($this->model()->where('code', $data['code'])->find()) {
            throw new BusinessException('权限编码已存在');
        }

        return $this->transaction(function () use ($data) {
            $permission = $this->model();
            $permission->save([
                'parent_id' => $data['parent_id'] ?? 0,
                'name' => $data['name'],
                'code' => $data['code'],
                'type' => $data['type'] ?? 1,
                'path' => $data['path'] ?? '',
                'icon' => $data['icon'] ?? '',
                'component' => $data['component'] ?? '',
                'redirect' => $data['redirect'] ?? '',
                'affix_tab' => $data['affix_tab'] ?? 0,
                'no_basic_layout' => $data['no_basic_layout'] ?? 0,
                'sort' => $data['sort'] ?? 0,
                'status' => $data['status'] ?? 1,
                'is_show' => $data['is_show'] ?? 1,
                'source' => PermissionModel::SOURCE_MANUAL,
                'remark' => $data['remark'] ?? '',
            ]);

            // 清除所有用户权限缓存
            $this->clearAllUserPermissionCache();

            return $permission->id;
        });
    }

    /**
     * 更新权限
     */
    public function update(int $id, array $data): bool
    {
        $permission = $this->model()->find($id);
        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        // 不允许将父级设置为自己或自己的子级
        if (isset($data['parent_id']) && $data['parent_id'] == $id) {
            throw new BusinessException('不能将自己设置为父级');
        }

        // 检查权限编码是否重复
        if (!empty($data['code']) && $data['code'] !== $permission->code) {
            if ($this->model()->where('code', $data['code'])->where('id', '<>', $id)->find()) {
                throw new BusinessException('权限编码已存在');
            }
        }

        $this->model()->updateById($id, $data);

        // 清除所有用户权限缓存
        $this->clearAllUserPermissionCache();

        return true;
    }

    /**
     * 删除权限（级联删除子权限）
     */
    public function delete(int $id): bool
    {
        $permission = $this->model()->find($id);
        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        // 获取所有子权限ID（递归）
        $childIds = $this->getAllChildIds($id);

        // 合并要删除的ID：当前权限 + 所有子权限
        $deleteIds = array_merge([$id], $childIds);

        // 开启事务
        return $this->transaction(function () use ($deleteIds) {
            // 1. 批量软删除权限
            $this->model()->whereIn('id', $deleteIds)->delete();

            // 2. 删除角色权限关联数据
            $this->model(RolePermission::class)->whereIn('permission_id', $deleteIds)->delete();

            // 3. 清除所有用户权限缓存
            $this->clearAllUserPermissionCache();

            return true;
        });
    }

    /**
     * 获取所有子节点 ID（递归）
     */
    public function getAllChildIds(int $parentId): array
    {
        $ids = [];
        $children = $this->model()->where('parent_id', $parentId)->column('id');

        if (!empty($children)) {
            $ids = array_merge($ids, $children);
            foreach ($children as $childId) {
                $ids = array_merge($ids, $this->getAllChildIds($childId));
            }
        }

        return $ids;
    }

    /**
     * 批量更新字段（可选包含所有子节点）
     */
    public function batchUpdateField(int $id, string $field, $value, bool $includeChildren = true): bool
    {
        $permission = $this->model()->find($id);
        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        $updateData = [$field => $value];
        $updateIds = [$id];

        if ($includeChildren) {
            $updateIds = array_merge($updateIds, $this->getAllChildIds($id));
        }

        return $this->transaction(function () use ($updateIds, $updateData) {
            $this->model()->whereIn('id', array_values(array_unique($updateIds)))->update($updateData);

            // 统一清缓存，避免逐条更新导致频繁清理
            $this->clearAllUserPermissionCache();
            return true;
        });
    }

    /**
     * 构建树形结构
     */
    protected function buildTree(array $list, int $parentId = 0): array
    {
        $tree = [];
        foreach ($list as $item) {
            if ($item['parent_id'] == $parentId) {
                $children = $this->buildTree($list, $item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }


    /**
     * 转换为前端路由格式
     */
    protected function transformToRoutes($nodes)
    {
        $routes = [];
        foreach ($nodes as $node) {
            // 跳过空数据
            if (empty($node['code']) || empty($node['name'])) {
                continue;
            }

            $route = [
                'name' => convertToRouteName($node['code']),
                'path' => $node['path'] ?: '/' . strtolower($node['code']),
                'meta' => [
                    'title' => $node['name'],
                    'hideInMenu' => $node['is_show'] === 0,
                ],
            ];

            // 如果有图标，添加到 meta
            if (!empty($node['icon'])) {
                $route['meta']['icon'] = $node['icon'];
            }

            // 如果有排序，添加到 meta
//            if (!empty($node['sort'])) {
//                $route['meta']['order'] = (int)$node['sort'];
//            }

            // 如果需要固定标签页，添加 affixTab
            if (!empty($node['affix_tab']) && $node['affix_tab'] == 1) {
                $route['meta']['affixTab'] = true;
            }

            // 如果有组件路径，添加 component
            if (!empty($node['component'])) {
                // 移除 views/ 前缀和 .vue 后缀，只保留相对路径
//                $component = $node['component'];
//                if (strpos($component, 'views/') === 0) {
//                    $component = substr($component, 6); // 移除 "views/" 前缀
//                }
                // 移除 .vue 后缀
//                $component = str_replace('.vue', '', $component);
//                // 添加前缀斜杠
//                $route['component'] = '/' . $component;
                $route['component'] = $node['component'];
            }

            // 如果有 redirect，添加到路由
            if (!empty($node['redirect'])) {
                $route['redirect'] = $node['redirect'];
            }

            // 处理特殊配置（如 noBasicLayout）
            if (!empty($node['no_basic_layout']) && $node['no_basic_layout'] == 1) {
                $route['meta']['noBasicLayout'] = true;
            }

            // 如果有子节点，递归处理
            if (!empty($node['children']) && is_array($node['children'])) {
                $children = $this->transformToRoutes($node['children']);
                if (!empty($children)) {
                    $route['children'] = $children;
                }
            }

            $routes[] = $route;
        }
        return $routes;
    }

    /**
     * 递归查找第一个有效的 path
     *
     * @param array $routes 路由数组
     * @return string|null
     */
    protected function findFirstPath(array $routes): ?string
    {
        foreach ($routes as $route) {
            if (!empty($route['path']) && strpos($route['path'], '/') === 0) {
                return $route['path'];
            }
            // 递归查找子路由
            if (!empty($route['children']) && is_array($route['children'])) {
                $path = $this->findFirstPath($route['children']);
                if ($path !== null) {
                    return $path;
                }
            }
        }
        return null;
    }

    /**
     * 清除所有用户权限缓存
     */
    protected function clearAllUserPermissionCache(): void
    {
        $cacheService = new PermissionCacheService();
        $cacheService->clearAll();
    }
}
