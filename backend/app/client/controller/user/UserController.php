<?php
declare(strict_types=1);

namespace app\client\controller\user;

use app\client\service\UserService;
use app\client\validate\user\UserValidate;
use mall_base\base\BaseController;

/**
 * 前台用户控制器
 */
class UserController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UserService::class;

    /**
     * 用户注册
     */
    public function register()
    {
        $data = $this->request->param(['mobile', 'email', 'password', 'nickname']);

        // 验证参数
        if (!empty($data['mobile'])) {
            $this->validate($data, UserValidate::class . '.register');
            $registerType = 'mobile';
            $account = $data['mobile'];
        } elseif (!empty($data['email'])) {
            $this->validate($data, UserValidate::class . '.registerByEmail');
            $registerType = 'email';
            $account = $data['email'];
        } else {
            return $this->error('请输入手机号或邮箱');
        }

        $result = $this->service()->register($account, $data['password'], $registerType);
        return $this->success($result, '注册成功');
    }

    /**
     * 用户登录
     */
    public function login()
    {
        $data = $this->request->param(['account', 'password']);

        if (empty($data['account'])) {
            return $this->error('请输入手机号或邮箱');
        }

        if (empty($data['password'])) {
            return $this->error('请输入密码');
        }

        $result = $this->service()->login($data['account'], $data['password']);
        return $this->success($result, '登录成功');
    }

    /**
     * 用户登出
     */
    public function logout()
    {
        $userId = $this->request->user_id ?? null;

        if (empty($userId)) {
            return $this->error('未登录');
        }

        $this->service()->logout((int) $userId);
        return $this->success(null, '登出成功');
    }

    /**
     * 获取用户列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'status', 'register_type']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
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

    /**
     * 获取当前登录用户信息
     */
    public function getMyInfo()
    {
        $userId = $this->request->user_id ?? null;

        if (empty($userId)) {
            return $this->error('未登录');
        }

        $info = $this->service()->getMyInfo((int) $userId);
        return $this->success($info, '获取成功');
    }

    /**
     * 当前用户更新信息
     */
    public function updateMyInfo()
    {
        $userId = $this->request->user_id ?? null;

        if (empty($userId)) {
            return $this->error('未登录');
        }

        $data = $this->request->param([
            'nickname', 'real_name', 'gender', 'birthday',
            'province', 'city', 'district', 'bio', 'avatar',
        ]);

        $this->service()->updateMyInfo((int) $userId, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 当前用户修改密码
     */
    public function updateMyPassword()
    {
        $userId = $this->request->user_id ?? null;

        if (empty($userId)) {
            return $this->error('未登录');
        }

        $data = $this->request->param(['old_password', 'password']);

        if (empty($data['old_password'])) {
            return $this->error('旧密码不能为空');
        }

        if (empty($data['password'])) {
            return $this->error('新密码不能为空');
        }

        $this->service()->updatePassword((int) $userId, $data['old_password'], $data['password']);
        return $this->success(null, '修改成功');
    }
}
