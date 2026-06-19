<?php

declare(strict_types=1);

namespace app\service\sms;

/**
 * 短信验证码场景
 *
 * 区分不同业务场景，便于:
 *  - 模板 ID 映射(阿里云每个场景一个模板)
 *  - 频控按场景独立计算
 *  - 验证码 Redis key 按场景分桶
 */
class SmsScene
{
    /** 登录验证码 */
    public const LOGIN = 'login';

    /** 注册验证码 */
    public const REGISTER = 'register';

    /** 找回密码 */
    public const RESET_PASSWORD = 'reset_password';

    /** 绑定/换绑手机号 */
    public const BIND_MOBILE = 'bind_mobile';

    /** 公众号 OAuth 后强制绑定手机号 */
    public const WECHAT_OFFICIAL_BIND = 'wechat_official_bind';

    private const TEXTS = [
        self::LOGIN                => '登录验证码',
        self::REGISTER             => '注册验证码',
        self::RESET_PASSWORD       => '找回密码',
        self::BIND_MOBILE          => '绑定手机号',
        self::WECHAT_OFFICIAL_BIND => '公众号绑定手机号',
    ];

    /**
     * 各场景能向短信模板提供的参数白名单(占位符名称)
     *
     * 当前 5 个场景占位符一致,拆为 per-scene 是为了支持"按场景判断 + 提示"
     * 以及未来不同场景占位符差异化扩展。
     */
    private const SCENE_PARAMS = [
        self::LOGIN                => ['code'],
        self::REGISTER             => ['code'],
        self::RESET_PASSWORD       => ['code'],
        self::BIND_MOBILE          => ['code'],
        self::WECHAT_OFFICIAL_BIND => ['code'],
    ];

    public static function isValid(string $scene): bool
    {
        return array_key_exists($scene, self::TEXTS);
    }

    public static function textOf(string $scene): string
    {
        return self::TEXTS[$scene] ?? '未知场景';
    }

    /**
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_keys(self::TEXTS);
    }

    /**
     * 场景能向短信模板提供的参数白名单(占位符名称)
     *
     *  - code: 验证码本体,由 SmsService 生成 6 位数字码
     *
     * 用于:
     *  - SmsSceneService::bind() 校验模板占位符是否被场景覆盖
     *  - SmsService::resolveDriverForScene() 构造 templateParam
     *
     * 参数说明:
     *  - 传入合法场景:返回该场景的占位符白名单
     *  - 不传或传入未知场景:返回所有场景占位符的并集(去重,向后兼容)
     *
     * 未来扩展 app_name / amount 等占位符,需在 SCENE_PARAMS 对应场景追加
     * + 在 SmsService 注入逻辑里增加分支。
     *
     * @return array<int, string>
     */
    public static function availableParamNames(string $scene = ''): array
    {
        if ($scene !== '' && isset(self::SCENE_PARAMS[$scene])) {
            return self::SCENE_PARAMS[$scene];
        }

        $merged = [];
        foreach (self::SCENE_PARAMS as $params) {
            foreach ($params as $param) {
                $merged[$param] = true;
            }
        }
        return array_keys($merged);
    }
}
