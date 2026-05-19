<?php

declare(strict_types=1);

namespace mall_base\drivers\sms;

use AlibabaCloud\SDK\Dypnsapi\V20170525\Dypnsapi;
use AlibabaCloud\SDK\Dypnsapi\V20170525\Models\CheckSmsVerifyCodeRequest;
use AlibabaCloud\SDK\Dypnsapi\V20170525\Models\SendSmsVerifyCodeRequest;
use Darabonba\OpenApi\Models\Config;
use Throwable;

/**
 * 阿里云号码认证服务(PNVS)短信认证驱动
 *
 * 与 AliyunSmsDriver 的核心区别:
 *  - 平台管理验证码生命周期(生成、发送、校验),不需要自建缓存
 *  - 签名/模板可选:配置则传入 API,未配置则使用平台默认值
 *  - 适合个人开发者,无需企业资质
 *  - API: SendSmsVerifyCode / CheckSmsVerifyCode
 *
 * 兼容性约束:
 *  - sendCode() 仅负责触发平台发码,code 参数忽略(平台自动生成)
 *  - verifyCode() 委托平台校验,不走本地缓存
 *  - send() / sendNotice() 不支持,PNVS 仅用于验证码场景
 */
class AliyunPnvsDriver extends BaseSmsDriver
{
    protected string $accessKeyId = '';
    protected string $accessKeySecret = '';
    protected string $regionId = 'cn-hangzhou';

    /** 可选:认证方案名称,用于隔离不同业务的验证码 */
    private string $schemeName = '';

    private ?Dypnsapi $client = null;

    protected function init(): void
    {
        $this->accessKeyId = (string) $this->getConfig('access_key_id', '');
        $this->accessKeySecret = (string) $this->getConfig('access_key_secret', '');
        $this->regionId = (string) $this->getConfig('region', 'cn-hangzhou');
        $this->schemeName = (string) $this->getConfig('scheme_name', '');
    }

    public function supportsCodeVerification(): bool
    {
        return true;
    }

    public function sendCode(string $phone, string $scene, string $code, array $extra = []): bool
    {
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        try {
            $this->ensureClient();

            $reqParams = [
                'phoneNumber' => $phone,
                'codeLength'  => 6,
                'validTime'   => (int) ($extra['valid_time'] ?? 300),
            ];
            if (!empty($extra['sign_name'])) {
                $reqParams['signName'] = $extra['sign_name'];
            }
            if (!empty($extra['template_code'])) {
                $reqParams['templateCode'] = $extra['template_code'];
            }
            // PNVS 接口 templateParam 必填,即使模板没有占位符也得传 JSON。
            // 验证码占位符必须用 ##code## 让平台自生成,后续 CheckSmsVerifyCode 才能校验;
            // 上游 SmsService::buildTemplateParam 已保证此约束,这里只负责 JSON 序列化。
            $templateParam = $extra['template_param'] ?? ['code' => '##code##'];
            $reqParams['templateParam'] = json_encode($templateParam, JSON_UNESCAPED_UNICODE);
            $req = new SendSmsVerifyCodeRequest($reqParams);

            // 认证方案名称:优先用场景绑定配置,否则用服务商全局配置
            $scheme = (string) ($extra['scheme_name'] ?? $this->schemeName);
            if ($scheme !== '') {
                $req->schemeName = $scheme;
            }

            $resp = $this->client->sendSmsVerifyCode($req);
            $body = $resp->body;

            if (($body->code ?? '') !== 'OK') {
                $this->setError($this->formatError(
                    (string) ($body->code ?? 'UNKNOWN'),
                    (string) ($body->message ?? 'PNVS 短信发送失败')
                ));
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->setError('PNVS 短信发送异常: ' . $e->getMessage());
            return false;
        }
    }

    public function verifyCode(string $phone, string $code): bool
    {
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        try {
            $this->ensureClient();

            $req = new CheckSmsVerifyCodeRequest([
                'phoneNumber' => $phone,
                'verifyCode'  => $code,
            ]);

            $scheme = $this->schemeName;
            if ($scheme !== '') {
                $req->schemeName = $scheme;
            }

            $resp = $this->client->checkSmsVerifyCode($req);
            $body = $resp->body;

            // code=OK 仅代表接口调用成功,验证码是否正确取决于 model.verifyResult
            if (($body->code ?? '') !== 'OK') {
                $this->setError($this->formatError(
                    (string) ($body->code ?? 'UNKNOWN'),
                    (string) ($body->message ?? '验证码校验失败')
                ));
                return false;
            }

            $verifyResult = $body->model->verifyResult ?? '';
            if ($verifyResult !== 'PASS') {
                $this->setError('验证码错误或已过期');
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->setError('PNVS 验证码校验异常: ' . $e->getMessage());
            return false;
        }
    }

    public function send(string $phone, string $code): bool
    {
        $this->setError('PNVS 驱动不支持直接发送,请调用 sendCode()');
        return false;
    }

    public function sendNotice(string $phone, array $params): bool
    {
        $this->setError('PNVS 驱动仅支持验证码场景,不支持通知短信');
        return false;
    }

    private function ensureClient(): void
    {
        if ($this->client !== null) {
            return;
        }
        if ($this->accessKeyId === '' || $this->accessKeySecret === '') {
            throw new \mall_base\exception\SmsException('未配置阿里云 AccessKey,请先在服务商管理中填写');
        }

        $config = new Config([
            'accessKeyId'     => $this->accessKeyId,
            'accessKeySecret' => $this->accessKeySecret,
            'regionId'        => $this->regionId,
            'endpoint'        => 'dypnsapi.aliyuncs.com',
        ]);
        $this->client = new Dypnsapi($config);
    }

    private function formatError(string $code, string $message): string
    {
        return $code === '' ? $message : "{$message} (code: {$code})";
    }
}
