<?php

declare(strict_types=1);

namespace app\admin\service\user;

use app\admin\model\user\User;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 前台用户管理服务（后台管理用）
 */
class UserService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = User::class;

    /**
     * 获取用户列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 15): array
    {
        $query = $this->model()->order('id', 'desc');

        // 关键词搜索
        if (!empty($where['keyword'])) {
            $query->whereLike('mobile|email|nickname', "%{$where['keyword']}%");
        }

        // 状态筛选
        if (isset($where['status']) && $where['status'] !== null) {
            $query->where('status', $where['status']);
        }

        // 注册方式筛选
        if (!empty($where['register_type'])) {
            $query->where('register_type', $where['register_type']);
        }

        $list = $query->page($page, $limit)->select();
        $total = $this->model()->when(!empty($where['keyword']), function ($q) use ($where) {
            $q->whereLike('mobile|email|nickname', "%{$where['keyword']}%");
        })->when(isset($where['status']) && $where['status'] !== null, function ($q) use ($where) {
            $q->where('status', $where['status']);
        })->when(!empty($where['register_type']), function ($q) use ($where) {
            $q->where('register_type', $where['register_type']);
        })->count();

        return [
            'list' => $list->toArray(),
            'total' => $total,
        ];
    }

    /**
     * 获取用户详情
     */
    public function getInfo(int $id): array
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        return $user->toArray();
    }

    /**
     * 创建用户
     */
    public function create(array $data): int
    {
        // 检查手机号是否已存在
        if (!empty($data['mobile'])) {
            $exists = $this->model()->where('mobile', $data['mobile'])->find();
            if ($exists) {
                throw new BusinessException('该手机号已被注册');
            }
        }

        // 检查邮箱是否已存在
        if (!empty($data['email'])) {
            $exists = $this->model()->where('email', $data['email'])->find();
            if ($exists) {
                throw new BusinessException('该邮箱已被注册');
            }
        }

        $user = $this->model();
        $user->save($data);

        return $user->id;
    }

    /**
     * 更新用户
     */
    public function update(int $id, array $data): bool
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        // 检查手机号是否已被其他人使用
        if (!empty($data['mobile'])) {
            $exists = $this->model()
                ->where('mobile', $data['mobile'])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                throw new BusinessException('该手机号已被使用');
            }
        }

        // 检查邮箱是否已被其他人使用
        if (!empty($data['email'])) {
            $exists = $this->model()
                ->where('email', $data['email'])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                throw new BusinessException('该邮箱已被使用');
            }
        }

        $user->save($data);

        return true;
    }

    /**
     * 删除用户
     */
    public function delete(int $id): bool
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        return $user->delete();
    }

    /**
     * 更新用户状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $user->save(['status' => $status]);

        return true;
    }

    /**
     * 重置密码
     */
    public function resetPassword(int $id, string $password): bool
    {
        $user = $this->model()->find($id);

        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $user->save(['password' => $password]);

        return true;
    }
}