<?php

declare(strict_types=1);

namespace app\service\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSceneBinding;
use app\model\sms\SmsSign;
use app\model\sms\SmsTemplate;
use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;
use mall_base\drivers\sms\BaseSmsDriver;
use mall_base\exception\SmsException;

/**
 * 短信验证码服务(业务入口)
 *
 * 职责:
 *  - 生成 6 位数字验证码
 *  - 走频控
 *  - 调驱动发送
 *  - 验证码存 SmsCache(独立于驱动,方便 mock 模式联调)
 *  - 提供 verifyCode(mobile, scene, code) 业务校验接口
 *
 * 驱动解析:
 *  - 构造期注入 driver -> 直接使用(用于单元测试 / mock 模式)
 *  - 构造期 driver=null -> 按 SmsSceneBinding 动态解析服务商、模板、签名
 *
 * 兼容性硬约束:
 *  - sendCode 方法签名保持不变,uniapp 调用方零修改
 *
 * @extends BaseService<SmsSceneBinding>
 */
class SmsService extends BaseService
{
    private const CODE_KEY_PREFIX = 'sms:code:';

    protected string $modelClass = SmsSceneBinding::class;

    public function __construct(
        private readonly ?BaseSmsDriver $driver,
        private readonly SmsRateLimiter $rateLimiter,
        private readonly SmsCache $cache,
        private readonly int $codeTtl = 300,
    ) {
    }

    /**
     * 发送验证码
     *
     * @throws SmsException 频控命中或渠道发送失败
     */
    public function sendCode(string $mobile, string $scene, string $ip = '', array $extra = []): void
    {
        $this->assertScene($scene);
        $this->assertMobile($mobile);

        $this->rateLimiter->assertCanSend($mobile, $ip);

        $code = $this->generateCode();

        [$driver, $sendExtra] = $this->resolveDriverForScene($scene, $code, $extra);

        if (!$driver->sendCode($mobile, $scene, $code, $sendExtra)) {
            throw new SmsException($driver->getError() ?: '短信发送失败');
        }

        $this->rateLimiter->record($mobile, $ip);

        // 平台侧校验的驱动(PNVS)自管验证码生命周期,不需要本地缓存
        if (!$driver->supportsCodeVerification()) {
            $this->cache->set($this->codeKey($mobile, $scene), $code, $this->codeTtl);
        }
    }

    /**
     * 校验验证码
     *
     * - 平台侧校验驱动(PNVS):委托驱动调用平台 API 校验
     * - 本地校验驱动(阿里云短信/Mock):从缓存比对,校验后删除防重放
     *
     * @throws SmsException 验证码不存在/过期/不匹配
     */
    public function verifyCode(string $mobile, string $scene, string $code): void
    {
        $this->assertScene($scene);
        $this->assertMobile($mobile);

        // 尝试解析驱动:优先走平台侧校验
        $driver = $this->resolveDriverForVerify($scene);
        if ($driver !== null && $driver->supportsCodeVerification()) {
            if (!$driver->verifyCode($mobile, trim($code))) {
                throw new SmsException($driver->getError() ?: '验证码错误');
            }
            return;
        }

        // 本地缓存校验
        $key = $this->codeKey($mobile, $scene);
        $stored = $this->cache->get($key);
        if (empty($stored)) {
            throw new SmsException('验证码已过期,请重新获取');
        }
        if ((string) $stored !== trim($code)) {
            throw new SmsException('验证码错误');
        }

        $this->cache->delete($key);
    }

    /**
     * 仅 mock 驱动下使用:取出当前验证码用于自动化测试
     *
     * @internal 不要在生产代码中调用
     */
    public function peekCodeForTesting(string $mobile, string $scene): ?string
    {
        $value = $this->cache->get($this->codeKey($mobile, $scene));
        return $value === null ? null : (string) $value;
    }

    /**
     * 解析当前场景应使用的驱动与发送参数
     *
     * @return array{0: BaseSmsDriver, 1: array<string, mixed>}
     */
    private function resolveDriverForScene(string $scene, string $code, array $extra): array
    {
        // 显式注入的驱动优先(测试 / mock 模式)
        if ($this->driver !== null) {
            return [$this->driver, $extra];
        }

        $binding = $this->model()
            ->where('scene_code', $scene)
            ->where('status', 1)
            ->find();
        if ($binding === null) {
            throw new SmsException("场景 [{$scene}] 尚未绑定短信模板,请在后台短信配置中完成绑定");
        }

        $provider = $this->model(SmsProvider::class)->find($binding->provider_id);
        if ($provider === null || (int) $provider->status !== 1) {
            throw new SmsException("场景 [{$scene}] 绑定的服务商不可用,请检查启用状态");
        }

        $driver = DriverManager::create('sms', (string) $provider->driver, [
            'access_key_id' => (string) $provider->access_key_id,
            'access_key_secret' => SmsSecret::decrypt((string) $provider->access_key_secret),
            'region' => (string) $provider->region,
            'scheme_name' => (string) ($provider->scheme_name ?? ''),
        ]);

        // PNVS 等平台侧校验驱动:接受 PASSED 或 LOCAL_ONLY 状态的签名/模板
        // (PNVS 签名/模板由阿里云预置,本地登记后即为 LOCAL_ONLY,不会进入 PASSED 状态)
        if ($driver->supportsCodeVerification()) {
            $allowedStatuses = [SmsSign::AUDIT_PASSED, SmsSign::AUDIT_LOCAL_ONLY];
            $pnvsTemplate = null;
            if (!empty($binding->sign_id)) {
                $sign = $this->model(SmsSign::class)->find($binding->sign_id);
                if ($sign !== null && in_array($sign->audit_status, $allowedStatuses, true)) {
                    $extra['sign_name'] = $sign->sign_name;
                }
            }
            if (!empty($binding->template_id)) {
                $template = $this->model(SmsTemplate::class)->find($binding->template_id);
                if ($template !== null && in_array($template->audit_status, $allowedStatuses, true)) {
                    $extra['template_code'] = $template->template_code;
                    $pnvsTemplate = $template;
                }
            }
            $extra['template_param'] = $this->buildTemplateParam(
                $pnvsTemplate !== null ? (string) $pnvsTemplate->template_content : '',
                $code,
                true,
            );
            return [$driver, $extra];
        }

        // 传统短信驱动:签名/模板必填
        $template = $this->model(SmsTemplate::class)->find($binding->template_id);
        if ($template === null) {
            throw new SmsException("场景 [{$scene}] 绑定的模板不存在,请重新绑定");
        }
        if ($template->audit_status !== SmsTemplate::AUDIT_PASSED) {
            throw new SmsException("场景 [{$scene}] 绑定的模板尚未审核通过");
        }

        $sign = $this->model(SmsSign::class)->find($binding->sign_id);
        if ($sign === null) {
            throw new SmsException("场景 [{$scene}] 绑定的签名不存在,请重新绑定");
        }
        if ($sign->audit_status !== SmsSign::AUDIT_PASSED) {
            throw new SmsException("场景 [{$scene}] 绑定的签名尚未审核通过");
        }

        $extra['sign_name'] = $sign->sign_name;
        $extra['template_code'] = $template->template_code;
        $extra['template_param'] = $extra['template_param']
            ?? $this->buildTemplateParam((string) $template->template_content, $code, false);

        return [$driver, $extra];
    }

    /**
     * 按模板内容里的 ${xxx} 占位符构造 templateParam
     *
     * 行为约束:
     *  - PNVS 平台侧校验驱动:code 注入 ##code## 让平台自生成(否则后续 CheckSmsVerifyCode 无法校验)
     *  - 普通驱动:code 注入 SmsService 本地生成的 6 位码
     *  - min: 从 codeTtl 派生分钟数,避免 magic number
     *  - 未识别的占位符在 SmsSceneService::bind() 阶段已被拦截,此处不会出现;
     *    防御性兜底为空字符串,阿里云会返回模板校验失败便于排障
     *  - PNVS 兜底:即使模板没有占位符,也回填 {"code":"##code##"} 满足阿里云 TemplateParam 必填要求
     *
     * @return array<string, string>
     */
    private function buildTemplateParam(string $templateContent, string $code, bool $isPlatformManaged): array
    {
        $placeholders = SmsTemplate::extractPlaceholders($templateContent);
        $result = [];
        foreach ($placeholders as $key) {
            $result[$key] = match ($key) {
                'code'  => $isPlatformManaged ? '##code##' : $code,
                'min'   => (string) max(1, (int) ($this->codeTtl / 60)),
                default => '',
            };
        }
        if ($isPlatformManaged && $result === []) {
            $result['code'] = '##code##';
        }
        return $result;
    }

    /**
     * 解析校验阶段的驱动(仅用于 verifyCode 判断是否走平台侧校验)
     */
    private function resolveDriverForVerify(string $scene): ?BaseSmsDriver
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $binding = $this->model()
            ->where('scene_code', $scene)
            ->where('status', 1)
            ->find();
        if ($binding === null) {
            return null;
        }

        $provider = $this->model(SmsProvider::class)->find($binding->provider_id);
        if ($provider === null || (int) $provider->status !== 1) {
            return null;
        }

        return DriverManager::create('sms', (string) $provider->driver, [
            'access_key_id' => (string) $provider->access_key_id,
            'access_key_secret' => SmsSecret::decrypt((string) $provider->access_key_secret),
            'region' => (string) $provider->region,
            'scheme_name' => (string) ($provider->scheme_name ?? ''),
        ]);
    }

    private function codeKey(string $mobile, string $scene): string
    {
        return self::CODE_KEY_PREFIX . $scene . ':' . $mobile;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function assertScene(string $scene): void
    {
        if (!SmsScene::isValid($scene)) {
            throw new SmsException('无效的短信场景');
        }
    }

    private function assertMobile(string $mobile): void
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            throw new SmsException('手机号格式不正确');
        }
    }
}
