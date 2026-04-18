<?php
declare(strict_types=1);

namespace app\service\client;

use app\model\user\User;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\log\Logger;
use mall_base\service\JwtCacheService;
use mall_base\service\JwtService;
use think\facade\Request;

/**
 * 微信小程序服务
 * @extends BaseService<User>
 */
class WechatService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = User::class;

    /**
     * 微信小程序登录
     *
     * @param string $code 微信小程序登录凭证
     * @return array 登录结果
     */
    public function login(string $code): array
    {
        // 1. 通过 code 换取 openid 和 session_key
        $wxData = $this->code2Session($code);

        // 2. 查找用户
        $user = $this->model()
            ->where('wx_openid', $wxData['openid'])
            ->where('status', 1)
            ->find();

        if (!$user) {
            // 新用户，返回信息提示前端需要绑定手机号
            Logger::instance()->info('微信新用户', [
                'openid' => $wxData['openid'],
                'unionid' => $wxData['unionid'] ?? '',
            ]);

            return [
                'need_mobile' => true,
                'openid' => $wxData['openid'],
                'unionid' => $wxData['unionid'] ?? null,
                'session_key' => $wxData['session_key'],
            ];
        }

        // 3. 更新用户登录信息和 session_key
        $user->save([
            'session_key' => $wxData['session_key'],
            'wx_unionid' => $wxData['unionid'] ?? $user->wx_unionid,
            'last_login_time' => date('Y-m-d H:i:s'),
            'last_login_ip' => Request::ip(),
        ]);

        // 4. 生成 JWT Token
        $jwtService = app()->make(JwtService::class);
        $token = $jwtService->encode([
            'user_id' => $user->id,
            'account' => $user->wx_openid,
            'register_type' => 'wechat',
        ]);

        // 5. 存储 refresh_token 到 Redis
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $user->id,
            $jwtService->getRefreshExpire()
        );

        Logger::instance()->info('微信登录成功', [
            'user_id' => $user->id,
            'openid' => $wxData['openid'],
        ]);

        return $token;
    }

    /**
     * 绑定手机号（微信小程序新用户）
     *
     * @param string $openid 微信 openid
     * @param string $mobile 手机号
     * @param string|null $nickname 昵称（可选）
     * @param string|null $avatar 头像（可选）
     * @return array 登录 token
     */
    public function bindMobile(
        string $openid,
        string $mobile,
        ?string $nickname = null,
        ?string $avatar = null
    ): array {
        // 1. 查找临时用户（通过 openid 创建的用户）
        $user = $this->model()
            ->where('wx_openid', $openid)
            ->find();

        if (!$user) {
            throw new BusinessException('用户不存在，请先登录');
        }

        // 2. 检查手机号是否已被其他用户使用
        $exists = $this->model()
            ->where('mobile', $mobile)
            ->where('id', '<>', $user->id)
            ->find();

        if ($exists) {
            throw new BusinessException('该手机号已被其他用户使用');
        }

        // 3. 更新用户信息
        $updateData = [
            'mobile' => $mobile,
            'nickname' => $nickname ?: $this->generateNickname('mobile', $mobile),
            'register_type' => 'wechat',
        ];

        if ($avatar) {
            $updateData['avatar'] = $avatar;
        }

        $user->save($updateData);

        // 4. 生成 JWT Token
        $jwtService = app()->make(JwtService::class);
        $token = $jwtService->encode([
            'user_id' => $user->id,
            'account' => $mobile,
            'register_type' => 'wechat',
        ]);

        // 5. 存储 refresh_token 到 Redis
        $jwtCacheService = app()->make(JwtCacheService::class);
        $jwtCacheService->storeRefreshToken(
            $token['refresh_token'],
            $user->id,
            $jwtService->getRefreshExpire()
        );

        Logger::instance()->info('微信用户绑定手机号', [
            'user_id' => $user->id,
            'mobile' => $mobile,
            'openid' => $openid,
        ]);

        return $token;
    }

    /**
     * 解密手机号（微信小程序）
     *
     * @param string $sessionKey 会话密钥
     * @param string $encryptedData 加密数据
     * @param string $iv 加密向量
     * @return string 解密后的手机号
     */
    public function decryptPhoneNumber(
        string $sessionKey,
        string $encryptedData,
        string $iv
    ): string {
        // 检查 OpenSSL 扩展
        if (!function_exists('openssl_decrypt')) {
            throw new BusinessException('系统不支持 OpenSSL 解密');
        }

        // Base64 解码
        $encryptedData = base64_decode($encryptedData);
        $iv = base64_decode($iv);
        $sessionKey = base64_decode($sessionKey);

        // AES-128-CBC 解密
        $decrypted = openssl_decrypt(
            $encryptedData,
            'AES-128-CBC',
            $sessionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new BusinessException('手机号解密失败');
        }

        // 解析 JSON
        $data = json_decode($decrypted, true);

        if (!isset($data['phoneNumber'])) {
            throw new BusinessException('手机号解密失败：数据格式错误');
        }

        return $data['phoneNumber'];
    }

    /**
     * 通过 code 换取 openid 和 session_key
     *
     * @param string $code 微信小程序登录凭证
     * @return array 包含 openid、session_key、unionid
     */
    private function code2Session(string $code): array
    {
        $appId = config('wechat.mini_program.app_id');
        $appSecret = config('wechat.mini_program.app_secret');

        if (empty($appId) || empty($appSecret)) {
            throw new BusinessException('微信小程序配置未设置');
        }

        $url = 'https://api.weixin.qq.com/sns/jscode2session';
        $params = [
            'appid' => $appId,
            'secret' => $appSecret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ];

        try {
            // 使用 Guzzle HTTP 客户端
            $client = new \GuzzleHttp\Client([
                'timeout' => 10,
            ]);

            $response = $client->get($url, [
                'query' => $params,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (isset($data['errcode'])) {
                Logger::instance()->error('微信 code2Session 失败', [
                    'errcode' => $data['errcode'],
                    'errmsg' => $data['errmsg'],
                ]);
                throw new BusinessException('微信登录失败: ' . $data['errmsg']);
            }

            if (!isset($data['openid']) || !isset($data['session_key'])) {
                throw new BusinessException('微信登录失败：返回数据异常');
            }

            return [
                'openid' => $data['openid'],
                'session_key' => $data['session_key'],
                'unionid' => $data['unionid'] ?? null,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Logger::instance()->error('微信 API 请求失败', [
                'message' => $e->getMessage(),
            ]);
            throw new BusinessException('微信服务暂时不可用，请稍后再试');
        }
    }

    /**
     * 生成默认昵称
     *
     * @param string $type 注册类型
     * @param string $account 账号
     * @return string 昵称
     */
    protected function generateNickname(string $type, string $account): string
    {
        if ($type === 'mobile') {
            // 手机号中间4位隐藏
            return '用户' . substr($account, 0, 3) . '****' . substr($account, -4);
        }

        return '微信用户' . substr($account, 0, 8);
    }
}
