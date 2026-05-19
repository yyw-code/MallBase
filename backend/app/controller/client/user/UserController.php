<?php
declare(strict_types=1);

namespace app\controller\client\user;

use app\service\client\SmsAuthService;
use app\service\client\UserService;
use app\service\client\WechatAppFactory;
use app\service\client\WechatService;
use app\validate\client\user\UserValidate;
use mall_base\base\BaseController;
use app\service\sms\SmsScene;

/**
 * 前台用户控制器
 * @extends BaseController<UserService>
 */
class UserController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UserService::class;

    /**
     * 用户登录(手机号 + 密码)
     */
    public function login()
    {
        $data = $this->request->param(['account', 'password']);

        if (empty($data['account'])) {
            return $this->error('请输入手机号');
        }

        if (empty($data['password'])) {
            return $this->error('请输入密码');
        }

        $result = $this->service()->login($data['account'], $data['password']);
        return $this->success($result, '登录成功');
    }

    /**
     * 用户登录(用户名 + 密码)
     */
    public function loginByUsername()
    {
        $data = $this->request->param(['username', 'password']);

        if (empty($data['username'])) {
            return $this->error('请输入用户名');
        }
        if (empty($data['password'])) {
            return $this->error('请输入密码');
        }

        $result = $this->service()->loginByUsername($data['username'], $data['password']);
        return $this->success($result, '登录成功');
    }

    /**
     * 用户登录(手机号 + 短信验证码)
     */
    public function loginBySms()
    {
        $data = $this->request->param(['mobile', 'code']);

        if (empty($data['mobile'])) {
            return $this->error('请输入手机号');
        }
        if (empty($data['code'])) {
            return $this->error('请输入验证码');
        }

        $result = $this->service()->loginBySms($data['mobile'], $data['code']);
        return $this->success($result, '登录成功');
    }

    /**
     * 发送短信验证码
     *
     * 请求参数:
     *  - mobile: 手机号
     *  - scene:  场景(login/register/reset_password/bind_mobile/wechat_official_bind)
     */
    public function sendSmsCode()
    {
        $mobile = (string) $this->request->param('mobile', '');
        $scene = (string) $this->request->param('scene', SmsScene::LOGIN);

        if ($mobile === '') {
            return $this->error('请输入手机号');
        }

        /** @var SmsAuthService $smsAuth */
        $smsAuth = app(SmsAuthService::class);
        $smsAuth->send($mobile, $scene, $this->request->ip());

        return $this->success(null, '验证码已发送');
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

    /**
     * 微信小程序登录
     */
    public function wechatLogin()
    {
        $code = $this->request->param('code');
        if (empty($code)) {
            return $this->error('code 不能为空');
        }

        /** @var WechatService $wechatService */
        $wechatService = app(WechatService::class);
        $result = $wechatService->miniappLogin((string) $code);
        return $this->success($result, '登录成功');
    }

    /**
     * 微信小程序"手动绑定手机号"(force_mobile=false 场景)
     *
     * 请求参数:bind_token + mobile + code(SMS 验证码,scene=bind_mobile)
     */
    public function bindMobile()
    {
        $bindToken = (string) $this->request->param('bind_token', '');
        $mobile  = (string) $this->request->param('mobile', '');
        $smsCode = (string) $this->request->param('code', '');

        if ($bindToken === '' || $mobile === '' || $smsCode === '') {
            return $this->error('参数不完整');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            return $this->error('手机号格式不正确');
        }

        /** @var WechatService $wechatService */
        $wechatService = app(WechatService::class);
        $result = $wechatService->miniappBindMobileManual(
            $bindToken,
            $mobile,
            $smsCode,
            (string) $this->request->param('nickname', ''),
            (string) $this->request->param('avatar', '')
        );
        return $this->success($result, '绑定成功');
    }

    /**
     * 微信小程序"获取手机号"绑定(force_mobile=true 场景)
     *
     * 请求参数:bind_token + phone_code(button open-type=getPhoneNumber 触发后前端拿到的 code)
     */
    public function bindMobileByPhoneCode()
    {
        $bindToken = (string) $this->request->param('bind_token', '');
        $phoneCode = (string) $this->request->param('phone_code', '');

        if ($bindToken === '' || $phoneCode === '') {
            return $this->error('参数不完整');
        }

        /** @var WechatService $wechatService */
        $wechatService = app(WechatService::class);
        $result = $wechatService->miniappBindMobileByPhoneCode(
            $bindToken,
            $phoneCode,
            (string) $this->request->param('nickname', ''),
            (string) $this->request->param('avatar', '')
        );
        return $this->success($result, '绑定成功');
    }

    /**
     * 微信小程序"头像昵称"绑定(force_userinfo=true 场景)
     */
    public function bindUserInfo()
    {
        $bindToken = (string) $this->request->param('bind_token', '');
        $nickname = (string) $this->request->param('nickname', '');
        $avatar = (string) $this->request->param('avatar', '');

        if ($bindToken === '') {
            return $this->error('参数不完整');
        }

        /** @var WechatService $wechatService */
        $wechatService = app(WechatService::class);
        $result = $wechatService->miniappBindUserInfo($bindToken, $nickname, $avatar);
        return $this->success($result, '绑定成功');
    }

    /**
     * 获取微信公众号 OAuth 授权地址
     */
    public function wechatOfficialOauthUrl()
    {
        $redirectUri = (string) $this->request->param('redirect_uri', '');
        $state = (string) $this->request->param('state', 'login');

        if ($redirectUri === '') {
            return $this->error('redirect_uri 不能为空');
        }
        if (!preg_match('#^https?://#i', $redirectUri)) {
            return $this->error('redirect_uri 必须是完整的 http(s) 地址');
        }

        /** @var WechatAppFactory $factory */
        $factory = app(WechatAppFactory::class);

        return $this->success([
            'url' => $factory->officialOauthUrl($redirectUri, $state),
        ], '获取成功');
    }

    /**
     * 微信公众号 OAuth 登录
     */
    public function wechatOfficialLogin()
    {
        $code = (string) $this->request->param('code', '');
        if ($code === '') {
            return $this->error('code 不能为空');
        }

        /** @var WechatService $wechatService */
        $wechatService = app(WechatService::class);
        $result = $wechatService->officialLogin($code);
        return $this->success($result, '登录成功');
    }

    /**
     * 微信公众号 OAuth 后绑定手机号(走短信验证码)
     */
    public function wechatOfficialBindMobile()
    {
        $bindToken = (string) $this->request->param('bind_token', '');
        $mobile  = (string) $this->request->param('mobile', '');
        $smsCode = (string) $this->request->param('code', '');

        if ($bindToken === '' || $mobile === '' || $smsCode === '') {
            return $this->error('参数不完整');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            return $this->error('手机号格式不正确');
        }

        /** @var WechatService $wechatService */
        $wechatService = app(WechatService::class);
        $result = $wechatService->officialBindMobile($bindToken, $mobile, $smsCode);
        return $this->success($result, '绑定成功');
    }
}
