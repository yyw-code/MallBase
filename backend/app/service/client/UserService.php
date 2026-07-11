<?php
declare(strict_types=1);

namespace app\service\client;

use app\common\enum\RegisterType;
use app\model\user\User;
use app\model\user\UserGroup;
use app\model\user\UserGroupRelation;
use app\model\user\UserTag;
use app\model\user\UserTagRelation;
use app\service\UploadService;
use app\service\upload\AssetHydrator;
use app\service\user\UserMemberService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\service\JwtCacheService;
use mall_base\service\JwtService;
use app\service\sms\SmsScene;
use app\service\sms\SmsService;
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
     * 用户登录(手机号 + 密码)
     */
    public function login(string $account, string $password, string $clientType = 'uniapp'): array
    {
        $user = $this->model()
            ->where('mobile', $account)
            ->where('status', 1)
            ->find();

        if (!$user) {
            throw new BusinessException('账号不存在或已禁用');
        }
        if (!$user->checkPassword($password)) {
            throw new BusinessException('密码错误');
        }

        return $this->finishLogin($user, $account, $clientType);
    }

    /**
     * 用户登录(用户名 + 密码)
     *
     * 用户名可在注册时填,或在个人中心补设。username 列 UNIQUE,可空,
     * 与 mobile/wechat 登录互不冲突
     */
    public function loginByUsername(string $username, string $password, string $clientType = 'uniapp'): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new BusinessException('请输入用户名');
        }

        $user = $this->model()
            ->where('username', $username)
            ->where('status', 1)
            ->find();
        if (!$user) {
            throw new BusinessException('账号不存在或已禁用');
        }
        if (!$user->checkPassword($password)) {
            throw new BusinessException('密码错误');
        }

        return $this->finishLogin($user, $username, $clientType);
    }

    /**
     * 用户登录(手机号 + 短信验证码)
     *
     * 行为:
     *  - 手机号已存在 → 直接登录
     *  - 手机号不存在 → 自动创建账号,register_type=h5(纯网页注册路径),
     *    密码留空(后续个人中心可补设)
     */
    public function loginBySms(string $mobile, string $smsCode, string $clientType = 'uniapp'): array
    {
        $sms = app()->make(SmsService::class);
        $sms->verifyCode($mobile, SmsScene::LOGIN, $smsCode);

        $user = $this->model()
            ->where('mobile', $mobile)
            ->find();

        if (!$user) {
            $user = $this->model();
            $user->save([
                'mobile'        => $mobile,
                'register_type' => RegisterType::H5,
                'register_ip'   => Request::ip(),
                'nickname'      => $this->generateNickname(RegisterType::H5, $mobile),
                'status'        => 1,
            ]);
        } elseif ((int) $user->status !== 1) {
            throw new BusinessException('账号已禁用');
        }

        return $this->finishLogin($user, $mobile, $clientType);
    }

    /**
     * 完成登录:回填登录信息 + 签发 JWT,集中处理避免各登录路径复制粘贴
     *
     * @return array<string, mixed>
     */
    private function finishLogin(User $user, string $account, string $clientType): array
    {
        $user->last_login_time = date('Y-m-d H:i:s');
        $user->last_login_ip   = Request::ip();
        $user->save();

        $jwtService = app()->make(JwtService::class);
        $sid = $this->makeSessionId();
        $token = $jwtService->encode([
            'user_id'       => $user->id,
            'account'       => $account,
            'register_type' => $user->register_type,
            'guard'         => JwtCacheService::GUARD_CLIENT,
            'client_type'   => $this->normalizeClientType($clientType),
            'sid'           => $sid,
        ]);

        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $user->id,
            $jwtService->getRefreshExpire(),
            JwtCacheService::GUARD_CLIENT,
            $sid,
        );

        return $token;
    }

    /**
     * 刷新前台用户 Token，保持同一个登录会话 sid。
     *
     * @return array<string, mixed>
     */
    public function refreshToken(string $refreshToken): array
    {
        $jwtService = app()->make(JwtService::class);

        try {
            $decoded = $jwtService->decode($refreshToken);
            $payload = $decoded->data;
        } catch (\Exception $e) {
            throw new BusinessException('刷新令牌无效或已过期');
        }

        if (($payload->type ?? null) !== 'refresh') {
            throw new BusinessException('刷新令牌类型错误');
        }
        if (($payload->guard ?? null) !== JwtCacheService::GUARD_CLIENT) {
            throw new BusinessException('刷新令牌身份域错误');
        }

        $userId = (int) ($payload->user_id ?? 0);
        $sid = (string) ($payload->sid ?? '');
        if ($userId <= 0 || $sid === '') {
            throw new BusinessException('刷新令牌会话无效');
        }

        $user = $this->model()
            ->where('id', $userId)
            ->where('status', 1)
            ->find();
        if (!$user) {
            throw new BusinessException('用户不存在或已禁用');
        }

        $jwtCacheService = app()->make(JwtCacheService::class);
        if (!$jwtCacheService->verifyRefreshToken(
            $refreshToken,
            $userId,
            JwtCacheService::GUARD_CLIENT,
            $sid
        )) {
            throw new BusinessException('刷新令牌已失效');
        }

        $jwtCacheService->revokeRefreshToken($userId, JwtCacheService::GUARD_CLIENT, $sid);

        $token = $jwtService->encode([
            'user_id'       => $user->id,
            'account'       => (string) ($payload->account ?? ''),
            'register_type' => (string) ($payload->register_type ?? $user->register_type),
            'guard'         => JwtCacheService::GUARD_CLIENT,
            'client_type'   => $this->normalizeClientType((string) ($payload->client_type ?? 'uniapp')),
            'sid'           => $sid,
        ]);

        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $userId,
            $jwtService->getRefreshExpire(),
            JwtCacheService::GUARD_CLIENT,
            $sid,
        );

        return $token;
    }

    /**
     * 获取用户列表
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $query = $this->buildListQuery($where);

        $total = (int) (clone $query)->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 移除敏感信息
        foreach ($list as &$item) {
            unset($item['password']);
        }
        unset($item);
        $list = app()->make(AssetHydrator::class)->hydrateFields($list, [
            'avatar' => 'avatar_full_url',
        ]);

        return compact('total', 'list');
    }

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('mobile|email|nickname|real_name', "%{$where['keyword']}%");
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(!empty($where['register_type']), function ($q) use ($where) {
                $q->where('register_type', $where['register_type']);
            });
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
        $hydrated = app()->make(AssetHydrator::class)->hydrateFields([$info], [
            'avatar' => 'avatar_full_url',
        ]);
        $info = $hydrated[0] ?? $info;

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
        if (array_key_exists('avatar', $data)) {
            $data['avatar'] = app()->make(UploadService::class)
                ->normalizeStoredImagePath((string) $data['avatar']);
        }

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
                'avatar' => $data['avatar'] ?? '',
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

        if (array_key_exists('avatar', $data)) {
            $data['avatar'] = app()->make(UploadService::class)
                ->normalizeStoredImagePath((string) $data['avatar']);
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
    public function logout(int $userId, ?string $sid = null): bool
    {
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->clearUserTokens($userId, JwtCacheService::GUARD_CLIENT, $sid);
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
        $hydrated = app()->make(AssetHydrator::class)->hydrateFields([$info], [
            'avatar' => 'avatar_full_url',
        ]);
        $info = $hydrated[0] ?? $info;

        // 获取用户分组
        $info['groups'] = $this->getUserGroups($userId);

        // 获取用户标签
        $info['tags'] = $this->getUserTags($userId);

        $info['member'] = app()->make(UserMemberService::class)->clientSummary($userId);

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

        if (array_key_exists('avatar', $updateData)) {
            $updateData['avatar'] = app()->make(UploadService::class)
                ->normalizeStoredImagePath((string) $updateData['avatar']);
        }

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

    private function makeSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function normalizeClientType(string $clientType): string
    {
        $clientType = trim($clientType);
        if ($clientType === '') {
            return 'uniapp';
        }

        $clientType = strtolower($clientType);
        $clientType = preg_replace('/[^a-z0-9_:-]/', '', $clientType) ?: 'uniapp';

        return mb_substr($clientType, 0, 32);
    }
}
