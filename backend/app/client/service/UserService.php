<?php
declare(strict_types=1);

namespace app\client\service;

use app\model\user\User;
use app\model\user\UserGroup;
use app\model\user\UserGroupRelation;
use app\model\user\UserTag;
use app\model\user\UserTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\service\JwtCacheService;
use mall_base\service\JwtService;
use think\facade\Request;

/**
 * 前台用户服务
 * @extends BaseService<User>
 */
class UserService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = User::class;

    /**
     * 用户注册
     */
    public function register(string $account, string $password, string $registerType): array
    {
        // 检查账号是否已存在
        $field = $registerType === 'mobile' ? 'mobile' : 'email';
        $exists = $this->model()->where($field, $account)->find();
        if ($exists) {
            throw new BusinessException('该' . ($registerType === 'mobile' ? '手机号' : '邮箱') . '已被注册');
        }

        // 创建用户
        $user = $this->model();
        $user->save([
            $field => $account,
            'password' => $password,
            'register_type' => $registerType,
            'register_ip' => Request::ip(),
            'nickname' => $this->generateNickname($registerType, $account),
            'status' => 1,
        ]);

        // 生成 JWT Token
        $jwtService = app()->make(JwtService::class);
        $token = $jwtService->encode([
            'user_id' => $user->id,
            'account' => $account,
            'register_type' => $registerType,
        ]);

        // 存储 refresh_token 到 Redis
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $user->id,
            $jwtService->getRefreshExpire()
        );

        return $token;
    }

    /**
     * 用户登录
     */
    public function login(string $account, string $password): array
    {
        // 通过手机号或邮箱查找用户
        $user = $this->model()
            ->where('mobile', $account)
            ->whereOr('email', $account)
            ->where('status', 1)
            ->find();

        if (!$user) {
            throw new BusinessException('账号不存在或已禁用');
        }

        if (!$user->checkPassword($password)) {
            throw new BusinessException('密码错误');
        }

        // 更新登录信息
        $user->last_login_time = date('Y-m-d H:i:s');
        $user->last_login_ip = Request::ip();
        $user->save();

        // 生成 JWT Token
        $jwtService = app()->make(JwtService::class);
        $token = $jwtService->encode([
            'user_id' => $user->id,
            'account' => $account,
            'register_type' => $user->register_type,
        ]);

        // 存储 refresh_token 到 Redis
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $user->id,
            $jwtService->getRefreshExpire()
        );

        return $token;
    }

    /**
     * 获取用户列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('mobile|email|nickname|real_name', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null, function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(!empty($where['register_type']), function ($q) use ($where) {
                $q->where('register_type', $where['register_type']);
            });

        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 移除敏感信息
        foreach ($list as &$item) {
            unset($item['password']);
        }

        return compact('total', 'list');
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

        $info = $user->toArray();
        unset($info['password']);

        // 获取用户分组
        $info['groups'] = $this->getUserGroups($id);

        // 获取用户标签
        $info['tags'] = $this->getUserTags($id);

        return $info;
    }

    /**
     * 创建用户（后台管理）
     */
    public function create(array $data): int
    {
        // 检查手机号或邮箱是否已存在
        if (!empty($data['mobile'])) {
            $exists = $this->model()->where('mobile', $data['mobile'])->find();
            if ($exists) {
                throw new BusinessException('该手机号已被注册');
            }
        }
        if (!empty($data['email'])) {
            $exists = $this->model()->where('email', $data['email'])->find();
            if ($exists) {
                throw new BusinessException('该邮箱已被注册');
            }
        }

        return $this->transaction(function () use ($data) {
            $user = $this->model();
            $user->save([
                'mobile' => $data['mobile'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'nickname' => $data['nickname'] ?? '',
                'real_name' => $data['real_name'] ?? '',
                'gender' => $data['gender'] ?? 0,
                'birthday' => $data['birthday'] ?? null,
                'status' => $data['status'] ?? 1,
                'remark' => $data['remark'] ?? '',
            ]);

            return $user->id;
        });
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

        // 检查手机号是否重复
        if (!empty($data['mobile']) && $data['mobile'] !== $user->mobile) {
            $exists = $this->model()
                ->where('mobile', $data['mobile'])
                ->where('id', '<>', $id)
                ->value('id');
            if ($exists) {
                throw new BusinessException('该手机号已被使用');
            }
        }

        // 检查邮箱是否重复
        if (!empty($data['email']) && $data['email'] !== $user->email) {
            $exists = $this->model()
                ->where('email', $data['email'])
                ->where('id', '<>', $id)
                ->value('id');
            if ($exists) {
                throw new BusinessException('该邮箱已被使用');
            }
        }

        return $this->transaction(function () use ($id, $data) {
            $this->model()->updateById($id, $data);
            return true;
        });
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

        // 软删除
        $user->delete();

        return true;
    }

    /**
     * 重置密码（后台管理）
     */
    public function resetPassword(int $id, string $newPassword): bool
    {
        $user = $this->model()->find($id);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        // 使用模型属性赋值，会触发 setPasswordAttr 修改器
        $user->password = $newPassword;
        return $user->save();
    }

    /**
     * 更新状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $user = $this->model()->find($id);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $this->model()->updateById($id, ['status' => $status]);
        return true;
    }

    /**
     * 用户修改自己的密码
     */
    public function updatePassword(int $id, string $oldPassword, string $newPassword): bool
    {
        $user = $this->model()->find($id);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        if (!$user->checkPassword($oldPassword)) {
            throw new BusinessException('旧密码错误');
        }

        // 使用模型属性赋值，会触发 setPasswordAttr 修改器
        $user->password = $newPassword;
        return $user->save();
    }

    /**
     * 用户登出
     */
    public function logout(int $userId): bool
    {
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->clearUserTokens($userId);
        return true;
    }

    /**
     * 获取当前登录用户信息
     */
    public function getMyInfo(int $userId): array
    {
        $user = $this->model()->find($userId);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $info = $user->toArray();
        unset($info['password']);

        // 获取用户分组
        $info['groups'] = $this->getUserGroups($userId);

        // 获取用户标签
        $info['tags'] = $this->getUserTags($userId);

        return $info;
    }

    /**
     * 获取用户的所有分组
     */
    public function getUserGroups(int $userId): array
    {
        $relations = $this->model(UserGroupRelation::class)->where('user_id', $userId)
            ->select();

        $groupIds = array_column($relations->toArray(), 'group_id');

        if (empty($groupIds)) {
            return [];
        }

        $groups = $this->model(UserGroup::class)->where('status', 1)
            ->whereIn('id', $groupIds)
            ->select();

        return $groups->toArray();
    }

    /**
     * 获取用户的所有标签
     */
    public function getUserTags(int $userId): array
    {
        $relations = $this->model(UserTagRelation::class)->where('user_id', $userId)
            ->select();

        $tagIds = array_column($relations->toArray(), 'tag_id');

        if (empty($tagIds)) {
            return [];
        }

        $tags = $this->model(UserTag::class)->where('status', 1)
            ->whereIn('id', $tagIds)
            ->select();

        return $tags->toArray();
    }

    /**
     * 用户更新自己的信息
     */
    public function updateMyInfo(int $id, array $data): bool
    {
        $user = $this->model()->find($id);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        // 只允许更新部分字段
        $allowFields = ['nickname', 'real_name', 'gender', 'birthday', 'province', 'city', 'district', 'bio', 'avatar'];
        $updateData = array_intersect_key($data, array_flip($allowFields));

        if (empty($updateData)) {
            return true;
        }

        $this->model()->updateById($id, $updateData);
        return true;
    }

    /**
     * 生成默认昵称
     */
    protected function generateNickname(string $registerType, string $account): string
    {
        if ($registerType === 'mobile') {
            // 手机号中间4位隐藏
            return '用户' . substr($account, 0, 3) . '****' . substr($account, -4);
        } else {
            // 邮箱取@前的部分
            $parts = explode('@', $account);
            return $parts[0] ?? '用户';
        }
    }
}
