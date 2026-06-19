<?php

declare(strict_types=1);

namespace app\service\client;

use app\model\user\User;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use app\service\sms\SmsScene;
use app\service\sms\SmsService;

/**
 * 客户端短信验证码业务编排
 *
 * 职责:
 *  - 校验场景与业务前置条件(例如登录场景未限制注册状态;
 *    重置密码场景必须手机号已存在)
 *  - 委托 SmsService 完成生成、频控、发送、缓存、校验
 *
 * 不在 UserService 里直接放,是因为 SMS 流程跨越多个登录路径
 * (mobile-login / wechat-bind / reset-password),独立服务更内聚
 *
 * @extends BaseService<User>
 */
class SmsAuthService extends BaseService
{
    protected string $modelClass = User::class;

    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * 发送验证码
     *
     * @param string $mobile  手机号
     * @param string $scene   场景,需是 SmsScene 常量值
     * @param string $ip      调用方 IP,用于频控
     * @return array{code_ttl: int}
     */
    public function send(string $mobile, string $scene, string $ip = ''): array
    {
        if (!SmsScene::isValid($scene)) {
            throw new BusinessException('不支持的短信场景');
        }

        // 业务前置:不同场景对手机号注册状态的要求不同
        $this->assertSceneBusinessRule($mobile, $scene);

        $this->sms->sendCode($mobile, $scene, $ip);

        return [
            'code_ttl' => $this->sms->codeTtl(),
        ];
    }

    private function assertSceneBusinessRule(string $mobile, string $scene): void
    {
        $exists = $this->model()->where('mobile', $mobile)->find();

        switch ($scene) {
            case SmsScene::REGISTER:
                if ($exists !== null) {
                    throw new BusinessException('该手机号已注册');
                }
                break;

            case SmsScene::RESET_PASSWORD:
                if ($exists === null) {
                    throw new BusinessException('手机号未注册');
                }
                break;

            // LOGIN / BIND_MOBILE / WECHAT_OFFICIAL_BIND:不限制注册状态
            default:
                break;
        }
    }
}
