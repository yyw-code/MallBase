<?php

declare(strict_types=1);

namespace app\controller\admin\user;

use app\service\admin\user\UserService;
use app\validate\admin\user\UserValidate;
use mall_base\base\BaseController;

/**
 * 前台用户管理控制器（后台管理用）
 * @extends BaseController<UserService>
 */
class UserController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UserService::class;

    /**
     * 获取用户列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'status', 'register_type', 'group_ids', 'tag_ids']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function stats()
    {
        $where = $this->request->param(['keyword', 'status', 'register_type', 'group_ids', 'tag_ids']);
        return $this->success($this->service()->stats($where), '获取成功');
    }

    public function memberLevelOptions()
    {
        return $this->success($this->service()->memberLevelOptions(), '获取成功');
    }

    public function export()
    {
        $where = $this->request->param(['keyword', 'status', 'register_type', 'group_ids', 'tag_ids']);

        return response($this->service()->exportCsv($where), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="users.csv"',
        ]);
    }

    /**
     * 获取用户详情
     */
    public function info($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    /**
     * 创建用户
     */
    public function create()
    {
        $data = $this->request->param([
            'mobile', 'email', 'password', 'nickname',
            'real_name', 'gender', 'birthday', 'status', 'remark',
            'avatar', 'group_ids', 'tag_ids',
        ]);

        $this->validate($data, UserValidate::class . '.create');

        // 手机号和邮箱至少有一个
        if (empty($data['mobile']) && empty($data['email'])) {
            return $this->error('手机号和邮箱至少填写一个');
        }

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新用户
     */
    public function update($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'mobile', 'email', 'nickname', 'real_name',
            'gender', 'birthday', 'status', 'remark',
            'avatar', 'group_ids', 'tag_ids',
        ]);

        $this->validate($data, UserValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除用户
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    /**
     * 更新用户状态
     */
    public function updateStatus($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['status']);

        if (!isset($data['status'])) {
            return $this->error('状态不能为空');
        }

        $this->service()->updateStatus((int) $id, (int) $data['status']);
        return $this->success(null, '更新成功');
    }

    /**
     * 重置密码（后台管理）
     */
    public function resetPassword($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['password']);

        if (empty($data['password'])) {
            return $this->error('密码不能为空');
        }

        $this->service()->resetPassword((int) $id, $data['password']);
        return $this->success(null, '重置成功');
    }

    public function setMember($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['level_id', 'locked', 'lock_until', 'remark']);
        $result = $this->service()->setMemberLevel(
            (int) $id,
            $data,
            (int) ($this->request->admin_id ?? 0)
        );

        return $this->success($result, '设置成功');
    }
}
