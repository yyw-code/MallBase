<?php

declare (strict_types=1);

namespace app\service\admin\setting;

use app\model\auth\Permission;
use app\model\setting\RuleType;
use app\model\setting\Setting;
use app\model\setting\SettingGroup;
use app\model\setting\SettingSection;
use app\service\cache\SettingCacheService;
use app\service\upload\AssetHydrator;
use app\support\upload\LocalUploadRootPolicy;
use app\validate\admin\setting\SettingValueValidate;
use app\service\UploadService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Log;

/**
 * 设置服务（分组 + 设置项 + 权限同步 统一管理）
 * @extends BaseService<SettingGroup>
 */
class SettingService extends BaseService
{
    private const HIDDEN_SETTING_CODES = [
        'client_home_banners',
        'client_theme_admin_mode',
        'client_theme_admin_theme_id',
        'client_theme_user_select_enabled',
        'invite_reward_bind_daily_limit',
        'invite_reward_bind_total_limit',
    ];

    private const UI_COMPONENTS = [
        'money_yuan' => [
            'label' => '金额输入',
            'description' => '元输入，分保存',
        ],
        'remote_select' => [
            'label' => '远程下拉',
            'description' => '从系统数据源选择',
        ],
    ];

    private const UI_OPTION_SOURCES = [
        'distribution_level' => [
            'label' => '分销等级',
            'description' => '分销模块等级列表',
        ],
    ];

    private const SETTING_UI_META = [
        'SystemCopyright' => [
            'copyright_company' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
            'copyright_company_url' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
            'copyright_date' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
            'copyright_icp' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
            'copyright_icp_url' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
            'copyright_psb' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
            'copyright_psb_url' => [
                'visible_when' => [
                    ['field' => 'copyright_enabled', 'operator' => 'truthy'],
                ],
            ],
        ],
        'PointsConfig' => [
            'points_reward_enabled' => [
                'visible_when' => [
                    ['field' => 'points_enabled', 'operator' => 'truthy'],
                ],
            ],
            'points_reward_freeze_days' => [
                'visible_when' => [
                    ['field' => 'points_enabled', 'operator' => 'truthy'],
                    ['field' => 'points_reward_enabled', 'operator' => 'truthy'],
                ],
            ],
            'points_deduction_enabled' => [
                'visible_when' => [
                    ['field' => 'points_enabled', 'operator' => 'truthy'],
                ],
            ],
            'points_deduction_points_per_yuan' => [
                'visible_when' => [
                    ['field' => 'points_enabled', 'operator' => 'truthy'],
                    ['field' => 'points_deduction_enabled', 'operator' => 'truthy'],
                ],
            ],
            'points_deduction_max_percent' => [
                'visible_when' => [
                    ['field' => 'points_enabled', 'operator' => 'truthy'],
                    ['field' => 'points_deduction_enabled', 'operator' => 'truthy'],
                ],
            ],
        ],
        'MemberConfig' => [
            'member_growth_points_per_yuan' => [
                'visible_when' => [
                    ['field' => 'member_enabled', 'operator' => 'truthy'],
                ],
            ],
        ],
        'DistributionConfig' => [
            'distribution_enabled' => [
                'section_code' => 'basic',
            ],
            'distributor_open_mode' => [
                'section_code' => 'opening',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'auto_open_level_id' => [
                'section_code' => 'opening',
                'component' => 'remote_select',
                'option_source' => 'distribution_level',
                'label' => '默认分销等级',
                'placeholder' => '请选择默认分销等级',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                    [
                        'field' => 'distributor_open_mode',
                        'operator' => 'in',
                        'value' => ['apply', 'everyone', 'amount'],
                    ],
                ],
            ],
            'second_level_enabled' => [
                'section_code' => 'commission',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'self_purchase_enabled' => [
                'section_code' => 'commission',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'relation_valid_days' => [
                'section_code' => 'settlement',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'settlement_days' => [
                'section_code' => 'settlement',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'min_withdraw_cents' => [
                'section_code' => 'withdraw',
                'component' => 'money_yuan',
                'label' => '最低提现金额(元)',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'global_first_rate' => [
                'section_code' => 'commission',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'global_second_rate' => [
                'section_code' => 'commission',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                    ['field' => 'second_level_enabled', 'operator' => 'truthy'],
                ],
            ],
            'amount_open_threshold_cents' => [
                'section_code' => 'opening',
                'component' => 'money_yuan',
                'label' => '满额开通门槛(元)',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                    ['field' => 'distributor_open_mode', 'operator' => 'equals', 'value' => 'amount'],
                ],
            ],
            'invite_reward_enabled' => [
                'section_code' => 'invite',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
            'invite_reward_trigger' => [
                'section_code' => 'invite',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                    ['field' => 'invite_reward_enabled', 'operator' => 'truthy'],
                ],
            ],
            'invite_reward_amount_cents' => [
                'section_code' => 'invite',
                'component' => 'money_yuan',
                'label' => '固定邀请奖励金额(元)',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                    ['field' => 'invite_reward_enabled', 'operator' => 'truthy'],
                ],
            ],
            'attribution_enabled' => [
                'section_code' => 'settlement',
                'visible_when' => [
                    ['field' => 'distribution_enabled', 'operator' => 'truthy'],
                ],
            ],
        ],
    ];

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
        $this->cacheService = app()->make(SettingCacheService::class);
    }

    // ==================== 分组管理 ====================

    /**
     * 获取分组列表（支持树形结构）
     */
    public function getGroupList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->buildGroupListQuery($where);

        $total = (int) (clone $query)->count();
        $list = $query->order('sort', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    protected function buildGroupListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(!empty($where['parent_id']), function ($q) use ($where) {
                $q->where('parent_id', (int) $where['parent_id']);
            });
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

        // 检查父级分组是否存在并验证 display_type 约束
        $parentId = (int)($data['parent_id'] ?? 0);
        $displayType = $data['display_type'] ?? SettingGroup::DISPLAY_TYPE_PAGE;

        if ($parentId > 0) {
            $parentGroup = $this->model()->find($parentId);
            if (!$parentGroup) {
                throw new BusinessException('父级分组不存在');
            }

            // 目录不能有父级
            if ($displayType === SettingGroup::DISPLAY_TYPE_CATEGORY) {
                throw new BusinessException('目录类型不能有父级');
            }

            // 选项卡的父级必须是页面类型
            if ($displayType === SettingGroup::DISPLAY_TYPE_TAB) {
                if ($parentGroup->display_type !== SettingGroup::DISPLAY_TYPE_PAGE) {
                    throw new BusinessException('选项卡的父级必须是页面类型');
                }
            }
        } elseif ($displayType === SettingGroup::DISPLAY_TYPE_TAB) {
            // 选项卡必须有父级
            throw new BusinessException('选项卡必须选择父级分组');
        }

        $displayType = $data['display_type'] ?? SettingGroup::DISPLAY_TYPE_PAGE;

        $group = $this->model();
        $group->save([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'code' => $data['code'],
            'icon' => $data['icon'] ?? '',
            'description' => $data['description'] ?? '',
            'sort' => $data['sort'] ?? 0,
            'display_type' => $displayType,
            'status' => $data['status'] ?? 1,
            'permission_parent_code' => $data['permission_parent_code'] ?? null,
            'permission_path' => $data['permission_path'] ?? null,
            'permission_component' => $data['permission_component'] ?? null,
            'permission_status' => $data['permission_status'] ?? null,
        ]);

        // 同步创建权限（权限层级自动根据分组层级决定）
        if ($displayType === SettingGroup::DISPLAY_TYPE_CATEGORY) {
            // 目录类型：创建菜单权限
            $this->syncCreatePermission($group);
        } elseif ($parentId <= 0) {
            // 顶级分组（page/tab）：创建权限
            $this->syncCreatePermission($group);
        } else {
            // 子分组：根据父分组的 display_type 决定是否创建权限
            $parentGroup = $this->model()->find($parentId);
            if ($parentGroup && $parentGroup->display_type !== SettingGroup::DISPLAY_TYPE_TAB) {
                // page/category 模式的父分组下，子分组需要独立权限（作为子菜单）
                $this->syncCreatePermission($group);
            }
            // tab 模式的父分组下，子分组不创建权限
        }

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
        if ((int)($group->is_system ?? 0) === 1) {
            throw new BusinessException('系统内置分组不允许修改基础信息');
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

            // 验证 display_type 与父级的关系
            $displayType = $data['display_type'] ?? $group->display_type;
            if ($parentId > 0) {
                $parentGroup = $this->model()->find($parentId);
                if (!$parentGroup) {
                    throw new BusinessException('父级分组不存在');
                }

                // 目录不能有父级
                if ($displayType === SettingGroup::DISPLAY_TYPE_CATEGORY) {
                    throw new BusinessException('目录类型不能有父级');
                }

                // 选项卡的父级必须是页面类型
                if ($displayType === SettingGroup::DISPLAY_TYPE_TAB) {
                    if ($parentGroup->display_type !== SettingGroup::DISPLAY_TYPE_PAGE) {
                        throw new BusinessException('选项卡的父级必须是页面类型');
                    }
                }
            } elseif ($displayType === SettingGroup::DISPLAY_TYPE_TAB) {
                // 选项卡必须有父级
                throw new BusinessException('选项卡必须选择父级分组');
            }
        }

        // 记录变更前的值（用于检测是否变更）
        $oldDisplayType = $group->display_type;
        $oldCode = $group->code;

        // 更新分组数据
        $updateData = array_intersect_key($data, array_flip([
            'parent_id', 'name', 'code', 'icon', 'description', 'sort', 'display_type', 'status',
            'permission_parent_code', 'permission_path', 'permission_component', 'permission_status',
        ]));
        $group->save($updateData);

        // 顶级分组：display_type 变更时同步子分组权限
        if ($group->parent_id <= 0 && isset($data['display_type']) && $data['display_type'] !== $oldDisplayType) {
            $this->syncChildPermissionsOnDisplayTypeChange($group, $oldDisplayType, $data['display_type']);
        }

        // code 变更时同步更新子分组的权限 path（子分组的 path 包含父分组 code）
        if (!empty($data['code']) && $data['code'] !== $oldCode) {
            $this->syncChildPermissionPaths($group);
        }

        // 子分组：tab 类型的父分组下不生成独立权限
        if ($group->parent_id > 0) {
            $parentGroup = $this->model()->find($group->parent_id);
            if ($parentGroup && $parentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                // tab 模式下子分组不需要独立权限，清除已有权限
                if ($group->permission_id > 0) {
                    $permission = $this->model(Permission::class)->find($group->permission_id);
                    if ($permission) {
                        $permission->delete();
                    }
                    $group->save(['permission_id' => 0]);
                }
                $this->cacheService->clearAll();
                return true;
            }
        }

        // 同步更新权限（权限层级自动根据分组层级决定）
        $this->syncUpdatePermission($group);

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
        if ((int)($group->is_system ?? 0) === 1) {
            throw new BusinessException('系统内置分组不允许删除');
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

            // 删除所有分组下的页内分组
            $this->model(SettingSection::class)->whereIn('group_id', $groupIds)->delete();

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
     * 修改分组状态（同步更新对应权限状态）
     * 禁用父级时递归禁用所有子级及其权限
     */
    public function changeGroupStatus(int $id, int $status): bool
    {
        $group = $this->model()->find($id);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }
        if ((int)($group->is_system ?? 0) === 1) {
            throw new BusinessException('系统内置分组不允许修改状态');
        }

        $group->save(['status' => $status]);

        // 同步更新对应权限状态
        $this->syncPermissionStatus($group, $status);

        // 禁用时递归禁用所有子级
        if ($status === 0) {
            $this->disableChildGroups($id);
        }

        $this->cacheService->clearAll();

        return true;
    }

    /**
     * 递归禁用子级分组及其权限
     */
    protected function disableChildGroups(int $parentId): void
    {
        $children = $this->model()->where('parent_id', $parentId)->select();
        foreach ($children as $child) {
            $child->save(['status' => 0]);
            $this->syncPermissionStatus($child, 0);
            $this->disableChildGroups($child->id);
        }
    }

    /**
     * 同步更新分组对应权限的状态
     */
    protected function syncPermissionStatus($group, int $status): void
    {
        if ($group->permission_id > 0) {
            $permission = $this->model(Permission::class)->find($group->permission_id);
            if ($permission) {
                $permission->save(['status' => $status]);
            }
        }
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

    // ==================== 页内分组管理 ====================

    /**
     * 获取设置分组下的页内分组列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSectionList(int $groupId): array
    {
        $group = $this->model()->find($groupId);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        return $this->model(SettingSection::class)
            ->where('group_id', $groupId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 创建页内分组。
     */
    public function createSection(array $data): int
    {
        $groupId = (int)($data['group_id'] ?? 0);
        $group = $this->model()->find($groupId);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }
        if ($group->display_type !== SettingGroup::DISPLAY_TYPE_PAGE) {
            throw new BusinessException('只有页面类型分组可以管理页内分组');
        }

        $code = trim((string)($data['code'] ?? ''));
        if ($this->model(SettingSection::class)->where('group_id', $groupId)->where('code', $code)->find()) {
            throw new BusinessException('页内分组编码已存在');
        }

        $section = $this->model(SettingSection::class);
        $section->save([
            'group_id' => $groupId,
            'name' => trim((string)$data['name']),
            'code' => $code,
            'sort' => (int)($data['sort'] ?? 0),
            'is_system' => 0,
        ]);

        $this->cacheService->clearGroup($group->code);

        return (int)$section->id;
    }

    /**
     * 更新页内分组。
     */
    public function updateSection(int $id, array $data): bool
    {
        $section = $this->model(SettingSection::class)->find($id);
        if (!$section) {
            throw new BusinessException('页内分组不存在');
        }

        $group = $this->model()->find($section->group_id);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $updateData = [
            'name' => trim((string)($data['name'] ?? $section->name)),
            'sort' => (int)($data['sort'] ?? $section->sort),
        ];

        if ((int)$section->is_system !== 1) {
            $code = trim((string)($data['code'] ?? $section->code));
            if ($code !== $section->code && $this->model(SettingSection::class)
                ->where('group_id', $section->group_id)
                ->where('code', $code)
                ->find()) {
                throw new BusinessException('页内分组编码已存在');
            }
            $updateData['code'] = $code;
        } elseif (isset($data['code']) && trim((string)$data['code']) !== $section->code) {
            throw new BusinessException('系统内置页内分组编码不允许修改');
        }

        $section->save($updateData);
        $this->cacheService->clearGroup($group->code);

        return true;
    }

    /**
     * 删除页内分组。
     */
    public function deleteSection(int $id): bool
    {
        $section = $this->model(SettingSection::class)->find($id);
        if (!$section) {
            throw new BusinessException('页内分组不存在');
        }
        if ((int)$section->is_system === 1) {
            throw new BusinessException('系统内置页内分组不允许删除');
        }

        $group = $this->model()->find($section->group_id);
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $used = $this->model(Setting::class)
            ->where('group_id', $section->group_id)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`ui`, '$.section_code')) = ?", [$section->code])
            ->find();
        if ($used) {
            throw new BusinessException('页内分组已被设置项使用，不能删除');
        }

        $section->delete();
        $this->cacheService->clearGroup($group->code);

        return true;
    }

    // ==================== 权限同步 ====================

    /**
     * 解析权限的 parent_id（自动根据分组层级决定）
     *
     * @param SettingGroup $group 分组模型
     * @return int 权限的父级ID
     */
    protected function resolvePermissionParentId(SettingGroup $group): int
    {
        $parentCode = trim((string) ($group->permission_parent_code ?? ''));
        if ($parentCode !== '') {
            $parentPermission = $this->model(Permission::class)
                ->where('code', $parentCode)
                ->find();

            return $parentPermission ? (int) $parentPermission->id : 0;
        }

        if ($group->parent_id > 0) {
            // 子分组：使用父分组对应的权限ID
            $parentGroup = $this->model()->find($group->parent_id);
            if ($parentGroup && $parentGroup->permission_id > 0) {
                $parentPermission = $this->model(Permission::class)->find($parentGroup->permission_id);
                if ($parentPermission) {
                    return $parentPermission->id;
                }
            }
            return 0;
        }

        // 顶级分组：作为根级菜单
        return 0;
    }

    /**
     * 根据分组生成菜单 path
     * 规则：
     * - category（目录）：生成稳定路由，避免前端用权限 code 兜底为非法 path
     * - page（页面）：顶级 /settings/{code}，子级 /settings/{parent_code}/{code}
     * - tab（选项卡）：/settings/{code}
     * - tab 的子页面：共享父级路由 /settings/{parent_code}
     */
    protected function makePermissionPath(SettingGroup $group): string
    {
        $customPath = trim((string) ($group->permission_path ?? ''));
        if ($customPath !== '') {
            return $customPath;
        }

        // 目录类型仍生成稳定路由，用于前端菜单树承载
        if ($group->display_type === SettingGroup::DISPLAY_TYPE_CATEGORY) {
            return '/settings/' . $group->code;
        }

        // 选项卡类型生成路由
        if ($group->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
            return '/settings/' . $group->code;
        }

        // 页面类型
        if ($group->parent_id > 0) {
            $parent = $this->model()->find($group->parent_id);
            if ($parent) {
                // 如果父级是选项卡，共享父级路由
                if ($parent->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                    return '/settings/' . $parent->code;
                }
                // 其他情况生成子级路由
                return '/settings/' . $parent->code . '/' . $group->code;
            }
        }
        return '/settings/' . $group->code;
    }

    protected function makePermissionComponent(SettingGroup $group): string
    {
        $customComponent = trim((string) ($group->permission_component ?? ''));

        return $customComponent !== '' ? $customComponent : self::SETTING_COMPONENT;
    }

    protected function makePermissionStatus(SettingGroup $group): int
    {
        if ($group->permission_status !== null && $group->permission_status !== '') {
            return (int) $group->permission_status;
        }

        return (int) ($group->status ?? 1);
    }

    /**
     * 同步创建权限（权限层级自动根据分组层级决定）
     *
     * @param SettingGroup $group 分组模型
     * @return int 创建的权限ID
     */
    protected function syncCreatePermission(SettingGroup $group): int
    {
        $permissionParentId = $this->resolvePermissionParentId($group);

        $permission = new Permission();

        $permissionData = [
            'parent_id' => $permissionParentId,
            'name' => $group->name,
            'code' => $this->makeSettingPermissionCode((string) $group->code),
            'type' => Permission::TYPE_MENU,
            'path' => $this->makePermissionPath($group),
            'component' => $this->makePermissionComponent($group),
            'icon' => $group->icon ?: null,
            'sort' => $group->sort ?? 0,
            'status' => $this->makePermissionStatus($group),
            'is_show' => 1,
            'source' => Permission::SOURCE_SETTING,
            'remark' => $group->description ?: null,
        ];

        $permission->save($permissionData);

        // 回写 permission_id 到分组
        $group->save(['permission_id' => $permission->id]);

        return $permission->id;
    }

    /**
     * 同步更新权限（权限层级自动根据分组层级决定）
     *
     * @param SettingGroup $group 分组模型
     */
    protected function syncUpdatePermission(SettingGroup $group): void
    {
        // permission_id 不存在时，创建权限并回写
        if ($group->permission_id <= 0) {
            $this->syncCreatePermission($group);
            return;
        }

        $permission = $this->model(Permission::class)->find($group->permission_id);
        if (!$permission) {
            // 权限记录被删了，重新创建
            $this->syncCreatePermission($group);
            return;
        }

        $permissionParentId = $this->resolvePermissionParentId($group);

        $permission->save([
            'parent_id' => $permissionParentId,
            'name' => $group->name,
            'code' => $this->makeSettingPermissionCode((string) $group->code),
            'path' => $this->makePermissionPath($group),
            'component' => $this->makePermissionComponent($group),
            'icon' => $group->icon ?: null,
            'sort' => $group->sort ?? 0,
            'status' => $this->makePermissionStatus($group),
            'remark' => $group->description ?: null,
        ]);
    }

    /**
     * 顶级分组 display_type 变更时，同步子分组的权限
     * - page → tab：删除所有子分组的权限（tab 模式下子分组不生成独立权限）
     * - tab → page：为所有子分组创建权限（page 模式下子分组作为子菜单需要独立权限）
     *
     * @param SettingGroup $group 当前分组（已更新后的数据）
     * @param string $oldDisplayType 变更前的 display_type
     * @param string $newDisplayType 变更后的 display_type
     */
    protected function syncChildPermissionsOnDisplayTypeChange(SettingGroup $group, string $oldDisplayType, string $newDisplayType): void
    {
        $children = $this->model()->where('parent_id', $group->id)->select();

        if ($oldDisplayType === SettingGroup::DISPLAY_TYPE_PAGE && $newDisplayType === SettingGroup::DISPLAY_TYPE_TAB) {
            // page → tab：删除所有子分组的权限
            foreach ($children as $child) {
                if ($child->permission_id > 0) {
                    $permission = $this->model(Permission::class)->find($child->permission_id);
                    if ($permission) {
                        $permission->delete();
                    }
                    $child->save(['permission_id' => 0]);
                }
            }
        } elseif ($oldDisplayType === SettingGroup::DISPLAY_TYPE_TAB && $newDisplayType === SettingGroup::DISPLAY_TYPE_PAGE) {
            // tab → page：为所有子分组创建权限
            foreach ($children as $child) {
                if ($child->permission_id <= 0) {
                    $this->syncCreatePermission($child);
                }
            }
        }
    }

    /**
     * 父分组 code 变更时，同步更新子分组的权限 path
     * 子分组的 path 格式为 /settings/{parent_code}/{child_code}，包含父分组 code
     *
     * @param SettingGroup $group 父分组（已更新后的数据）
     */
    protected function syncChildPermissionPaths(SettingGroup $group): void
    {
        $children = $this->model()->where('parent_id', $group->id)->select();

        foreach ($children as $child) {
            if ($child->permission_id > 0) {
                $permission = $this->model(Permission::class)->find($child->permission_id);
                if ($permission) {
                    $permission->save([
                        'path' => $this->makePermissionPath($child),
                        'code' => self::PERMISSION_CODE_PREFIX . $child->code,
                    ]);
                }
            }
        }
    }

    /**
     * 判断分组是否应具备独立菜单权限
     *
     * @param array<string, mixed> $group
     * @param array<string, mixed>|null $parentGroup
     */
    protected function shouldGroupHaveStandalonePermission(array $group, ?array $parentGroup): bool
    {
        if (
            !empty($group['permission_parent_code'])
            || !empty($group['permission_path'])
            || !empty($group['permission_component'])
        ) {
            return true;
        }

        if (($group['display_type'] ?? SettingGroup::DISPLAY_TYPE_PAGE) === SettingGroup::DISPLAY_TYPE_CATEGORY) {
            return true;
        }

        if ((int) ($group['parent_id'] ?? 0) <= 0) {
            return true;
        }

        if ($parentGroup === null) {
            return true;
        }

        return ($parentGroup['display_type'] ?? SettingGroup::DISPLAY_TYPE_PAGE) !== SettingGroup::DISPLAY_TYPE_TAB;
    }

    /**
     * 生成分组对应的权限 code
     */
    protected function makeSettingPermissionCode(string $groupCode): string
    {
        return self::PERMISSION_CODE_PREFIX . $groupCode;
    }

    /**
     * 判断分组当前是否已关联有效权限记录
     *
     * @param array<string, mixed> $group
     * @param array<int, array<string, mixed>> $permissionSnapshots
     */
    protected function hasValidPermissionReference(array $group, array $permissionSnapshots): bool
    {
        $permissionId = (int) ($group['permission_id'] ?? 0);
        if ($permissionId <= 0 || !isset($permissionSnapshots[$permissionId])) {
            return false;
        }

        $permission = $permissionSnapshots[$permissionId];

        return ($permission['code'] ?? '') === $this->makeSettingPermissionCode((string) ($group['code'] ?? ''))
            && (int) ($permission['source'] ?? 0) === Permission::SOURCE_SETTING;
    }

    /**
     * 计算设置菜单权限的修复顺序
     *
     * @param array<int, array<string, mixed>> $groups
     * @param array<int, array<string, mixed>> $permissionSnapshots
     * @return array<int, array<string, mixed>>
     */
    protected function buildPermissionRepairOrder(array $groups, array $permissionSnapshots): array
    {
        if (empty($groups)) {
            return [];
        }

        $groupsById = [];
        $childrenByParent = [];
        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $group['id'] = $groupId;
            $group['parent_id'] = (int) ($group['parent_id'] ?? 0);
            $group['permission_id'] = (int) ($group['permission_id'] ?? 0);
            $group['sort'] = (int) ($group['sort'] ?? 0);
            $groupsById[$groupId] = $group;
            $childrenByParent[$group['parent_id']][] = $groupId;
        }

        foreach ($childrenByParent as &$childIds) {
            usort($childIds, function (int $leftId, int $rightId) use ($groupsById): int {
                $left = $groupsById[$leftId];
                $right = $groupsById[$rightId];

                return [$left['sort'], $left['id']] <=> [$right['sort'], $right['id']];
            });
        }
        unset($childIds);

        $repairMemo = [];
        $needsRepair = function (int $groupId) use (&$needsRepair, &$repairMemo, $groupsById, $permissionSnapshots): bool {
            if (array_key_exists($groupId, $repairMemo)) {
                return $repairMemo[$groupId];
            }

            $group = $groupsById[$groupId] ?? null;
            if ($group === null) {
                return $repairMemo[$groupId] = false;
            }

            $parentGroup = null;
            if ($group['parent_id'] > 0) {
                $parentGroup = $groupsById[$group['parent_id']] ?? null;
            }

            $shouldHaveStandalonePermission = $this->shouldGroupHaveStandalonePermission($group, $parentGroup);
            if (!$shouldHaveStandalonePermission) {
                return $repairMemo[$groupId] = $group['permission_id'] > 0;
            }

            $parentNeedsRepair = false;
            if ($parentGroup !== null && $this->shouldGroupHaveStandalonePermission(
                $parentGroup,
                $groupsById[(int) ($parentGroup['parent_id'] ?? 0)] ?? null
            )) {
                $parentNeedsRepair = $needsRepair((int) $parentGroup['id']);
            }

            return $repairMemo[$groupId] = !$this->hasValidPermissionReference($group, $permissionSnapshots) || $parentNeedsRepair;
        };

        $ordered = [];
        $visited = [];
        $visit = function (int $groupId) use (&$visit, &$ordered, &$visited, $groupsById, $childrenByParent, $needsRepair): void {
            if (isset($visited[$groupId])) {
                return;
            }

            $visited[$groupId] = true;
            if (($groupsById[$groupId] ?? null) === null) {
                return;
            }

            if ($needsRepair($groupId)) {
                $ordered[] = $groupsById[$groupId];
            }

            foreach ($childrenByParent[$groupId] ?? [] as $childId) {
                $visit($childId);
            }
        };

        foreach ($childrenByParent[0] ?? [] as $rootId) {
            $visit($rootId);
        }

        foreach (array_keys($groupsById) as $groupId) {
            $visit($groupId);
        }

        return $ordered;
    }

    /**
     * 计算设置菜单权限的同步顺序（父级优先，包含全部分组）。
     *
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    protected function buildPermissionSyncOrder(array $groups): array
    {
        if (empty($groups)) {
            return [];
        }

        $groupsById = [];
        $childrenByParent = [];
        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $group['id'] = $groupId;
            $group['parent_id'] = (int) ($group['parent_id'] ?? 0);
            $group['sort'] = (int) ($group['sort'] ?? 0);
            $groupsById[$groupId] = $group;
            $childrenByParent[$group['parent_id']][] = $groupId;
        }

        foreach ($childrenByParent as &$childIds) {
            usort($childIds, function (int $leftId, int $rightId) use ($groupsById): int {
                $left = $groupsById[$leftId];
                $right = $groupsById[$rightId];

                return [$left['sort'], $left['id']] <=> [$right['sort'], $right['id']];
            });
        }
        unset($childIds);

        $ordered = [];
        $visited = [];
        $visit = function (int $groupId) use (&$visit, &$ordered, &$visited, $groupsById, $childrenByParent): void {
            if (isset($visited[$groupId]) || ($groupsById[$groupId] ?? null) === null) {
                return;
            }

            $visited[$groupId] = true;
            $ordered[] = $groupsById[$groupId];

            foreach ($childrenByParent[$groupId] ?? [] as $childId) {
                $visit($childId);
            }
        };

        foreach ($childrenByParent[0] ?? [] as $rootId) {
            $visit($rootId);
        }

        foreach (array_keys($groupsById) as $groupId) {
            $visit($groupId);
        }

        return $ordered;
    }

    /**
     * 幂等地补齐或修复设置菜单权限
     *
     * 使用场景：
     * - 安装流程末尾直接调用
     * - 新增 seed 数据后补菜单
     * - 修复误删 permission / 悬空 permission_id 的情况
     *
     * 规则：
     * - mb_setting_group 为唯一真相源，mb_permission 中设置菜单权限视为派生数据
     * - 父为 tab 的 page 子不建独立菜单，若遗留 permission_id 会被回收
     * - 按父先子后的顺序修复，保证子菜单能拿到最新父权限 ID
     *
     * @return int 本次新建的 permission 条数
     */
    public function rebuildAllPermissions(): int
    {
        $groups = $this->model()
            ->order('parent_id', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (empty($groups)) {
            return 0;
        }

        $referencedPermissionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $group): int => (int) ($group['permission_id'] ?? 0),
            $groups
        ))));
        $permissionSnapshots = [];
        if (!empty($referencedPermissionIds)) {
            $permissions = $this->model(Permission::class)
                ->whereIn('id', $referencedPermissionIds)
                ->field(['id', 'code', 'source'])
                ->select()
                ->toArray();
            foreach ($permissions as $permission) {
                $permissionSnapshots[(int) $permission['id']] = $permission;
            }
        }

        $ordered = $this->buildPermissionSyncOrder($groups);
        if (empty($ordered)) {
            return 0;
        }

        $created = 0;
        $changed = false;
        foreach ($ordered as $row) {
            $group = $this->model()->find((int)$row['id']);
            if (!$group) {
                continue;
            }

            $parentGroup = $group->parent_id > 0 ? $this->model()->find((int) $group->parent_id) : null;
            if (!$this->shouldGroupHaveStandalonePermission($group->toArray(), $parentGroup?->toArray())) {
                if ($group->permission_id > 0) {
                    $permission = $this->model(Permission::class)->find($group->permission_id);
                    if ($permission) {
                        $permission->delete();
                    }
                    $group->save(['permission_id' => 0]);
                    $changed = true;
                }
                continue;
            }

            $permission = $group->permission_id > 0
                ? $this->model(Permission::class)->find($group->permission_id)
                : null;
            $hasValidPermissionReference = $this->hasValidPermissionReference($group->toArray(), $permissionSnapshots);

            if ($permission === null || !$hasValidPermissionReference) {
                $this->syncCreatePermission($group);
                $created++;
                $changed = true;
                continue;
            }

            $this->syncUpdatePermission($group);
            $changed = true;
        }

        if ($changed) {
            $this->clearRebuildPermissionCacheSafely();
        }

        return $created;
    }

    /**
     * 权限重建结果以数据库为准，缓存清理失败不应阻断安装流程。
     */
    private function clearRebuildPermissionCacheSafely(): void
    {
        try {
            $this->cacheService->clearAll();
        } catch (\Throwable $e) {
            Log::warning('设置菜单权限已重建，但清理设置缓存失败：' . $e->getMessage());
        }
    }

    // ==================== 表单配置 ====================

    /**
     * 获取表单配置（表单类型选项 + 按 type 索引的验证规则类型）
     * 合并了表单类型和验证规则，前端一次获取全部配置
     *
     * @return array{type_options: array, rule_types: array, ui_components: array, option_sources: array, warnings: array}
     */
    public function getFormConfig(): array
    {
        $allRuleTypes = RuleType::getAll();

        // 从 Setting 模型获取表单类型选项
        $typeOptions = Setting::getTypeOptions();

        // 从公共 UploadService 获取上传规则配置
        $uploadRules = UploadService::getRules();

        // 前端需要的规则字段
        $keepKeys = ['type', 'label', 'need_value', 'value_placeholder', 'value_type', 'need_flags', 'default_message_template'];

        $systemLimits = UploadService::getSystemUploadLimits();
        $effectiveMaxSize = $systemLimits['effective_max_size_mb'];
        $effectiveMaxCount = $systemLimits['effective_max_count'];
        $formWarnings = [];

        // 所有表单类型值
        $formTypeValues = array_column($typeOptions, 'value');

        $ruleTypes = [];
        foreach ($formTypeValues as $formType) {
            // 过滤出适用于当前表单类型的规则
            $applicableRules = array_values(array_filter($allRuleTypes, function ($rule) use ($formType) {
                $applicable = $rule['applicable_types'] ?? [];
                return empty($applicable) || in_array($formType, $applicable, true);
            }));

            // 仅保留前端需要的字段，并为 accept_types 规则注入当前类型的 options
            $applicableRules = array_map(function ($rule) use ($keepKeys, $formType, $uploadRules) {
                $item = array_intersect_key($rule, array_flip($keepKeys));
                // 为 accept_types 规则添加 options（当前表单类型对应的 accept_types 列表）
                if ($rule['type'] === RuleType::TYPE_ACCEPT_TYPES) {
                    $merged = (array)($uploadRules[$formType]['accept_types'] ?? []);
                    // file/files 字段在 secure_upload 模式下需要勾选证书扩展名，
                    // 把 cert 上传类型的 accept_types 合并进来（来自 UploadConfig 的 mime_cert 联动）
                    if (in_array($formType, ['file', 'files'], true) && isset($uploadRules['cert']['accept_types'])) {
                        $merged = array_values(array_unique(array_merge(
                            $merged,
                            (array)$uploadRules['cert']['accept_types']
                        )));
                    }
                    $item['options'] = RuleType::formatAcceptTypeOptions($merged);
                }
                return $item;
            }, $applicableRules);

            // 注入 max_size / max_count 的系统上限提示
            $applicableRules = array_map(function ($item) use ($effectiveMaxSize, $effectiveMaxCount, &$formWarnings) {
                if (($item['type'] ?? '') === RuleType::TYPE_MAX_FILE_SIZE && is_numeric($effectiveMaxSize) && $effectiveMaxSize > 0) {
                    $item['value_max'] = floatval($effectiveMaxSize);
                    $item['hint'] = sprintf(
                        '受 PHP 限制，最大 %sMB；如仍报 413 请检查 Nginx client_max_body_size。',
                        $this->formatNumber(floatval($effectiveMaxSize))
                    );
                    $formWarnings[] = $item['hint'];
                }

                if (($item['type'] ?? '') === RuleType::TYPE_MAX_FILE_COUNT && is_numeric($effectiveMaxCount) && $effectiveMaxCount > 0) {
                    $item['value_max'] = intval($effectiveMaxCount);
                    $item['hint'] = sprintf(
                        '受 PHP 限制，最多 %d 个文件；如仍报 413 请检查 Nginx client_max_body_size。',
                        intval($effectiveMaxCount)
                    );
                    $formWarnings[] = $item['hint'];
                }

                return $item;
            }, $applicableRules);

            $ruleTypes[$formType] = $applicableRules;
        }

        return [
            'type_options' => $typeOptions,
            'rule_types' => $ruleTypes,
            'ui_components' => $this->getUiComponentOptions(),
            'option_sources' => $this->getUiOptionSourceOptions(),
            'warnings' => array_values(array_unique($formWarnings)),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, description?: string}>
     */
    private function getUiComponentOptions(): array
    {
        $options = [
            [
                'label' => '默认组件',
                'value' => '',
                'description' => '按表单类型自动渲染',
            ],
        ];

        foreach (self::UI_COMPONENTS as $value => $item) {
            $options[] = [
                'label' => $item['label'],
                'value' => $value,
                'description' => $item['description'],
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string, description?: string}>
     */
    private function getUiOptionSourceOptions(): array
    {
        $options = [];

        foreach (self::UI_OPTION_SOURCES as $value => $item) {
            $options[] = [
                'label' => $item['label'],
                'value' => $value,
                'description' => $item['description'],
            ];
        }

        return $options;
    }

    // ==================== 设置项管理 ====================

    /**
     * 获取设置项列表
     * group_id 为 0 或不传时返回所有设置项，支持关键词和表单类型搜索
     *
     * @param array $where 搜索条件 [group_id, keyword, type]
     * @return array
     */
    public function getSettingList(array $where = [], int $page = 1, int $pageSize = 15): array
    {
        // group_id > 0 时校验分组是否存在
        if (!empty($where['group_id'])) {
            $group = $this->model()->find($where['group_id']);
            if (!$group) {
                throw new BusinessException('分组不存在');
            }
        }

        $query = $this->buildSettingListQuery($where);

        $total = (int) (clone $query)->count();
        $list = $query->order('sort', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $list = app()->make(AssetHydrator::class)->hydrateSettings($list);
        $list = $this->filterVisibleSettings($list);
        $list = $this->attachResolvedUiMetaToSettingList($list);
        $list = $this->maskSensitiveSettings($list);

        return compact('total', 'list');
    }

    protected function buildSettingListQuery(array $where)
    {
        return $this->model(Setting::class)
            ->when(!empty($where['group_id']), function ($q) use ($where) {
                $q->where('group_id', $where['group_id']);
            })
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|code', "%{$where['keyword']}%");
            })
            ->when(!empty($where['type']), function ($q) use ($where) {
                $q->where('type', $where['type']);
            });
    }

    /**
     * 创建设置项
     */
    public function createSetting(array $data): array
    {
        $this->assertLocalUploadRootSettingValue(
            $data['code'] ?? null,
            $data['value'] ?? '',
        );

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

        $rulesProcessResult = $this->normalizeAndClampRules($data['rules'] ?? null);
        $ui = $this->normalizeSettingUi(
            $data['ui'] ?? null,
            (int)$data['group_id'],
            (string)$data['code']
        );

        $setting = $this->model(Setting::class);
        $setting->save([
            'group_id' => $data['group_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'value' => $data['value'] ?? '',
            'type' => $data['type'] ?? Setting::TYPE_INPUT,
            'options' => $data['options'] ?? null,
            'rules' => $rulesProcessResult['rules'],
            'ui' => $ui,
            'placeholder' => $data['placeholder'] ?? '',
            'remark' => $data['remark'] ?? '',
            'sort' => $data['sort'] ?? 0,
        ]);

        // 清除当前分组缓存
        $this->cacheService->clearGroup($group->code);

        // 如果当前分组是 tab 模式的子分组，同时清除父分组的缓存
        if ($group->parent_id > 0) {
            $parentGroup = $this->model()->find($group->parent_id);
            if ($parentGroup && $parentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                $this->cacheService->clearGroup($parentGroup->code);
            }
        }

        return [
            'id' => $setting->id,
            'warnings' => $rulesProcessResult['warnings'],
        ];
    }

    /**
     * 更新设置项
     */
    public function updateSetting(int $id, array $data): array
    {
        $setting = $this->model(Setting::class)->find($id);
        if (!$setting) {
            throw new BusinessException('设置项不存在');
        }

        if (array_key_exists('value', $data) || array_key_exists('code', $data)) {
            $this->assertLocalUploadRootSettingValue(
                $data['code'] ?? $setting->code,
                $data['value'] ?? $setting->value,
            );
        }

        if ((int)($setting->is_system ?? 0) === 1) {
            $unexpectedKeys = array_diff(array_keys($data), ['ui']);
            if (!empty($unexpectedKeys)) {
                throw new BusinessException('系统内置设置项只允许调整页内分组');
            }

            $ui = $this->normalizeSystemSettingUi(
                $data['ui'] ?? null,
                (int)$setting->group_id,
                (string)$setting->code
            );
            $currentUi = is_array($setting->ui) ? $setting->ui : [];
            if ($ui === null) {
                unset($currentUi['section_code'], $currentUi['section']);
            } else {
                unset($currentUi['section']);
                $currentUi['section_code'] = $ui['section_code'];
            }
            $setting->save(['ui' => empty($currentUi) ? null : $currentUi]);
            $this->clearSettingGroupCaches($setting);

            return [
                'updated' => true,
                'warnings' => [],
            ];
        }

        $effectiveSetting = array_replace($setting->toArray(), $data);
        if (
            array_key_exists('value', $data)
            && $this->isSensitiveSetting($effectiveSetting)
            && $this->isBlankSensitiveValue($data['value'])
        ) {
            unset($data['value']);
        }

        // 记录原始 group_id，用于判断是否跨分组移动
        $originalGroupId = $setting->group_id;

        // 如果修改了 group_id，验证新分组是否存在
        if (isset($data['group_id']) && $data['group_id'] != $originalGroupId) {
            $newGroup = $this->model()->find($data['group_id']);
            if (!$newGroup) {
                throw new BusinessException('目标分组不存在');
            }
        }

        // 如果修改了 code，检查目标分组下是否重复
        $targetGroupId = $data['group_id'] ?? $setting->group_id;
        if (!empty($data['code']) && $data['code'] !== $setting->code) {
            if ($this->model(Setting::class)
                ->where('group_id', $targetGroupId)
                ->where('code', $data['code'])
                ->find()) {
                throw new BusinessException('该分组下设置项编码已存在');
            }
        }

        $rulesProcessResult = $this->normalizeAndClampRules($data['rules'] ?? null);
        $data['rules'] = $rulesProcessResult['rules'];
        if (array_key_exists('ui', $data)) {
            $data['ui'] = $this->normalizeSettingUi(
                $data['ui'],
                (int)$targetGroupId,
                (string)($data['code'] ?? $setting->code)
            );
        }

        $setting->save($data);

        // 清除单项缓存
        $this->cacheService->clearSettingValue($setting->code);

        // 如果修改了分组，需要清除原分组和新分组的缓存
        $newGroupId = $setting->group_id;
        if ($newGroupId !== $originalGroupId) {
            // 清除原分组缓存
            $oldGroup = $this->model()->find($originalGroupId);
            if ($oldGroup) {
                $this->cacheService->clearGroup($oldGroup->code);

                // 如果原分组是 tab 模式的子分组，同时清除父分组的缓存
                if ($oldGroup->parent_id > 0) {
                    $oldParentGroup = $this->model()->find($oldGroup->parent_id);
                    if ($oldParentGroup && $oldParentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                        $this->cacheService->clearGroup($oldParentGroup->code);
                    }
                }
            }

            // 清除新分组缓存
            $newGroup = $this->model()->find($newGroupId);
            if ($newGroup) {
                $this->cacheService->clearGroup($newGroup->code);

                // 如果新分组是 tab 模式的子分组，同时清除父分组的缓存
                if ($newGroup->parent_id > 0) {
                    $newParentGroup = $this->model()->find($newGroup->parent_id);
                    if ($newParentGroup && $newParentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                        $this->cacheService->clearGroup($newParentGroup->code);
                    }
                }
            }
        } else {
            // 没有修改分组，只清除当前分组缓存
            $group = $this->model()->find($setting->group_id);
            if ($group) {
                $this->cacheService->clearGroup($group->code);

                // 如果当前分组是 tab 模式的子分组，同时清除父分组的缓存
                if ($group->parent_id > 0) {
                    $parentGroup = $this->model()->find($group->parent_id);
                    if ($parentGroup && $parentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                        $this->cacheService->clearGroup($parentGroup->code);
                    }
                }
            }
        }

        return [
            'updated' => true,
            'warnings' => $rulesProcessResult['warnings'],
        ];
    }

    /**
     * 归一化设置项后台交互元数据。
     *
     * @param mixed $ui
     * @return array<string, mixed>|null
     */
    protected function normalizeSettingUi(mixed $ui, int $groupId, string $currentCode): ?array
    {
        if ($ui === null || $ui === '' || $ui === []) {
            return null;
        }

        if (is_string($ui)) {
            $decoded = json_decode($ui, true);
            $ui = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        if (!is_array($ui)) {
            return null;
        }

        $result = [];
        $component = trim((string)($ui['component'] ?? ''));
        if ($component !== '') {
            if (!array_key_exists($component, self::UI_COMPONENTS)) {
                throw new BusinessException('输入组件不合法');
            }
            $result['component'] = $component;
        }

        $sectionCode = trim((string)($ui['section_code'] ?? ''));
        if ($sectionCode !== '') {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $sectionCode)) {
                throw new BusinessException('页内分组编码不合法');
            }
            if (!$this->model(SettingSection::class)->where('group_id', $groupId)->where('code', $sectionCode)->find()) {
                throw new BusinessException('页内分组不存在');
            }
            $result['section_code'] = mb_substr($sectionCode, 0, 64);
        }

        if ($component === 'remote_select') {
            $optionSource = trim((string)($ui['option_source'] ?? ''));
            if ($optionSource === '') {
                throw new BusinessException('远程下拉必须选择选项来源');
            }
            if (!array_key_exists($optionSource, self::UI_OPTION_SOURCES)) {
                throw new BusinessException('远程选项来源不合法');
            }
            $result['option_source'] = $optionSource;
        }

        $conditions = $ui['visible_when'] ?? [];
        if (is_array($conditions) && !empty($conditions)) {
            $result['visible_when'] = $this->normalizeUiConditions(
                $conditions,
                $groupId,
                $currentCode
            );
        }

        return empty($result) ? null : $result;
    }

    /**
     * 系统内置设置项只允许保存页内分组归属。
     */
    protected function normalizeSystemSettingUi(mixed $ui, int $groupId, string $currentCode): ?array
    {
        $normalized = $this->normalizeSettingUi($ui, $groupId, $currentCode);
        if ($normalized === null) {
            return null;
        }

        $result = [];
        if (!empty($normalized['section_code'])) {
            $result['section_code'] = $normalized['section_code'];
        }

        return empty($result) ? null : $result;
    }

    /**
     * 清理设置项所属分组相关缓存。
     */
    protected function clearSettingGroupCaches(Setting $setting): void
    {
        $this->cacheService->clearSettingValue($setting->code);

        $group = $this->model()->find($setting->group_id);
        if (!$group) {
            return;
        }

        $this->cacheService->clearGroup($group->code);
        if ($group->parent_id > 0) {
            $parentGroup = $this->model()->find($group->parent_id);
            if ($parentGroup && $parentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                $this->cacheService->clearGroup($parentGroup->code);
            }
        }
    }

    /**
     * @param array<int, mixed> $conditions
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeUiConditions(
        array $conditions,
        int $groupId,
        string $currentCode
    ): array
    {
        $result = [];

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                throw new BusinessException(
                    '第' . ($index + 1) . '条显示条件格式不正确'
                );
            }

            $field = trim((string)($condition['field'] ?? ''));
            if ($field === '') {
                throw new BusinessException(
                    '第' . ($index + 1) . '条显示条件缺少依赖字段'
                );
            }
            if ($field === $currentCode) {
                throw new BusinessException('显示条件不能依赖当前设置项自身');
            }

            /** @var Setting|null $dependency */
            $dependency = $this->model(Setting::class)
                ->where('group_id', $groupId)
                ->where('code', $field)
                ->find();
            if ($dependency === null) {
                throw new BusinessException('显示条件依赖字段不存在或不在同一分组');
            }

            $operator = trim((string)($condition['operator'] ?? 'equals'));
            $dependencyType = (string)$dependency->type;

            if ($dependencyType === Setting::TYPE_SWITCH) {
                if (!in_array($operator, ['truthy', 'falsy'], true)) {
                    throw new BusinessException('开关字段只能配置开启/关闭显示条件');
                }
                $result[] = [
                    'field' => $field,
                    'operator' => $operator,
                ];
                continue;
            }

            if (!in_array($dependencyType, [Setting::TYPE_SELECT, Setting::TYPE_RADIO], true)) {
                throw new BusinessException('显示条件只能依赖同组的开关、单选或下拉字段');
            }

            if (!in_array($operator, ['equals', 'not_equals', 'in'], true)) {
                throw new BusinessException('选择类字段的显示条件判断方式不合法');
            }

            $allowedValues = $this->getSettingOptionValues($dependency);
            if (empty($allowedValues)) {
                throw new BusinessException('依赖字段没有可用选项');
            }

            $value = $condition['value'] ?? null;
            if ($operator === 'in') {
                $values = is_array($value) ? $value : [];
                $values = array_values(array_unique(array_map(
                    fn ($item) => trim((string)$item),
                    $values
                )));
                $values = array_values(array_filter(
                    $values,
                    fn (string $item): bool => $item !== ''
                ));
                if (empty($values)) {
                    throw new BusinessException('显示条件匹配值不能为空');
                }
                foreach ($values as $item) {
                    if (!in_array($item, $allowedValues, true)) {
                        throw new BusinessException('显示条件匹配值不在依赖字段选项中');
                    }
                }
                $result[] = [
                    'field' => $field,
                    'operator' => 'in',
                    'value' => $values,
                ];
                continue;
            }

            $value = trim((string)$value);
            if ($value === '' || !in_array($value, $allowedValues, true)) {
                throw new BusinessException('显示条件匹配值不在依赖字段选项中');
            }

            $result[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    protected function getSettingOptionValues(Setting $setting): array
    {
        $options = $setting->options;
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (!is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $option) {
            if (!is_array($option) || !array_key_exists('value', $option)) {
                continue;
            }
            $value = trim((string)$option['value']);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * 规则归一化并按系统限制截断（超限自动截断并告警）
     *
     * @param mixed $rules
     * @return array{rules: array|null, warnings: string[]}
     */
    protected function normalizeAndClampRules($rules): array
    {
        if ($rules === null || $rules === '') {
            return ['rules' => null, 'warnings' => []];
        }

        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rules = $decoded;
            }
        }

        if (!is_array($rules)) {
            return ['rules' => null, 'warnings' => []];
        }

        $limits = UploadService::getSystemUploadLimits();
        $effectiveMaxSize = $limits['effective_max_size_mb'];
        $effectiveMaxCount = $limits['effective_max_count'];

        $warnings = [];
        $clamped = false;

        foreach ($rules as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            $type = $rule['type'] ?? '';
            $value = $rule['value'] ?? null;

            if ($type === RuleType::TYPE_MAX_FILE_SIZE && is_numeric($value) && is_numeric($effectiveMaxSize) && $effectiveMaxSize > 0) {
                $sizeValue = floatval($value);
                if ($sizeValue > $effectiveMaxSize) {
                    $rule['value'] = floatval($effectiveMaxSize);
                    $warnings[] = sprintf(
                        '规则 max_size 已按 PHP 上限自动截断为 %sMB。',
                        $this->formatNumber(floatval($effectiveMaxSize))
                    );
                    $clamped = true;
                }
            }

            if ($type === RuleType::TYPE_MAX_FILE_COUNT && is_numeric($value) && is_numeric($effectiveMaxCount) && $effectiveMaxCount > 0) {
                $countValue = intval($value);
                if ($countValue > $effectiveMaxCount) {
                    $rule['value'] = intval($effectiveMaxCount);
                    $warnings[] = sprintf(
                        '规则 max_count 已按 PHP 上限自动截断为 %d。',
                        intval($effectiveMaxCount)
                    );
                    $clamped = true;
                }
            }
        }
        unset($rule);

        if ($clamped) {
            $warnings[] = UploadService::NGINX_413_HINT;
        }

        return [
            'rules' => $rules,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    protected function formatNumber(float $num): string
    {
        if (floor($num) === $num) {
            return (string)intval($num);
        }
        return rtrim(rtrim(sprintf('%.2f', $num), '0'), '.');
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
        if ((int)($setting->is_system ?? 0) === 1) {
            throw new BusinessException('系统内置设置项不允许删除');
        }

        $code = $setting->code;
        $groupId = $setting->group_id;
        $setting->delete();

        // 清除单项缓存
        $this->cacheService->clearSettingValue($code);

        // 清除当前分组缓存
        $group = $this->model()->find($groupId);
        if ($group) {
            $this->cacheService->clearGroup($group->code);

            // 如果当前分组是 tab 模式的子分组，同时清除父分组的缓存
            if ($group->parent_id > 0) {
                $parentGroup = $this->model()->find($group->parent_id);
                if ($parentGroup && $parentGroup->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
                    $this->cacheService->clearGroup($parentGroup->code);
                }
            }
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

    /**
     * Read the un-cached local upload root used by the isolated bootstrap
     * retention finalizer. This deliberately bypasses the ordinary setting
     * cache so a response-loss replay observes the committed database value.
     */
    public function getLocalUploadRootForBootstrap(): string
    {
        $setting = $this->model(Setting::class)
            ->where('code', 'local_root_path')
            ->find();
        if (!$setting) {
            throw new BusinessException('BOOTSTRAP_LOCAL_UPLOAD_SETTING_MISSING');
        }

        return (string) $setting->value;
    }

    /**
     * The sole automatic migration of local_root_path. All validation is done
     * by the caller before this method; the transaction contains only one
     * expected-old-value conditional write.
     */
    public function compareAndSetLocalUploadRootForBootstrap(string $expectedOldValue): void
    {
        $current = $this->getLocalUploadRootForBootstrap();
        if ($current === 'uploads') {
            $this->clearLocalUploadRootBootstrapCache();
            return;
        }
        if ($current !== $expectedOldValue) {
            throw new BusinessException('BOOTSTRAP_LOCAL_UPLOAD_SETTING_CONFLICT');
        }

        $updated = (int) $this->transaction(function () use ($expectedOldValue): int {
            return $this->model(Setting::class)
                ->where('code', 'local_root_path')
                ->where('value', $expectedOldValue)
                ->update(['value' => 'uploads']);
        });

        if ($updated !== 1 && $this->getLocalUploadRootForBootstrap() !== 'uploads') {
            throw new BusinessException('BOOTSTRAP_LOCAL_UPLOAD_SETTING_CONFLICT');
        }
        $this->clearLocalUploadRootBootstrapCache();
    }

    private function clearLocalUploadRootBootstrapCache(): void
    {
        $this->cacheService->clearSettingValue('local_root_path');
        $this->cacheService->clearGroup('UploadLocal');
        $this->cacheService->clearAll();
    }

    // ==================== 配置读取/保存 ====================

    /**
     * 获取分组配置（用于前端表单渲染，走缓存）
     *
     * - display_type=tab：返回子分组列表及其设置项，前端渲染为选项卡
     * - display_type=page：返回当前分组的设置项 + 如果有 tab 子分组则同时返回
     * - display_type=category：返回空设置项（纯导航）
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

        // tab 模式：返回子分组列表及其设置项
        if ($group->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
            $children = $this->model()
                ->where('parent_id', $group->id)
                ->where('status', 1)
                ->order('sort', 'asc')
                ->select()
                ->toArray();

            $tabs = [];
            foreach ($children as $child) {
                $settings = $this->cacheService->getGroupSettings($child['code'], function () use ($child) {
                    return $this->model(Setting::class)
                        ->where('group_id', $child['id'])
                        ->order('sort', 'asc')
                        ->select()
                        ->toArray();
                });
                $settings = app()->make(AssetHydrator::class)->hydrateSettings($settings);
                $settings = $this->filterVisibleSettings($settings);
                $settings = $this->applyUiMetaToSettings($settings, (string) $child['code']);
                $settings = $this->maskSensitiveSettings($settings);
                // 返回扁平化的 TabConfigItem 格式：code, icon, id, name, settings
                $tabs[] = [
                    'code' => $child['code'],
                    'icon' => $child['icon'] ?? null,
                    'id' => $child['id'],
                    'name' => $child['name'],
                    'settings' => $settings,
                ];
            }

            return [
                'group' => $group->toArray(),
                'display_type' => SettingGroup::DISPLAY_TYPE_TAB,
                'tabs' => $tabs,
            ];
        }

        // category 模式：返回空设置项（纯导航，不显示表单）
        if ($group->display_type === SettingGroup::DISPLAY_TYPE_CATEGORY) {
            return [
                'group' => $group->toArray(),
                'display_type' => SettingGroup::DISPLAY_TYPE_CATEGORY,
                'settings' => [],
            ];
        }

        // page 模式：返回当前分组的设置项
        $settings = $this->cacheService->getGroupSettings($groupCode, function () use ($group) {
            return $this->model(Setting::class)
                ->where('group_id', $group->id)
                ->order('sort', 'asc')
                ->select()
                ->toArray();
        });
        $settings = app()->make(AssetHydrator::class)->hydrateSettings($settings);
        $settings = $this->filterVisibleSettings($settings);
        $settings = $this->applyUiMetaToSettings($settings, $groupCode);
        $settings = $this->maskSensitiveSettings($settings);

        // 检查是否有 tab 类型的子分组
        $tabChildren = $this->model()
            ->where('parent_id', $group->id)
            ->where('display_type', SettingGroup::DISPLAY_TYPE_TAB)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        // 如果有 tab 子分组，同时返回
        if (!empty($tabChildren)) {
            $tabs = [];
            foreach ($tabChildren as $child) {
                $childSettings = $this->cacheService->getGroupSettings($child['code'], function () use ($child) {
                    return $this->model(Setting::class)
                        ->where('group_id', $child['id'])
                        ->order('sort', 'asc')
                        ->select()
                        ->toArray();
                });
                $childSettings = app()->make(AssetHydrator::class)->hydrateSettings($childSettings);
                $childSettings = $this->filterVisibleSettings($childSettings);
                $childSettings = $this->applyUiMetaToSettings($childSettings, (string) $child['code']);
                $childSettings = $this->maskSensitiveSettings($childSettings);
                $tabs[] = [
                    'code' => $child['code'],
                    'icon' => $child['icon'] ?? null,
                    'id' => $child['id'],
                    'name' => $child['name'],
                    'settings' => $childSettings,
                ];
            }

            return [
                'group' => $group->toArray(),
                'display_type' => SettingGroup::DISPLAY_TYPE_PAGE,
                'settings' => $settings,
                'tabs' => $tabs, // 额外返回 tab 子分组
            ];
        }

        // 普通 page 模式：只返回当前分组的设置项
        return [
            'group' => $group->toArray(),
            'display_type' => SettingGroup::DISPLAY_TYPE_PAGE,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    private function filterVisibleSettings(array $settings): array
    {
        return array_values(array_filter(
            $settings,
            fn (array $setting): bool => !in_array((string) ($setting['code'] ?? ''), self::HIDDEN_SETTING_CODES, true),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    private function applyUiMetaToSettings(array $settings, string $groupCode): array
    {
        $groupId = (int)($settings[0]['group_id'] ?? 0);
        $sectionMap = $this->getSectionMapByGroupId($groupId);
        foreach ($settings as &$setting) {
            $setting = $this->applyUiMetaToSetting($setting, $groupCode);
            $setting = $this->attachSectionLabelToSetting($setting, $sectionMap);
        }
        unset($setting);

        return $settings;
    }

    /**
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    private function attachResolvedUiMetaToSettingList(array $settings): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map(
            fn (array $setting): int => (int)($setting['group_id'] ?? 0),
            $settings
        ))));
        if (empty($groupIds)) {
            return $settings;
        }

        $groups = $this->model()
            ->whereIn('id', $groupIds)
            ->select()
            ->toArray();
        $groupCodeMap = [];
        foreach ($groups as $group) {
            $groupCodeMap[(int)$group['id']] = (string)$group['code'];
        }
        $sectionMaps = $this->getSectionMapsByGroupIds($groupIds);

        foreach ($settings as &$setting) {
            $groupId = (int)($setting['group_id'] ?? 0);
            $groupCode = $groupCodeMap[$groupId] ?? '';
            if ($groupCode !== '') {
                $resolvedSetting = $this->applyUiMetaToSetting($setting, $groupCode);
                $resolvedSetting = $this->attachSectionLabelToSetting($resolvedSetting, $sectionMaps[$groupId] ?? []);
                $setting['resolved_ui'] = $resolvedSetting['ui'] ?? null;
            }
        }
        unset($setting);

        return $settings;
    }

    /**
     * @param array<int, int> $groupIds
     * @return array<int, array<string, string>>
     */
    private function getSectionMapsByGroupIds(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (empty($groupIds)) {
            return [];
        }

        $sections = $this->model(SettingSection::class)
            ->whereIn('group_id', $groupIds)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        $maps = [];
        foreach ($sections as $section) {
            $groupId = (int)($section['group_id'] ?? 0);
            $code = (string)($section['code'] ?? '');
            if ($groupId <= 0 || $code === '') {
                continue;
            }
            $maps[$groupId][$code] = (string)($section['name'] ?? $code);
        }

        return $maps;
    }

    /**
     * @return array<string, string>
     */
    private function getSectionMapByGroupId(int $groupId): array
    {
        return $this->getSectionMapsByGroupIds([$groupId])[$groupId] ?? [];
    }

    /**
     * @param array<string, mixed> $setting
     * @param array<string, string> $sectionMap
     * @return array<string, mixed>
     */
    private function attachSectionLabelToSetting(array $setting, array $sectionMap): array
    {
        if (!isset($setting['ui']) || !is_array($setting['ui'])) {
            return $setting;
        }

        $sectionCode = (string)($setting['ui']['section_code'] ?? '');
        if ($sectionCode !== '') {
            $setting['ui']['section'] = $sectionMap[$sectionCode] ?? $sectionCode;
        }

        return $setting;
    }

    /**
     * @param array<string, mixed> $setting
     * @return array<string, mixed>
     */
    private function applyUiMetaToSetting(array $setting, string $groupCode): array
    {
        $code = (string)($setting['code'] ?? '');
        $uiMeta = self::SETTING_UI_META[$groupCode][$code] ?? null;
        $settingUi = $setting['ui'] ?? null;
        if ($this->hasSettingUiMeta($settingUi)) {
            if ($uiMeta !== null && is_array($settingUi)) {
                $setting['ui'] = array_replace_recursive($uiMeta, $settingUi);
            }
            return $setting;
        }

        if ($uiMeta !== null) {
            $setting['ui'] = $uiMeta;
        }

        return $setting;
    }

    private function hasSettingUiMeta(mixed $ui): bool
    {
        if (is_array($ui)) {
            return !empty($ui);
        }

        if (is_string($ui)) {
            return trim($ui) !== '';
        }

        return false;
    }

    /**
     * 获取分组配置值（key-value 对，供其他服务调用）
     * 支持 page 和 tab 两种模式
     */
    public function getGroupValues(string $groupCode): array
    {
        $group = $this->model()->where('code', $groupCode)->find();
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        if ($group->status !== 1) {
            throw new BusinessException('分组已禁用');
        }

        $values = [];

        if ($group->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
            $childGroups = $this->model()
                ->where('parent_id', $group->id)
                ->where('status', 1)
                ->select();

            foreach ($childGroups as $childGroup) {
                $values = array_replace($values, $this->getRawValuesByGroupId((int) $childGroup->id));
            }

            return $values;
        }

        if ($group->display_type === SettingGroup::DISPLAY_TYPE_CATEGORY) {
            return [];
        }

        return $this->getRawValuesByGroupId((int) $group->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRawValuesByGroupId(int $groupId): array
    {
        $settings = $this->model(Setting::class)
            ->where('group_id', $groupId)
            ->select();

        $values = [];
        foreach ($settings as $setting) {
            $values[(string) $setting->code] = $setting->value;
        }

        return $values;
    }

    /**
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    private function maskSensitiveSettings(array $settings): array
    {
        return array_map(fn (array $setting): array => $this->maskSensitiveSetting($setting), $settings);
    }

    /**
     * @param array<string, mixed> $setting
     * @return array<string, mixed>
     */
    private function maskSensitiveSetting(array $setting): array
    {
        if (!$this->isSensitiveSetting($setting)) {
            return $setting;
        }

        $setting['has_value'] = !$this->isBlankSensitiveValue($setting['value'] ?? null);
        $setting['value'] = '';
        $setting['sensitive'] = true;

        $ui = $setting['ui'] ?? [];
        if (is_string($ui)) {
            $decoded = json_decode($ui, true);
            $ui = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($ui)) {
            $ui = [];
        }
        $ui['sensitive'] = true;
        $setting['ui'] = $ui;

        return $setting;
    }

    private function isSensitiveSetting(mixed $setting): bool
    {
        if ($setting instanceof Setting) {
            $setting = $setting->toArray();
        }

        if (!is_array($setting)) {
            return false;
        }

        if ((string)($setting['type'] ?? '') === Setting::TYPE_PASSWORD) {
            return true;
        }

        $ui = $setting['ui'] ?? null;
        if (is_string($ui)) {
            $decoded = json_decode($ui, true);
            $ui = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($ui)) {
            return false;
        }

        return in_array($ui['sensitive'] ?? false, [true, 1, '1', 'true'], true);
    }

    private function isBlankSensitiveValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function fillSensitiveValuesForValidation(array $config, array $values): array
    {
        foreach ($this->collectSensitiveSettingsFromConfig($config) as $setting) {
            $code = (string)($setting['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if (array_key_exists($code, $values) && !$this->isBlankSensitiveValue($values[$code])) {
                continue;
            }

            $storedValue = $this->getStoredSettingValue($setting);
            if (!$this->isBlankSensitiveValue($storedValue)) {
                $values[$code] = $storedValue;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    private function collectSensitiveSettingsFromConfig(array $config): array
    {
        $settings = [];
        if (($config['display_type'] ?? '') === SettingGroup::DISPLAY_TYPE_TAB) {
            foreach ($config['tabs'] ?? [] as $tab) {
                foreach (($tab['settings'] ?? []) as $setting) {
                    if (is_array($setting) && $this->isSensitiveSetting($setting)) {
                        $settings[] = $setting;
                    }
                }
            }

            return $settings;
        }

        foreach ($config['settings'] ?? [] as $setting) {
            if (is_array($setting) && $this->isSensitiveSetting($setting)) {
                $settings[] = $setting;
            }
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $setting
     */
    private function getStoredSettingValue(array $setting): mixed
    {
        $id = (int)($setting['id'] ?? 0);
        if ($id > 0) {
            return $this->model(Setting::class)
                ->where('id', $id)
                ->value('value');
        }

        return $this->model(Setting::class)
            ->where('code', (string)($setting['code'] ?? ''))
            ->value('value');
    }

    /**
     * 保存分组配置（批量更新值）
     * 支持 page 和 tab 两种模式
     */
    public function saveGroupValues(string $groupCode, array $values): bool
    {
        $this->assertLocalUploadRootGroupValues($values);

        $group = $this->model()->where('code', $groupCode)->find();
        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $updatedCodes = [];

        if ($group->display_type === SettingGroup::DISPLAY_TYPE_TAB) {
            // tab 模式：遍历所有启用的子分组，逐个更新设置项
            $childGroups = $this->model()
                ->where('parent_id', $group->id)
                ->where('status', 1)
                ->select();

            foreach ($childGroups as $childGroup) {
                $settings = $this->model(Setting::class)
                    ->where('group_id', $childGroup->id)
                    ->select();

                foreach ($settings as $setting) {
                    if (array_key_exists($setting->code, $values)) {
                        $updatedCode = $this->saveSettingValue($setting, $values[$setting->code]);
                        if ($updatedCode !== null) {
                            $updatedCodes[] = $updatedCode;
                        }
                    }
                }

                // 清除子分组缓存
                $this->cacheService->clearGroup($childGroup->code);
            }
        } else {
            // page 模式：只更新当前分组的设置项
            $settings = $this->model(Setting::class)
                ->where('group_id', $group->id)
                ->select();

            foreach ($settings as $setting) {
                if (array_key_exists($setting->code, $values)) {
                    $updatedCode = $this->saveSettingValue($setting, $values[$setting->code]);
                    if ($updatedCode !== null) {
                        $updatedCodes[] = $updatedCode;
                    }
                }
            }
        }

        $this->cacheService->clearSettingValues($updatedCodes);
        $this->cacheService->clearGroup($groupCode);

        return true;
    }

    private function saveSettingValue(Setting $setting, mixed $value): ?string
    {
        $this->assertLocalUploadRootSettingValue($setting->code, $value);

        if ($this->isSensitiveSetting($setting) && $this->isBlankSensitiveValue($value)) {
            return null;
        }

        if ((string)$setting->type === Setting::TYPE_OPTION_LIST) {
            $value = $this->normalizeOptionListValue($value);
        }

        $setting->value = $value;
        $setting->save();

        return (string)$setting->code;
    }

    private function normalizeOptionListValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '[]';
        }

        $rows = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($rows)) {
            return '[]';
        }

        $result = [];
        $labels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string)($row['label'] ?? ''));
            if ($label === '' || isset($labels[$label])) {
                continue;
            }
            $labels[$label] = true;

            $optionValue = trim((string)($row['value'] ?? ''));
            if ($optionValue === '') {
                $optionValue = $this->generateOptionValue();
            }

            $result[] = ['value' => $optionValue, 'label' => $label];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private function generateOptionValue(): string
    {
        try {
            return 'CUSTOM_' . strtoupper(bin2hex(random_bytes(4)));
        } catch (\Throwable) {
            return 'CUSTOM_' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        }
    }

    /**
     * 校验并保存分组配置值
     */
    public function saveGroupValuesWithValidation(string $groupCode, array $values): bool
    {
        $this->assertLocalUploadRootGroupValues($values);

        $config = $this->getGroupConfig($groupCode);
        $valuesForValidation = $this->fillSensitiveValuesForValidation($config, $values);
        $effectiveValues = array_replace($this->collectValuesFromConfig($config), $valuesForValidation);
        $allSettings = $this->collectSettingsForValidation($config, $effectiveValues);

        $validate = new SettingValueValidate();
        $errors = $validate->validateGroupValues($allSettings, $effectiveValues);
        if (!empty($errors)) {
            $firstError = is_array($errors) ? (string)reset($errors) : '';
            throw new BusinessException($firstError !== '' ? $firstError : '配置验证失败');
        }

        return $this->saveGroupValues($groupCode, $values);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function assertLocalUploadRootGroupValues(array $values): void
    {
        if (!array_key_exists('local_root_path', $values)) {
            return;
        }

        $this->assertLocalUploadRootSettingValue(
            'local_root_path',
            $values['local_root_path'],
        );
    }

    private function assertLocalUploadRootSettingValue(mixed $code, mixed $value): void
    {
        if ($code !== 'local_root_path') {
            return;
        }

        try {
            (new LocalUploadRootPolicy())->assertCanonical($value);
        } catch (\RuntimeException) {
            throw new BusinessException(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE);
        }
    }

    /**
     * 从分组配置中提取需要校验的设置项
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    protected function collectSettingsForValidation(array $config, array $values = []): array
    {
        if (($config['display_type'] ?? '') === SettingGroup::DISPLAY_TYPE_TAB) {
            $allSettings = [];
            foreach ($config['tabs'] ?? [] as $tab) {
                foreach (($tab['settings'] ?? []) as $setting) {
                    if ($this->isSettingVisibleForValues($setting, $values)) {
                        $allSettings[] = $setting;
                    }
                }
            }
            return $allSettings;
        }

        return array_values(array_filter(
            $config['settings'] ?? [],
            fn (array $setting): bool => $this->isSettingVisibleForValues($setting, $values),
        ));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function collectValuesFromConfig(array $config): array
    {
        $values = [];

        if (($config['display_type'] ?? '') === SettingGroup::DISPLAY_TYPE_TAB) {
            foreach ($config['tabs'] ?? [] as $tab) {
                foreach (($tab['settings'] ?? []) as $setting) {
                    if (is_array($setting) && isset($setting['code'])) {
                        $values[(string) $setting['code']] = $setting['value'] ?? null;
                    }
                }
            }

            return $values;
        }

        foreach ($config['settings'] ?? [] as $setting) {
            if (is_array($setting) && isset($setting['code'])) {
                $values[(string) $setting['code']] = $setting['value'] ?? null;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $setting
     * @param array<string, mixed> $values
     */
    protected function isSettingVisibleForValues(array $setting, array $values): bool
    {
        $conditions = $setting['ui']['visible_when'] ?? [];
        if (!is_array($conditions) || empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition) || !$this->matchesUiCondition($condition, $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $values
     */
    protected function matchesUiCondition(array $condition, array $values): bool
    {
        $field = (string) ($condition['field'] ?? '');
        if ($field === '') {
            return true;
        }

        $operator = (string) ($condition['operator'] ?? 'equals');
        $actual = $values[$field] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($operator) {
            'truthy' => $this->uiTruthy($actual),
            'falsy' => !$this->uiTruthy($actual),
            'not_equals' => $this->normalizeUiCompareValue($actual) !== $this->normalizeUiCompareValue($expected),
            'in' => is_array($expected)
                && in_array($this->normalizeUiCompareValue($actual), array_map(fn ($item) => $this->normalizeUiCompareValue($item), $expected), true),
            default => $this->normalizeUiCompareValue($actual) === $this->normalizeUiCompareValue($expected),
        };
    }

    private function uiTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeUiCompareValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

}
