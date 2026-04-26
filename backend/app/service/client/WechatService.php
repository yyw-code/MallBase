<?php
declare(strict_types=1);

namespace app\service\client;

use app\common\enum\RegisterType;
use app\model\user\User;
use EasyWeChat\Kernel\Exceptions\HttpException;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\log\Logger;
use mall_base\service\JwtCacheService;
use mall_base\service\JwtService;
use app\service\sms\SmsScene;
use app\service\sms\SmsService;
use think\facade\Request;

/**
 * 微信登录服务(小程序 + 公众号 + H5 入微信内打开)
 *
 * 接入策略:
 *  - 通过 {@see WechatAppFactory} 拿 EasyWeChat Application,每次请求 new(无状态)
 *  - 配置全部来自 mb_setting(后台改完即时生效),不读 .env
 *
 * 账号匹配优先级(authenticateOrCreate 内部统一):
 *  1) unionid(且开放平台已绑定)
 *  2) mobile(本次拿到的真实手机号)
 *  3) 当前来源对应的 openid 列(wx_miniapp_openid / wx_official_openid)
 *  4) 都没命中 → 新建用户,register_type 记录首次来源
 *
 * 跨端合并语义:
 *  - 老用户首次出现 unionid → 写入 wx_unionid 列
 *  - 老用户首次出现某来源 openid → 写入对应列
 *  - 老用户已绑过 mobile → 不会被本次微信带的 mobile 覆盖
 *
 * @extends BaseService<User>
 */
class WechatService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = User::class;

    public function __construct(
        private readonly WechatAppFactory $factory,
        private readonly SmsService $sms,
    ) {
    }

    // ============================================================
    // 小程序
    // ============================================================

    /**
     * 微信小程序登录
     *
     * 流程:
     *  1) code → openid/session_key/unionid(via EasyWeChat)
     *  2) authenticateOrCreate 匹配/创建用户
     *  3) 若开关 wechat_mini_force_mobile 开启且用户尚无 mobile → 返回 need_mobile 让前端
     *     调 miniappBindMobileByPhoneCode($phoneCode) 自动填手机号(走 getuserphonenumber)
     *  4) 否则签发 JWT
     *
     * @return array<string, mixed> 已登录返回 token;待绑定手机号返回 need_mobile=true
     */
    public function miniappLogin(string $code): array
    {
        $session = $this->miniappCodeToSession($code);
        $openid = (string) ($session['openid'] ?? '');
        if ($openid === '') {
            throw new BusinessException('微信登录失败,未获取到 openid');
        }
        $unionid = (string) ($session['unionid'] ?? '');
        $sessionKey = (string) ($session['session_key'] ?? '');

        $user = $this->authenticateOrCreate(
            source: RegisterType::WECHAT_MINIAPP,
            openid: $openid,
            unionid: $unionid,
            mobile: '',
        );

        // session_key 仅小程序需要,落库以便后续 getPhoneNumber/decryptSession
        if ($sessionKey !== '' && $sessionKey !== (string) $user->session_key) {
            $user->save(['session_key' => $sessionKey]);
        }

        if (in_array((string) getSystemSetting('wechat_mini_force_mobile', '0'), ['1', 'true', 'on', 'yes'], true) && (string) $user->mobile === '') {
            return [
                'need_mobile'        => true,
                'openid'             => $openid,
                'unionid'            => $unionid !== '' ? $unionid : null,
                'session_key'        => $sessionKey,
                'force_phone_number' => true,
            ];
        }

        return $this->issueToken($user, RegisterType::WECHAT_MINIAPP, $openid);
    }

    /**
     * 小程序"手动绑定手机号"(force_mobile=false 场景的回退路径)
     *
     * 用户首次小程序登录后,若开关未开则前端继续使用现有"手机号 + 短信验证码"
     * 表单完成手机号绑定。进入此方法时:
     *  - openid 是上一次 miniappLogin 返回给前端的 openid
     *  - mobile 是用户输入的手机号
     *  - smsCode 是 SmsScene::BIND_MOBILE 发出去的验证码
     */
    public function miniappBindMobileManual(string $openid, string $mobile, string $smsCode): array
    {
        $this->sms->verifyCode($mobile, SmsScene::BIND_MOBILE, $smsCode);

        $user = $this->model()->where('wx_miniapp_openid', $openid)->find();
        if ($user === null) {
            throw new BusinessException('用户登录态已过期,请重新登录');
        }

        $existingByMobile = $this->model()
            ->where('mobile', $mobile)
            ->where('id', '<>', $user->id)
            ->find();
        if ($existingByMobile !== null) {
            $this->mergeWechatBindingsInto($existingByMobile, $user, RegisterType::WECHAT_MINIAPP);
            $user->delete();
            $user = $existingByMobile;
        } else {
            $user->save(['mobile' => $mobile]);
        }

        return $this->issueToken($user, RegisterType::WECHAT_MINIAPP, $openid);
    }

    /**
     * 小程序"getPhoneNumber 兑换"绑定手机号
     *
     * 入参 $phoneCode 是 button open-type=getPhoneNumber 触发后前端拿到的 code
     * 后端调 wxa.business.getuserphonenumber 用 access_token 兑换真实手机号,
     * 然后按 mobile 完成"跨端账号合并 + 落库"
     */
    public function miniappBindMobileByPhoneCode(string $openid, string $phoneCode): array
    {
        $mobile = $this->miniappFetchPhoneNumber($phoneCode);
        if ($mobile === '') {
            throw new BusinessException('获取手机号失败,请重试');
        }

        $user = $this->model()->where('wx_miniapp_openid', $openid)->find();
        if ($user === null) {
            throw new BusinessException('用户登录态已过期,请重新登录');
        }

        // 若该手机号已属于另一个用户(老 H5 用户) → 合并:把 openid/unionid 写到老用户上,
        // 删除本次创建的临时 user 行(register_type=wechat_miniapp 且 mobile=null 的占位用户)
        $existingByMobile = $this->model()
            ->where('mobile', $mobile)
            ->where('id', '<>', $user->id)
            ->find();
        if ($existingByMobile !== null) {
            $this->mergeWechatBindingsInto($existingByMobile, $user, RegisterType::WECHAT_MINIAPP);
            $user->delete();
            $user = $existingByMobile;
        } else {
            $user->save(['mobile' => $mobile]);
        }

        return $this->issueToken($user, RegisterType::WECHAT_MINIAPP, $openid);
    }

    // ============================================================
    // 公众号(网页 OAuth,微信内打开)
    // ============================================================

    /**
     * 公众号 OAuth 登录
     *
     * 流程:
     *  1) code → openid + 可选 unionid + 可选 nickname/headimg(via Socialite OAuth)
     *  2) authenticateOrCreate 匹配/创建用户
     *  3) 若开关 wechat_offi_force_mobile_bind 开启且用户尚无 mobile → 返回 need_mobile,
     *     前端再调 officialBindMobile($openid, $mobile, $smsCode) 完成短信绑定
     *  4) 否则签发 JWT
     */
    public function officialLogin(string $code): array
    {
        $oauthUser = $this->officialUserFromCode($code);
        $openid = (string) ($oauthUser['openid'] ?? '');
        if ($openid === '') {
            throw new BusinessException('公众号登录失败,未获取到 openid');
        }
        $unionid = (string) ($oauthUser['unionid'] ?? '');
        $nickname = (string) ($oauthUser['nickname'] ?? '');
        $avatar = (string) ($oauthUser['avatar'] ?? '');

        $user = $this->authenticateOrCreate(
            source: RegisterType::WECHAT_OFFICIAL,
            openid: $openid,
            unionid: $unionid,
            mobile: '',
        );

        // 首次拿到 nickname/avatar 时回填(尊重用户后续手改,不覆盖已存在的值)
        $updates = [];
        if ($nickname !== '' && (string) $user->nickname === '') {
            $updates['nickname'] = mb_substr($nickname, 0, 50);
        }
        if ($avatar !== '' && (string) $user->avatar === '') {
            $updates['avatar'] = $avatar;
        }
        if ($updates !== []) {
            $user->save($updates);
        }

        if (in_array((string) getSystemSetting('wechat_offi_force_mobile_bind', '0'), ['1', 'true', 'on', 'yes'], true) && (string) $user->mobile === '') {
            return [
                'need_mobile' => true,
                'openid'      => $openid,
                'unionid'     => $unionid !== '' ? $unionid : null,
                'sms_required'=> true,
            ];
        }

        return $this->issueToken($user, RegisterType::WECHAT_OFFICIAL, $openid);
    }

    /**
     * 公众号场景的"短信绑定手机号"
     *
     * 公众号 OAuth 拿不到手机号,必须用短信验证码绑定。
     * SmsService 会校验 60s 间隔/24h 上限/IP 限制,业务无需自己处理频控
     */
    public function officialBindMobile(string $openid, string $mobile, string $smsCode): array
    {
        $this->sms->verifyCode($mobile, SmsScene::WECHAT_OFFICIAL_BIND, $smsCode);

        $user = $this->model()->where('wx_official_openid', $openid)->find();
        if ($user === null) {
            throw new BusinessException('用户登录态已过期,请重新登录');
        }

        $existingByMobile = $this->model()
            ->where('mobile', $mobile)
            ->where('id', '<>', $user->id)
            ->find();
        if ($existingByMobile !== null) {
            $this->mergeWechatBindingsInto($existingByMobile, $user, RegisterType::WECHAT_OFFICIAL);
            $user->delete();
            $user = $existingByMobile;
        } else {
            $user->save(['mobile' => $mobile]);
        }

        return $this->issueToken($user, RegisterType::WECHAT_OFFICIAL, $openid);
    }

    // ============================================================
    // 内部:匹配 / 合并 / 签发
    // ============================================================

    /**
     * 匹配老用户或建立新用户
     *
     * @param string $source  RegisterType::WECHAT_MINIAPP 或 RegisterType::WECHAT_OFFICIAL
     * @param string $mobile  本次微信渠道拿到的真实手机号(空表示未取得)
     */
    private function authenticateOrCreate(
        string $source,
        string $openid,
        string $unionid,
        string $mobile,
    ): User {
        $openidColumn = $this->openidColumnOf($source);
        $trustUnionid = $unionid !== '' && $this->factory->trustUnionid();

        $user = null;

        // 1) unionid 优先(必须开放平台已绑定)
        if ($trustUnionid) {
            $user = $this->model()->where('wx_unionid', $unionid)->find();
        }

        // 2) mobile
        if ($user === null && $mobile !== '') {
            $user = $this->model()->where('mobile', $mobile)->find();
        }

        // 3) 来源对应的 openid
        if ($user === null) {
            $user = $this->model()->where($openidColumn, $openid)->find();
        }

        // 4) 新建
        if ($user === null) {
            $data = [
                $openidColumn   => $openid,
                'register_type' => $source,
                'register_ip'   => Request::ip(),
                'status'        => 1,
                'nickname'      => '',
            ];
            if ($unionid !== '') {
                $data['wx_unionid'] = $unionid;
            }
            if ($mobile !== '') {
                $data['mobile'] = $mobile;
            }
            $user = $this->model();
            $user->save($data);
            return $user;
        }

        // 命中老用户:补齐空字段
        $updates = [];
        if ($unionid !== '' && (string) $user->wx_unionid === '') {
            $updates['wx_unionid'] = $unionid;
        }
        if ((string) $user->{$openidColumn} === '') {
            $updates[$openidColumn] = $openid;
        }
        if ($mobile !== '' && (string) $user->mobile === '') {
            $updates['mobile'] = $mobile;
        }
        if ($updates !== []) {
            $user->save($updates);
        }

        return $user;
    }

    /**
     * 把 $from 用户上的微信绑定字段并入 $to(目标 = 通过手机号匹配到的老用户)
     */
    private function mergeWechatBindingsInto(User $to, User $from, string $source): void
    {
        $openidColumn = $this->openidColumnOf($source);
        $updates = [];

        if ((string) $to->{$openidColumn} === '' && (string) $from->{$openidColumn} !== '') {
            $updates[$openidColumn] = $from->{$openidColumn};
        }
        if ((string) $to->wx_unionid === '' && (string) $from->wx_unionid !== '') {
            $updates['wx_unionid'] = $from->wx_unionid;
        }
        if ($source === RegisterType::WECHAT_MINIAPP
            && (string) $to->session_key === ''
            && (string) $from->session_key !== ''
        ) {
            $updates['session_key'] = $from->session_key;
        }

        if ($updates !== []) {
            $to->save($updates);
        }
    }

    private function openidColumnOf(string $source): string
    {
        return match ($source) {
            RegisterType::WECHAT_MINIAPP  => 'wx_miniapp_openid',
            RegisterType::WECHAT_OFFICIAL => 'wx_official_openid',
            default => throw new BusinessException('不支持的微信来源'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function issueToken(User $user, string $registerType, string $account): array
    {
        $user->save([
            'last_login_time' => date('Y-m-d H:i:s'),
            'last_login_ip'   => Request::ip(),
        ]);

        $jwtService = app()->make(JwtService::class);
        $token = $jwtService->encode([
            'user_id'       => $user->id,
            'account'       => $account,
            'register_type' => $registerType,
        ]);

        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $user->id,
            $jwtService->getRefreshExpire(),
        );

        return $token;
    }

    // ============================================================
    // 适配器:封装 EasyWeChat 调用,便于单元测试 mock
    // 子类(测试桩)可重写这三个 protected 方法
    // ============================================================

    /**
     * @return array{openid?:string, session_key?:string, unionid?:string}
     */
    protected function miniappCodeToSession(string $code): array
    {
        try {
            return $this->factory->miniApp()->getUtils()->codeToSession($code);
        } catch (HttpException $e) {
            Logger::instance()->error('小程序 codeToSession 失败', ['error' => $e->getMessage()]);
            throw new BusinessException($this->wechatApiErrorMessage($e->getMessage(), '微信登录失败,请稍后再试'));
        }
    }

    /**
     * 调 getuserphonenumber 兑换手机号
     */
    protected function miniappFetchPhoneNumber(string $code): string
    {
        try {
            $resp = $this->factory->miniApp()->getUtils()->getPhoneNumber($code);
            return (string) ($resp['phone_info']['purePhoneNumber'] ?? '');
        } catch (HttpException $e) {
            Logger::instance()->error('小程序 getPhoneNumber 失败', ['error' => $e->getMessage()]);
            throw new BusinessException($this->wechatApiErrorMessage($e->getMessage(), '获取手机号失败,请稍后再试'));
        }
    }

    private function wechatApiErrorMessage(string $message, string $fallback): string
    {
        if (!preg_match('/\{.*\}/', $message, $matches)) {
            return $fallback;
        }

        $payload = json_decode($matches[0], true);
        if (!is_array($payload)) {
            return $fallback;
        }

        $errcode = isset($payload['errcode']) ? (int) $payload['errcode'] : 0;
        if ($errcode === 0) {
            return $fallback;
        }

        $text = match ($errcode) {
            -1 => '微信服务繁忙,请稍后重试',
            40013 => '小程序 AppID 配置不正确或与当前小程序不一致',
            40029, 40163 => '登录 code 无效或已过期,请重新打开小程序后重试',
            40125 => '小程序 AppSecret 配置不正确',
            41002 => '小程序 AppID 配置缺失',
            41004 => '小程序 AppSecret 配置缺失',
            45011 => '微信接口调用过于频繁,请稍后重试',
            default => $fallback,
        };

        return sprintf('%s(errcode:%d)', $text, $errcode);
    }

    /**
     * @return array{openid?:string, unionid?:string, nickname?:string, avatar?:string}
     */
    protected function officialUserFromCode(string $code): array
    {
        try {
            $oauth = $this->factory->officialAccount()->getOAuth();
            $oauth = $oauth->scopes([$this->factory->officialOauthScope()]);
            $user = $oauth->userFromCode($code);
            return [
                'openid'   => (string) ($user->getId() ?? ''),
                'unionid'  => (string) ($user->getRaw()['unionid'] ?? ''),
                'nickname' => (string) ($user->getNickname() ?? ''),
                'avatar'   => (string) ($user->getAvatar() ?? ''),
            ];
        } catch (\Throwable $e) {
            Logger::instance()->error('公众号 OAuth 失败', ['error' => $e->getMessage()]);
            throw new BusinessException('公众号登录失败,请稍后再试');
        }
    }
}
