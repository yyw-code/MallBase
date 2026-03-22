<?php

declare (strict_types=1);

namespace app\admin\controller\auth;

use app\admin\service\auth\AdminService;
use app\admin\validate\auth\AdminValidate;
use mall_base\base\BaseController;

/**
 * 管理员控制器
 * @extends BaseController<AdminService>
 */
class AdminController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = AdminService::class;

    /**
     * 登录
     * @param int $username required source=body 登录用户名
     * @param string $password required  source=body 登录密码
     */
    public function login()
    {
        $data = $this->request->param(['username', 'password']);

        // 验证登录参数
        $this->validate($data, AdminValidate::class . '.login');

        $result = $this->service()->login($data['username'], $data['password']);
        return $this->success($result, '登录成功');
    }

    /**
     * 刷新 Token
     */
    public function refreshToken()
    {
        $data = $this->request->param(['refresh_token']);
        $refreshToken = $data['refresh_token'] ?? '';

        if (empty($refreshToken)) {
            return $this->error('刷新令牌不能为空');
        }

        $result = $this->service()->refreshToken($refreshToken);
        return $this->success($result, '刷新成功');
    }

    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['username', 'status']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        $list = $this->service()->getList($where, $page, $limit);
        return $this->success($list, '获取成功');
    }

    /**
     * 获取详情
     */
    public function info()
    {
        $id = $this->request->admin_id;

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
        $data = $this->request->param(['username', 'password', 'password_confirm', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids']);

        // 验证创建参数（包括密码确认）
        $this->validate($data, AdminValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新
     */
    public function update($id)
    {
        $data = $this->request->param(['username', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids']);

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证更新参数
        $this->validate($data, AdminValidate::class . '.update');

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

    /**
     * 重置密码
     * @param int $id required source=body 管理员ID
     * @param string $password required source=body 新密码
     * @param string $password_confirm required source=body 确认密码
     */
    public function resetPassword($id)
    {
        $data = $this->request->param(['password']);

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证重置密码参数
        $this->validate($data, AdminValidate::class . '.resetPassword');

        $this->service()->resetPassword((int)$id, $data['password']);
        return $this->success(null, '密码重置成功');
    }


    /**
     * 修改密码
     * @param int $id required source=body 管理员ID
     * @param string $password required source=body 新密码
     * @param string $password_confirm required source=body 确认密码
     */
    public function changePassword()
    {
        $data = $this->request->param(['password', 'password_confirm']);
        $id = $this->request->admin_id;

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证重置密码参数
        $this->validate($data, AdminValidate::class . '.changePassword');

        $this->service()->resetPassword((int)$id, $data['password']);
        return $this->success(null, '密码重置成功');
    }

    /**
     * 获取权限信息（权限码、菜单、路由）
     */
    public function getAccessInfo()
    {
        $adminId = $this->request->admin_id;
        $info = $this->service()->getAccessInfo($adminId);
        return $this->success($info, '获取成功');
    }
}
