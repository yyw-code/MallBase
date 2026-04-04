<?php

declare (strict_types=1);

namespace app\admin\controller\setting;

use app\admin\service\auth\PermissionService;
use app\admin\service\setting\SettingService;
use mall_base\base\BaseController;

/**
 * 设置分组控制器（对应前端分组管理页面）
 * @extends BaseController<SettingService>
 */
class GroupController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = SettingService::class;

    // ==================== 权限菜单树 ====================

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
    public function list()
    {
        $where = $this->request->param(['keyword', 'status', 'parent_id']);
        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getGroupList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 分组树形列表（不分页，返回树形结构）
     */
    public function tree()
    {
        $where = $this->request->param(['keyword', 'status']);
        $tree = $this->service()->getGroupTree($where);
        return $this->success($tree, '获取成功');
    }

    /**
     * 所有启用的分组（不分页，树形结构）
     */
    public function all()
    {
        $list = $this->service()->getAllGroups();
        return $this->success($list, '获取成功');
    }

    /**
     * 分组详情（用于编辑回显）
     */
    public function info($id)
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
    public function create()
    {
        $data = $this->request->param(['parent_id', 'menu_parent_permission_id', 'name', 'code', 'icon', 'description', 'sort', 'status']);

        $this->validate($data, 'setting/SettingGroup.create');

        $id = $this->service()->createGroup($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新分组
     */
    public function update($id)
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
     * 修改分组状态（同步更新对应权限状态）
     */
    public function changeStatus($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $status = $this->request->param('status');

        if ($status === null || !in_array(intval($status), [0, 1], true)) {
            return $this->error('状态值无效');
        }

        $this->service()->changeGroupStatus((int)$id, intval($status));
        return $this->success(null, '状态更新成功');
    }

    /**
     * 删除分组
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->deleteGroup((int)$id);
        return $this->success(null, '删除成功');
    }
}