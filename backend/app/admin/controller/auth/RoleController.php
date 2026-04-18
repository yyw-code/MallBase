<?php

declare (strict_types=1);

namespace app\admin\controller\auth;

use app\service\admin\auth\RoleService;
use app\admin\validate\auth\RoleValidate;
use mall_base\base\BaseController;

/**
 * 角色控制器
 * @extends BaseController<RoleService>
 */
class RoleController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = RoleService::class;

    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $list = $this->service()->getList($where, $page, $limit);
        return $this->success($list, '获取成功');
    }

    /**
     * 获取所有角色
     */
    public function all()
    {
        $list = $this->service()->getAll();
        return $this->success($list, '获取成功');
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
        $data = $this->request->param(['name', 'code', 'status', 'sort', 'remark', 'menu_permission_ids', 'api_permission_ids', 'button_permission_ids']);

        // 验证创建参数
        $this->validate($data, RoleValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新
     */
    public function update($id)
    {
        $data = $this->request->param(['name', 'code', 'status', 'sort', 'remark', 'menu_permission_ids', 'api_permission_ids', 'button_permission_ids']);

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证更新参数
        $this->validate($data, RoleValidate::class . '.update');

        $this->service()->update((int)$id, $data);
        return $this->success(null, '更新成功');
    }

    public function changeStatus($id)
    {
        $status = $this->request->param('status');
        if (empty($id)) {
            return $this->error('ID不能为空');
        }
        if (!in_array($status, [0, 1])) {
            return $this->error('状态值错误');
        }
        $this->service()->changeStatus((int)$id, $status);
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
}