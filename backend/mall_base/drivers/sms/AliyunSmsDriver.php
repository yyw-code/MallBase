<?php

declare(strict_types=1);

namespace mall_base\drivers\sms;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\AddSmsSignRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\AddSmsSignRequest\signFileList as AddSmsSignFileItem;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\AddSmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\DeleteSmsSignRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\DeleteSmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\ModifySmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\QuerySmsSignRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\QuerySmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use Darabonba\OpenApi\Models\Config;
use mall_base\drivers\sms\contracts\SmsTemplateManagerInterface;
use mall_base\exception\SmsException;
use Throwable;

/**
 * 阿里云短信驱动
 *
 * 兼容性硬约束:
 *  - sendCode/send/sendNotice 签名沿用 BaseSmsDriver,客户端零修改
 *  - 模板编码与签名名称由调用方(SmsService)经场景绑定查询后通过 $extra 注入
 */
class AliyunSmsDriver extends BaseSmsDriver implements SmsTemplateManagerInterface
{
    protected string $accessKeyId = '';
    protected string $accessKeySecret = '';
    protected string $regionId = 'cn-hangzhou';

    private ?Dysmsapi $client = null;

    protected function init(): void
    {
        $this->accessKeyId = (string) $this->getConfig('access_key_id', '');
        $this->accessKeySecret = (string) $this->getConfig('access_key_secret', '');
        $this->regionId = (string) $this->getConfig('region', 'cn-hangzhou');
    }

    // ------------------------------------------------------------------
    // BaseSmsDriver 实现(发送类)
    // ------------------------------------------------------------------

    public function sendCode(string $phone, string $scene, string $code, array $extra = []): bool
    {
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        $signName = (string) ($extra['sign_name'] ?? '');
        $templateCode = (string) ($extra['template_code'] ?? '');

        if ($signName === '' || $templateCode === '') {
            $this->setError("场景 [{$scene}] 未绑定阿里云短信签名/模板");
            return false;
        }

        $templateParam = $extra['template_param'] ?? ['code' => $code];

        try {
            $this->ensureClient();
            $req = new SendSmsRequest([
                'phoneNumbers' => $phone,
                'signName' => $signName,
                'templateCode' => $templateCode,
                'templateParam' => json_encode($templateParam, JSON_UNESCAPED_UNICODE),
            ]);
            $resp = $this->client->sendSms($req);
            $body = $resp->body;
            if (($body->code ?? '') !== 'OK') {
                $this->setError($this->formatError($body->code ?? 'UNKNOWN', $body->message ?? '阿里云短信发送失败'));
                return false;
            }
            return true;
        } catch (Throwable $e) {
            $this->setError('阿里云短信发送异常: ' . $e->getMessage());
            return false;
        }
    }

    public function send(string $phone, string $code): bool
    {
        // 默认登录场景,但无法在此自动查 binding,要求显式调用 sendCode($phone, 'login', $code, [...])
        $this->setError('请调用 sendCode($phone, $scene, $code, $extra) 显式传入场景与模板');
        return false;
    }

    public function sendNotice(string $phone, array $params): bool
    {
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        $signName = (string) ($params['sign_name'] ?? '');
        $templateCode = (string) ($params['template_code'] ?? '');
        $templateParam = $params['template_param'] ?? [];

        if ($signName === '' || $templateCode === '') {
            $this->setError('缺少 sign_name 或 template_code');
            return false;
        }

        try {
            $this->ensureClient();
            $req = new SendSmsRequest([
                'phoneNumbers' => $phone,
                'signName' => $signName,
                'templateCode' => $templateCode,
                'templateParam' => json_encode($templateParam, JSON_UNESCAPED_UNICODE),
            ]);
            $resp = $this->client->sendSms($req);
            $body = $resp->body;
            if (($body->code ?? '') !== 'OK') {
                $this->setError($this->formatError($body->code ?? 'UNKNOWN', $body->message ?? '阿里云短信发送失败'));
                return false;
            }
            return true;
        } catch (Throwable $e) {
            $this->setError('阿里云短信发送异常: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // SmsTemplateManagerInterface 实现(模板管理类)
    // ------------------------------------------------------------------

    public function addTemplate(array $data): array
    {
        $this->ensureClient();
        try {
            $req = new AddSmsTemplateRequest([
                'templateName' => $data['template_name'],
                'templateContent' => $data['template_content'],
                'templateType' => (int) $data['template_type'],
                'remark' => $data['remark'] ?? '',
            ]);
            $resp = $this->client->addSmsTemplate($req);
            $body = $resp->body;
            $this->assertOk($body->code ?? '', $body->message ?? '创建模板失败');
            return ['template_code' => (string) ($body->templateCode ?? '')];
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云创建模板异常: ' . $e->getMessage());
        }
    }

    public function modifyTemplate(string $templateCode, array $data): void
    {
        $this->ensureClient();
        try {
            $req = new ModifySmsTemplateRequest([
                'templateCode' => $templateCode,
                'templateName' => $data['template_name'],
                'templateContent' => $data['template_content'],
                'templateType' => (int) $data['template_type'],
                'remark' => $data['remark'] ?? '',
            ]);
            $resp = $this->client->modifySmsTemplate($req);
            $this->assertOk($resp->body->code ?? '', $resp->body->message ?? '修改模板失败');
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云修改模板异常: ' . $e->getMessage());
        }
    }

    public function deleteTemplate(string $templateCode): void
    {
        $this->ensureClient();
        try {
            $req = new DeleteSmsTemplateRequest(['templateCode' => $templateCode]);
            $resp = $this->client->deleteSmsTemplate($req);
            $this->assertOk($resp->body->code ?? '', $resp->body->message ?? '删除模板失败');
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云删除模板异常: ' . $e->getMessage());
        }
    }

    public function queryTemplate(string $templateCode): array
    {
        $this->ensureClient();
        try {
            $req = new QuerySmsTemplateRequest(['templateCode' => $templateCode]);
            $resp = $this->client->querySmsTemplate($req);
            $body = $resp->body;
            $this->assertOk($body->code ?? '', $body->message ?? '查询模板失败');
            return [
                'template_code' => (string) ($body->templateCode ?? $templateCode),
                'template_name' => (string) ($body->templateName ?? ''),
                'template_content' => (string) ($body->templateContent ?? ''),
                'template_type' => (int) ($body->templateType ?? 0),
                'audit_status' => $this->mapAuditStatus((int) ($body->templateStatus ?? 0)),
                'audit_reason' => $body->reason ?? null,
            ];
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云查询模板异常: ' . $e->getMessage());
        }
    }

    public function addSign(array $data): array
    {
        $this->ensureClient();
        try {
            $signFiles = $data['sign_files'] ?? [];
            if (empty($signFiles)) {
                throw new SmsException('阿里云签名审核必须附上资质证明文件,请上传至少 1 个文件');
            }

            $fileList = [];
            foreach ($signFiles as $f) {
                $fileList[] = new AddSmsSignFileItem([
                    'fileContents' => (string) ($f['file_contents'] ?? ''),
                    'fileSuffix' => (string) ($f['file_suffix'] ?? 'jpg'),
                ]);
            }

            $req = new AddSmsSignRequest([
                'signName' => $data['sign_name'],
                'signSource' => (int) $data['sign_source'],
                'signType' => (int) $data['sign_type'],
                'remark' => $data['remark'] ?? '',
                'signFileList' => $fileList,
            ]);
            $resp = $this->client->addSmsSign($req);
            $body = $resp->body;
            $this->assertOk($body->code ?? '', $body->message ?? '创建签名失败');
            return ['sign_name' => (string) ($body->signName ?? $data['sign_name'])];
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云创建签名异常: ' . $e->getMessage());
        }
    }

    public function deleteSign(string $signName): void
    {
        $this->ensureClient();
        try {
            $req = new DeleteSmsSignRequest(['signName' => $signName]);
            $resp = $this->client->deleteSmsSign($req);
            $this->assertOk($resp->body->code ?? '', $resp->body->message ?? '删除签名失败');
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云删除签名异常: ' . $e->getMessage());
        }
    }

    public function querySign(string $signName): array
    {
        $this->ensureClient();
        try {
            $req = new QuerySmsSignRequest(['signName' => $signName]);
            $resp = $this->client->querySmsSign($req);
            $body = $resp->body;
            $this->assertOk($body->code ?? '', $body->message ?? '查询签名失败');
            return [
                'sign_name' => (string) ($body->signName ?? $signName),
                'audit_status' => $this->mapAuditStatus((int) ($body->signStatus ?? 0)),
                'audit_reason' => $body->reason ?? null,
            ];
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsException('阿里云查询签名异常: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    private function ensureClient(): void
    {
        if ($this->client !== null) {
            return;
        }
        if ($this->accessKeyId === '' || $this->accessKeySecret === '') {
            throw new SmsException('未配置阿里云 AccessKey,请先在服务商管理中填写');
        }

        $config = new Config([
            'accessKeyId' => $this->accessKeyId,
            'accessKeySecret' => $this->accessKeySecret,
            'regionId' => $this->regionId,
            'endpoint' => 'dysmsapi.aliyuncs.com',
        ]);
        $this->client = new Dysmsapi($config);
    }

    /**
     * 把阿里云模板/签名状态码映射成本地枚举字符串
     *
     * 阿里云:
     *  - 0 = 审核中
     *  - 1 = 审核通过
     *  - 2 = 审核失败
     *  - 10 = 取消审核(签名才有,做 rejected 处理)
     */
    private function mapAuditStatus(int $status): string
    {
        return match ($status) {
            0 => 'pending',
            1 => 'passed',
            default => 'rejected',
        };
    }

    private function assertOk(string $code, string $message): void
    {
        if ($code !== 'OK') {
            throw new SmsException($this->formatError($code, $message));
        }
    }

    private function formatError(string $code, string $message): string
    {
        return $code === '' ? $message : "{$message} (code: {$code})";
    }
}
