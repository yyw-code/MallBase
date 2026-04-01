<?php

declare (strict_types=1);

namespace app\admin\service\setting;

use app\admin\model\auth\Permission;
use app\admin\model\setting\Setting;
use app\admin\model\setting\SettingGroup;
use app\admin\service\cache\SettingCacheService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 设置服务（分组 + 设置项 + 权限同步 统一管理）
 * @extends BaseService<SettingGroup>
 */
class SettingService extends BaseService
{
    /**
     * 权限 code 前缀
     */
    const PERMISSION_CODE_PREFIX = 'SettingGroup:';

    /**
     * 设置页面组件路径
     */
    const SETTING_COMPONENT = '/settings/dynamic-form/index';

    /**
     * Model 类名
     */
    protected string $modelClass = SettingGroup::class;

    /**
     * 缓存服务
     */
    protected SettingCacheService $cacheService;

    public function __construct()
    {
        $this->cacheService = new SettingCacheService();
    }

    // ==================== 分组管理 ====================

    /**
     * 获取分组列表（支持树形结构）
     */
    public function getGroupList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null, function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(!empty($where['parent_id']), function ($q) use ($where) {
                $q->where('parent_id', (int)$where['parent_id']);
            });

        $total = $query->count();
        $list = $query->order('sort', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    /**
     * 获取分组树形列表（不分页，返回树形结构）
     */
    public function getGroupTree(array $where = []): array
    {
        $list = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null, function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        return SettingGroup::toTree($list);
    }

    /**
     * 获取所有启用的分组（不分页，树形结构，用于下拉选择等）
     */
    public function getAllGroups(): array
    {
        return $this->cacheService->getAllGroups(function () {
            $list = $this->model()
                ->where('status', 1)
                ->order('sort', 'asc')
                ->select()
                ->toArray();

            return SettingGroup::toTree($list);
        });
    }

    /**
     * 创建分组（同时同步权限到 mb_permission）
     */
    public function createGroup(array $data): int
    {
        // 检查编码是否重复
        if ($this->model()->where('code', $data['code'])->find()) {
            throw new BusinessException('分组编码已存在');
        }

        // 检查权限编码是否重复
        $permissionCode = self::PERMISSION_CODE_PREFIX . $data['code'];
        if ($this->model(Permission::class)->where('code', $permissionCode)->find()) {
            throw new BusinessException('权限编码已存在');
        }

        // 检查父级分组是否存在
        $parentId = (int)($data['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parentGroup = $this->model()->find($parentId);
            if (!$parentGroup) {
                throw new BusinessException('父级分组不存在');
            }
        }

        // 获取顶级分组的父菜单权限ID
        $menuParentPermissionId = (int)($data['menu_parent_permission_id'] ?? 0);

        $group = $this->model();
        $group->save([
            'parent_id'   => $parentId,
            'name'        => $data['name'],
            'code'        => $data['code'],
            'icon'        => $data['icon'] ?? '',
            'description' => $data['description'] ?? '',
            'sort'        => $data['sort'] ?? 0,
            'status'      => $data['status'] ?? 1,
        ]);

        // 同步创建权限
        $this->syncCreatePermission($group, $menuParentPermissionId);

        $this->cacheService->clearAll();

        return $group->id;
    }

    /**
     * 更新分组（同时同步权限）
     */
    public function updateGroup(int $id, array $data): bool
    {
        $group = $this->model()->find($id);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        // 检查编码是否重复
        if (!empty($data['code']) && $data['code'] !== $group->code) {
            if ($this->model()->where('code', $data['code'])->find()) {
                throw new BusinessException('分组编码已存在');
            }
        }

        // 检查 parent_id 是否合法（不能将自己设为父级，也不能形成循环）
        if (isset($data['parent_id'])) {
            $parentId = (int)$data['parent_id'];
            if ($parentId === $id) {
                throw new BusinessException('不能将自己设为父级分组');
            }
            if ($parentId > 0 && $this->isChildOf($parentId, $id)) {
                throw new BusinessException('不能将子分组设为父级分组，会形成循环引用');
            }
        }

        // 更新分组数据
        $updateData = array_intersect_key($data, array_flip([
            'parent_id', 'name', 'code', 'icon', 'description', 'sort', 'status',
        ]));
        $group->save($updateData);

        // 获取顶级分组的父菜单权限ID
        $menuParentPermissionId = (int)($data['menu_parent_permission_id'] ?? 0);

        // 同步更新权限
        $this->syncUpdatePermission($group, $menuParentPermissionId);

        $this->cacheService->clearAll();

        return true;
    }

    /**
     * 删除分组（递归删除子分组、设置项、权限）
     */
    public function deleteGroup(int $id): bool
    {
        $group = $this->model()->find($id);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        return $this->transaction(function () use ($id, $group) {
            // 收集所有需要删除的分组ID（包含子分组）
            $groupIds = $this->getAllChildIds($id);
            $groupIds[] = $id;

            // 收集所有对应的权限ID
            $permissionIds = $this->model()
                ->whereIn('id', $groupIds)
                ->where('permission_id', '>', 0)
                ->column('permission_id');

            // 删除所有分组下的设置项
            $this->model(Setting::class)->whereIn('group_id', $groupIds)->delete();

            // 删除所有子分组和当前分组
            $this->model()->whereIn('id', $groupIds)->delete();

            // 删除对应的权限记录
            if (!empty($permissionIds)) {
                $this->model(Permission::class)->whereIn('id', $permissionIds)->delete();
            }

            // 清除缓存
            $this->cacheService->clearGroup($group->code);
            $this->cacheService->clearAll();

            return true;
        });
    }

    /**
     * 获取分组的所有子分组ID（递归）
     */
    protected function getAllChildIds(int $parentId): array
    {
        $ids = [];
        $children = $this->model()->where('parent_id', $parentId)->column('id');
        foreach ($children as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, $this->getAllChildIds((int)$childId));
        }
        return $ids;
    }

    /**
     * 检查 $parentId 是否是 $groupId 的后代（防止循环引用）
     */
    protected function isChildOf(int $parentId, int $groupId): bool
    {
        $children = $this->model()->where('parent_id', $groupId)->column('id');
        foreach ($children as $childId) {
            if ((int)$childId === $parentId) {
                return true;
            }
            if ($this->isChildOf($parentId, (int)$childId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取分组详情（用于编辑回显）
     */
    public function getGroupInfo(int $id): array
    {
        $group = $this->model()->find($id);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        return $group->toArray();
    }

    // ==================== 权限同步 ====================

    /**
     * 解析权限的 parent_id
     *
     * @param SettingGroup $group 分组模型
     * @param int $menuParentPermissionId 顶级分组的父菜单权限ID
     * @return int
     */
    protected function resolvePermissionParentId(SettingGroup $group, int $menuParentPermissionId = 0): int
    {
        if ($group->parent_id > 0) {
            // 子分组：使用父分组对应的权限ID
            $parentGroup = $this->model()->find($group->parent_id);
            if ($parentGroup && $parentGroup->permission_id > 0) {
                return $parentGroup->permission_id;
            }
            return 0;
        }

        // 顶级分组：使用传入的菜单父权限ID
        return $menuParentPermissionId;
    }

    /**
     * 根据分组生成菜单 path
     * 规则：顶级 /settings/{code}  子级 /settings/{parent_code}/{code}
     */
    protected function makePermissionPath(SettingGroup $group): string
    {
        if ($group->parent_id > 0) {
            $parent = $this->model()->find($group->parent_id);
            if ($parent) {
                return '/settings/' . $parent->code . '/' . $group->code;
            }
        }
        return '/settings/' . $group->code;
    }

    /**
     * 同步创建权限
     *
     * @param SettingGroup $group 分组模型
     * @param int $menuParentPermissionId 顶级分组的父菜单权限ID
     * @return int 创建的权限ID
     */
    protected function syncCreatePermission(SettingGroup $group, int $menuParentPermissionId = 0): int
    {
        $permissionParentId = $this->resolvePermissionParentId($group, $menuParentPermissionId);

        $permission = $this->model(Permission::class);
        $permission->save([
            'parent_id' => $permissionParentId,
            'name'      => $group->name,
            'code'      => self::PERMISSION_CODE_PREFIX . $group->code,
            'type'      => Permission::TYPE_MENU,
            'path'      => $this->makePermissionPath($group),
            'icon'      => $group->icon ?: null,
            'component' => self::SETTING_COMPONENT,
            'sort'      => $group->sort ?? 0,
            'status'    => $group->status ?? 1,
            'is_show'   => 1,
            'source'    => Permission::SOURCE_SETTING,
            'remark'    => $group->description ?: null,
        ]);

        // 回写 permission_id 到分组
        $group->save(['permission_id' => $permission->id]);

        return $permission->id;
    }

    /**
     * 同步更新权限
     *
     * @param SettingGroup $group 分组模型
     * @param int $menuParentPermissionId 顶级分组的父菜单权限ID
     */
    protected function syncUpdatePermission(SettingGroup $group, int $menuParentPermissionId = 0): void
    {
        if ($group->permission_id <= 0) {
            return;
        }

        $permission = $this->model(Permission::class)->find($group->permission_id);
        if (!$permission) {
            return;
        }

        $permissionParentId = $this->resolvePermissionParentId($group, $menuParentPermissionId);

        $permission->save([
            'parent_id' => $permissionParentId,
            'name'      => $group->name,
            'path'      => $this->makePermissionPath($group),
            'icon'      => $group->icon ?: null,
            'sort'      => $group->sort ?? 0,
            'status'    => $group->status ?? 1,
            'remark'    => $group->description ?: null,
        ]);
    }

    // ==================== 设置项管理 ====================

    /**
     * 获取分组下的设置项列表
     */
    public function getSettingList(int $groupId): array
    {
        $group = $this->model()->find($groupId);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        return $this->model(Setting::class)
            ->where('group_id', $groupId)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 创建设置项
     */
    public function createSetting(array $data): int
    {
        // 验证分组是否存在
        $group = $this->model()->find($data['group_id']);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        // 检查同一分组下编码是否重复
        if ($this->model(Setting::class)
            ->where('group_id', $data['group_id'])
            ->where('code', $data['code'])
            ->find()) {
            throw new BusinessException('该分组下设置项编码已存在');
        }

        $setting = $this->model(Setting::class);
        $setting->save([
            'group_id'    => $data['group_id'],
            'name'        => $data['name'],
            'code'        => $data['code'],
            'value'       => $data['value'] ?? '',
            'type'        => $data['type'] ?? Setting::TYPE_INPUT,
            'options'     => $data['options'] ?? null,
            'placeholder' => $data['placeholder'] ?? '',
            'remark'      => $data['remark'] ?? '',
            'sort'        => $data['sort'] ?? 0,
            'is_required' => $data['is_required'] ?? 0,
        ]);

        $this->cacheService->clearGroup($group->code);

        return $setting->id;
    }

    /**
     * 更新设置项
     */
    public function updateSetting(int $id, array $data): bool
    {
        $setting = $this->model(Setting::class)->find($id);
        if (!$setting) {
            throw new BusinessException('设置项不存在');
        }

        // 如果修改了 code，检查同一分组下是否重复
        if (!empty($data['code']) && $data['code'] !== $setting->code) {
            if ($this->model(Setting::class)
                ->where('group_id', $setting->group_id)
                ->where('code', $data['code'])
                ->find()) {
                throw new BusinessException('该分组下设置项编码已存在');
            }
        }

        $setting->save($data);

        // 清除单项缓存和分组缓存
        $this->cacheService->clearSettingValue($setting->code);
        $group = $this->model()->find($setting->group_id);
        if ($group) {
            $this->cacheService->clearGroup($group->code);
        }

        return true;
    }

    /**
     * 删除设置项
     */
    public function deleteSetting(int $id): bool
    {
        $setting = $this->model(Setting::class)->find($id);
        if (!$setting) {
            throw new BusinessException('设置项不存在');
        }

        $code = $setting->code;
        $groupId = $setting->group_id;
        $setting->delete();

        // 清除单项缓存和分组缓存
        $this->cacheService->clearSettingValue($code);
        $group = $this->model()->find($groupId);
        if ($group) {
            $this->cacheService->clearGroup($group->code);
        }

        return true;
    }

    // ==================== 公共配置读取 ====================

    /**
     * 获取单个设置项的值（公共方法，带缓存，供其他服务/模块调用）
     *
     * @param string $code 设置项编码（如 wechat_appid）
     * @param mixed $default 默认值（设置项不存在或值为空时返回）
     * @return mixed
     */
    public function getSettingValue(string $code, mixed $default = null): mixed
    {
        return $this->cacheService->getSettingValue($code, function () use ($code, $default) {
            $setting = $this->model(Setting::class)
                ->where('code', $code)
                ->find();

            if (!$setting || $setting->value === null || $setting->value === '') {
                return $default;
            }

            return $setting->value;
        });
    }

    // ==================== 配置读取/保存 ====================

    /**
     * 获取分组配置（用于前端表单渲染，走缓存）
     */
    public function getGroupConfig(string $groupCode): array
    {
        $group = $this->model()->where('code', $groupCode)->find();
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        if ($group->status !== 1) {
            throw new BusinessException('分组已禁用');
        }

        $settings = $this->cacheService->getGroupSettings($groupCode, function () use ($group) {
            return $this->model(Setting::class)
                ->where('group_id', $group->id)
                ->order('sort', 'asc')
                ->select()
                ->toArray();
        });

        return [
            'group' => $group->toArray(),
            'settings' => $settings,
        ];
    }

    /**
     * 获取分组配置值（key-value 对，供其他服务调用）
     */
    public function getGroupValues(string $groupCode): array
    {
        $config = $this->getGroupConfig($groupCode);
        $values = [];
        foreach ($config['settings'] as $setting) {
            $values[$setting['code']] = $setting['value'];
        }
        return $values;
    }

    /**
     * 保存分组配置（批量更新值）
     */
    public function saveGroupValues(string $groupCode, array $values): bool
    {
        $group = $this->model()->where('code', $groupCode)->find();
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $settings = $this->model(Setting::class)
            ->where('group_id', $group->id)
            ->select();

        foreach ($settings as $setting) {
            if (array_key_exists($setting->code, $values)) {
                $setting->value = $values[$setting->code];
                $setting->save();
            }
        }

        $this->cacheService->clearGroup($groupCode);

        return true;
    }
}