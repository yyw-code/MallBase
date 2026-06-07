<?php

declare(strict_types=1);

namespace mall_base\drivers\sms;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\AddSmsSignRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\AddSmsSignRequest\signFileList as AddSmsSignFileItem;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\CreateSmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\DeleteSmsSignRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\DeleteSmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\GetSmsTemplateRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\QuerySmsSignRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\UpdateSmsTemplateRequest;
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
            $content = (string) $data['template_content'];
            $params = [
                'templateName' => $data['template_name'],
                'templateContent' => $content,
                'templateType' => (int) $data['template_type'],
                'remark' => $data['remark'] ?? '',
                // 新接口 CreateSmsTemplate 强制要求 RelatedSignName:模板需关联一个
                // 已存在的短信签名供内容审核参照,由调用方解析后注入
                'relatedSignName' => $this->requireSignName($data),
            ];
            // 新接口 CreateSmsTemplate:模板含 ${var} 占位符时必须随附 TemplateRule
            // 声明每个变量的类型(JSON),否则审核驳回「变量不符合规范」
            $rule = $this->buildTemplateRule($content);
            if ($rule !== null) {
                $params['templateRule'] = $rule;
            }
            $resp = $this->client->createSmsTemplate(new CreateSmsTemplateRequest($params));
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
            $content = (string) $data['template_content'];
            $params = [
                'templateCode' => $templateCode,
                'templateName' => $data['template_name'],
                'templateContent' => $content,
                'templateType' => (int) $data['template_type'],
                'remark' => $data['remark'] ?? '',
                'relatedSignName' => $this->requireSignName($data),
            ];
            $rule = $this->buildTemplateRule($content);
            if ($rule !== null) {
                $params['templateRule'] = $rule;
            }
            $resp = $this->client->updateSmsTemplate(new UpdateSmsTemplateRequest($params));
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
            $req = new GetSmsTemplateRequest(['templateCode' => $templateCode]);
            $resp = $this->client->getSmsTemplate($req);
            $body = $resp->body;
            $this->assertOk($body->code ?? '', $body->message ?? '查询模板失败');
            // 新接口把驳回原因放进 auditInfo->rejectInfo,替代旧接口的扁平 reason 字段
            $reason = $body->auditInfo->rejectInfo ?? null;
            return [
                'template_code' => (string) ($body->templateCode ?? $templateCode),
                'template_name' => (string) ($body->templateName ?? ''),
                'template_content' => (string) ($body->templateContent ?? ''),
                'template_type' => (int) ($body->templateType ?? 0),
                'audit_status' => $this->mapTemplateStatus((string) ($body->templateStatus ?? '')),
                'audit_reason' => $reason !== null && $reason !== '' ? (string) $reason : null,
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

    /**
     * 把新接口 GetSmsTemplate 的 templateStatus 字符串映射成本地枚举
     *
     * 阿里云取值:
     *  - AUDIT_STATE_INIT      审核中
     *  - AUDIT_STATE_PASS      审核通过
     *  - AUDIT_STATE_NOT_PASS  审核未通过(原因见 auditInfo.rejectInfo)
     *  - AUDIT_SATE_CANCEL     取消审核
     */
    private function mapTemplateStatus(string $status): string
    {
        $status = strtoupper(trim($status));

        // GetSmsTemplate 返回 "0"/"1"/"2"/"10",QuerySmsTemplateList 返回 AUDIT_STATE_*。
        // 两种来源都统一映射成本地审核枚举,避免已通过模板被默认分支误判为失败。
        if (in_array($status, ['2', '10', 'AUDIT_STATE_NOT_PASS', 'AUDIT_STATE_CANCEL', 'AUDIT_SATE_CANCEL'], true)) {
            return 'rejected';
        }
        if (in_array($status, ['1', 'AUDIT_STATE_PASS'], true)) {
            return 'passed';
        }
        if (in_array($status, ['0', 'AUDIT_STATE_INIT'], true)) {
            return 'pending';
        }
        if (str_contains($status, 'NOT_PASS')) {
            return 'rejected';
        }
        if (str_contains($status, 'PASS')) {
            return 'passed';
        }
        if (str_contains($status, 'INIT')) {
            return 'pending';
        }
        return 'rejected';
    }

    /**
     * 由模板内容里的 ${var} 占位符构造 CreateSmsTemplate 的 TemplateRule(JSON)
     *
     * 新接口要求模板含变量时声明每个变量类型,否则审核驳回。命名含
     * code/captcha/验证/yzm 的变量按数字验证码处理,其余归为通用类型 others。
     * 无占位符时返回 null(纯文本模板无需 TemplateRule)。
     */
    private function buildTemplateRule(string $content): ?string
    {
        if (!preg_match_all('/\$\{(\w+)\}/', $content, $matches)) {
            return null;
        }
        $rule = [];
        foreach (array_unique($matches[1]) as $name) {
            $lower = strtolower($name);
            $isCaptcha = str_contains($lower, 'code')
                || str_contains($lower, 'captcha')
                || str_contains($lower, 'yzm')
                || str_contains($name, '验证');
            $rule[$name] = $isCaptcha ? 'numberCaptcha' : 'others';
        }
        return json_encode($rule, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 取出并校验关联签名名称
     *
     * 阿里云新接口 CreateSmsTemplate/UpdateSmsTemplate 把 RelatedSignName 列为必填,
     * 由调用方(SmsTemplateService / SmsTemplateSyncJob)解析服务商下的签名后注入。
     */
    private function requireSignName(array $data): string
    {
        $signName = trim((string) ($data['related_sign_name'] ?? ''));
        if ($signName === '') {
            throw new SmsException('阿里云新接口要求模板必须关联一个已存在的短信签名,请先在「短信签名」中创建并通过签名后再提交模板');
        }
        return $signName;
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
