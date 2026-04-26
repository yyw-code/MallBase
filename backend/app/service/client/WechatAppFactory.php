<?php

declare(strict_types=1);

namespace app\service\client;

use EasyWeChat\MiniApp\Application as MiniAppApplication;
use EasyWeChat\OfficialAccount\Application as OfficialAccountApplication;
use mall_base\exception\BusinessException;

/**
 * EasyWeChat Application 工厂
 *
 * 设计要点:
 *  - 每次调用返回一个 **新实例**,严格遵守 thinkPHP/service-stateless-swoole 规范,
 *    避免 Swoole 常驻进程下 SDK 内部状态(如 token、http client)跨请求污染
 *  - 配置来源:mb_setting(数据库),通过全局 getSystemSetting() 读取并经 Redis 缓存,
 *    后台改完即时生效;不读 .env / config/wechat.php
 *  - 缺少 AppID/AppSecret 时立刻抛 BusinessException,不让 SDK 用空字符串去发请求
 */
class WechatAppFactory
{

    /**
     * 构造小程序 Application
     *
     * @throws BusinessException 缺少 AppID/AppSecret
     */
    public function miniApp(): MiniAppApplication
    {
        $appId = (string) getSystemSetting('wechat_mini_appid', '');
        $appSecret = (string) getSystemSetting('wechat_mini_secret', '');
        $this->assertCredentials($appId, $appSecret, '微信小程序');

        return new MiniAppApplication([
            'app_id' => $appId,
            'secret' => $appSecret,
        ]);
    }

    /**
     * 构造公众号 Application
     *
     * @throws BusinessException 缺少 AppID/AppSecret
     */
    public function officialAccount(): OfficialAccountApplication
    {
        $appId = (string) getSystemSetting('wechat_offi_appid', '');
        $appSecret = (string) getSystemSetting('wechat_offi_secret', '');
        $this->assertCredentials($appId, $appSecret, '微信公众号');

        $config = [
            'app_id' => $appId,
            'secret' => $appSecret,
        ];

        $token = (string) getSystemSetting('wechat_offi_token', '');
        if ($token !== '') {
            $config['token'] = $token;
        }
        $aesKey = (string) getSystemSetting('wechat_offi_aes_key', '');
        if ($aesKey !== '') {
            $config['aes_key'] = $aesKey;
        }

        return new OfficialAccountApplication($config);
    }

    /**
     * 公众号 OAuth 强制头像昵称模式开关
     *
     * 关闭时使用 snsapi_base 仅取 openid;开启时使用 snsapi_userinfo 拉头像/昵称
     */
    public function officialOauthScope(): string
    {
        return in_array((string) getSystemSetting('wechat_offi_force_userinfo', '0'), ['1', 'true', 'on', 'yes'], true)
            ? 'snsapi_userinfo'
            : 'snsapi_base';
    }

    /**
     * 是否信任 unionid 进行跨端账号合并
     *
     * 仅当后台开关 wechat_open_bound = true 时为 true。
     * 未绑定开放平台主体时 unionid 可能各 AppID 独立,合并会出错
     */
    public function trustUnionid(): bool
    {
        return in_array((string) getSystemSetting('wechat_open_bound', '0'), ['1', 'true', 'on', 'yes'], true);
    }

    private function assertCredentials(string $appId, string $secret, string $label): void
    {
        if ($appId === '' || $secret === '') {
            throw new BusinessException(sprintf('%s 配置未完成,请在后台「设置 → 微信配置」中填写 AppID 与 AppSecret', $label));
        }
    }
}
