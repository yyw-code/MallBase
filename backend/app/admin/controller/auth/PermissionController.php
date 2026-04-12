<?php

declare (strict_types=1);

namespace app\admin\controller\auth;

use app\admin\service\auth\PermissionService;
use app\admin\validate\auth\PermissionValidate;
use mall_base\base\BaseController;

/**
 * 权限控制器
 * @extends BaseController<PermissionService>
 */
class PermissionController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = PermissionService::class;

    /**
     * 获取树形列表
     */
    public function tree()
    {
        $where = $this->request->param(['keyword', 'type', 'status', 'source']);

        $tree = $this->service()->getTree($where);
        return $this->success($tree, '获取成功');
    }

    /**
     * 获取菜单路由
     */
    public function menu()
    {
        $adminId = $this->request->admin_id;
        $routes = $this->service()->getMenu($adminId);

        return $this->success($routes, '获取成功');

    }

    /**
     * 获取权限信息（权限码）
     */
    public function getAccessCodes()
    {
        $adminId = $this->request->admin_id;
        $info = $this->service()->getAccessCodes($adminId);
        return $this->success($info, '获取成功');
    }


    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'type', 'status']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        $data = $this->service()->getList($where, $page, $limit);
        return $this->success($data, '获取成功');

    }

    /**
     * 获取详情
     */
    public function info($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getInfo((int)$id);
        return $this->success($info, '获取成功');
    }

    /**
     * 创建
     */
    public function create()
    {
        $data = $this->request->param(['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'redirect', 'affix_tab', 'no_basic_layout', 'sort', 'status', 'is_show', 'remark']);

        // 验证创建参数
        $this->validate($data, PermissionValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新
     */
    public function update($id)
    {
        $data = $this->request->param(['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'redirect', 'affix_tab', 'no_basic_layout', 'sort', 'status', 'is_show', 'remark']);

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证更新参数
        $this->validate($data, PermissionValidate::class . '.update');

        $this->service()->update((int)$id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete((int)$id);
        return $this->success(null, '删除成功');
    }

    /**
     * 批量更新字段（处理上下级关系）
     */
    public function batchUpdate($id)
    {
        $field = $this->request->param('field'); // status, is_show, affix_tab, no_basic_layout
        $value = $this->request->param('value');
        $includeChildren = $this->request->param('include_children', true); // 是否同时更新子节点

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        if (empty($field)) {
            return $this->error('字段不能为空');
        }

        // 验证字段是否合法
        $validFields = ['status', 'is_show', 'affix_tab', 'no_basic_layout'];
        if (!in_array($field, $validFields)) {
            return $this->error('字段不合法');
        }

        // 业务逻辑下沉到 Service：统一事务与缓存清理
        $this->service()->batchUpdateField((int)$id, $field, $value, (bool) $includeChildren);

        return $this->success(null, '更新成功');

    }
}
