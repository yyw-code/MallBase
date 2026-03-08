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
        $where = $this->request->param(['keyword', 'type', 'status']);

        try {
            $tree = $this->service()->getTree($where);
            return $this->success($tree, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'type', 'status']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        try {
            $list = $this->service()->getList($where, $page, $limit);
            return $this->success($list, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 获取详情
     */
    public function info($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空', 400);
        }

        try {
            $info = $this->service()->getInfo((int)$id);
            return $this->success($info, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 创建
     */
    public function create()
    {
        $data = $this->request->param(['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'sort', 'status', 'is_show', 'remark']);

        // 验证创建参数
        $this->validate($data, PermissionValidate::class . '.create');

        try {
            $id = $this->service()->create($data);
            return $this->success(['id' => $id], '创建成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 更新
     */
    public function update($id)
    {
        $data = $this->request->param(['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'sort', 'status', 'is_show', 'remark']);

        if (empty($id)) {
            return $this->error('ID不能为空', 400);
        }

        // 验证更新参数
        $this->validate($data, PermissionValidate::class . '.update');

        try {
            $this->service()->update((int)$id, $data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 删除
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空', 400);
        }

        try {
            $this->service()->delete((int)$id);
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}