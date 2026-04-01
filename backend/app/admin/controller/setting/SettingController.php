<?php

declare (strict_types=1);

namespace app\admin\controller\setting;

use app\admin\service\auth\PermissionService;
use app\admin\service\setting\SettingService;
use mall_base\base\BaseController;

/**
 * 设置控制器（分组 + 设置项 + 配置读取/保存）
 * @extends BaseController<SettingService>
 */
class SettingController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = SettingService::class;

    // ==================== 权限菜单树（设置模块用） ====================

    /**
     * 获取菜单权限树（仅纯目录，排除有 component 的菜单）
     * 用于设置分组选择父菜单挂载位置
     */
    public function menuTree()
    {
        $permissionService = app()->make(PermissionService::class);
        $tree = $permissionService->getTree(['type' => 1, 'status' => 1, 'component_empty' => 1]);
        return $this->success($tree, '获取成功');
    }

    // ==================== 分组管理 ====================

    /**
     * 分组列表（支持按 parent_id 筛选）
     */
    public function groupList()
    {
        $where = $this->request->param(['keyword', 'status', 'parent_id']);
        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getGroupList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 分组树形列表（不分页，返回树形结构）
     */
    public function groupTree()
    {
        $where = $this->request->param(['keyword', 'status']);
        $tree = $this->service()->getGroupTree($where);
        return $this->success($tree, '获取成功');
    }

    /**
     * 所有启用的分组（不分页，树形结构）
     */
    public function groupAll()
    {
        $list = $this->service()->getAllGroups();
        return $this->success($list, '获取成功');
    }

    /**
     * 分组详情（用于编辑回显）
     */
    public function groupInfo($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getGroupInfo((int)$id);
        return $this->success($info, '获取成功');
    }

    /**
     * 创建分组
     */
    public function groupCreate()
    {
        $data = $this->request->param(['parent_id', 'menu_parent_permission_id', 'name', 'code', 'icon', 'description', 'sort', 'status']);

        $this->validate($data, 'setting/SettingGroup.create');

        $id = $this->service()->createGroup($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新分组
     */
    public function groupUpdate($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['parent_id', 'menu_parent_permission_id', 'name', 'code', 'icon', 'description', 'sort', 'status']);

        $this->validate($data, 'setting/SettingGroup.update');

        $this->service()->updateGroup((int)$id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除分组
     */
    public function groupDelete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->deleteGroup((int)$id);
        return $this->success(null, '删除成功');
    }

    // ==================== 设置项管理 ====================

    /**
     * 设置项列表（按分组）
     */
    public function settingList()
    {
        $groupId = $this->request->param('group_id', 0);

        if (empty($groupId)) {
            return $this->error('分组ID不能为空');
        }

        $list = $this->service()->getSettingList((int)$groupId);
        return $this->success($list, '获取成功');
    }

    /**
     * 创建设置项
     */
    public function settingCreate()
    {
        $data = $this->request->param(['group_id', 'name', 'code', 'value', 'type', 'options', 'placeholder', 'remark', 'sort', 'is_required']);

        $this->validate($data, 'setting/SettingItem.create');

        $id = $this->service()->createSetting($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新设置项
     */
    public function settingUpdate($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'code', 'value', 'type', 'options', 'placeholder', 'remark', 'sort', 'is_required']);

        $this->validate($data, 'setting/SettingItem.update');

        $this->service()->updateSetting((int)$id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除设置项
     */
    public function settingDelete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->deleteSetting((int)$id);
        return $this->success(null, '删除成功');
    }

    // ==================== 配置读取/保存（前端使用） ====================

    /**
     * 获取分组配置（前端渲染表单用）
     * GET /setting/config/:groupCode
     */
    public function getConfig($groupCode)
    {
        if (empty($groupCode)) {
            return $this->error('分组编码不能为空');
        }

        $config = $this->service()->getGroupConfig($groupCode);
        return $this->success($config, '获取成功');
    }

    /**
     * 保存分组配置（前端提交表单）
     * POST /setting/saveConfig/:groupCode
     */
    public function saveConfig($groupCode)
    {
        if (empty($groupCode)) {
            return $this->error('分组编码不能为空');
        }

        // 获取所有提交的值（排除 groupCode 路由参数）
        $values = $this->request->except(['groupCode'], 'param');

        if (empty($values)) {
            return $this->error('没有需要保存的配置');
        }

        $this->service()->saveGroupValues($groupCode, $values);
        return $this->success(null, '保存成功');
    }
}