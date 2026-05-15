<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSceneBinding;
use app\model\sms\SmsSign;
use app\model\sms\SmsTemplate;
use app\service\sms\SmsScene;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 短信场景绑定 Service
 *
 * @extends BaseService<SmsSceneBinding>
 */
class SmsSceneService extends BaseService
{
    protected string $modelClass = SmsSceneBinding::class;

    /**
     * 列出所有内置场景 + 当前绑定情况(无绑定的也要返回行)
     */
    public function getList(): array
    {
        $bindings = $this->model()->select()->toArray();
        $byScene = [];
        foreach ($bindings as $row) {
            $byScene[$row['scene_code']] = $row;
        }

        $providerMap = SmsProvider::column('name', 'id');
        $templateMap = SmsTemplate::column('template_name', 'id');
        $signMap = SmsSign::column('sign_name', 'id');

        $list = [];
        foreach (SmsScene::allValues() as $code) {
            $row = $byScene[$code] ?? null;
            $list[] = [
                'scene_code' => $code,
                'scene_name' => SmsScene::textOf($code),
                'provider_id' => $row['provider_id'] ?? null,
                'provider_name' => $row ? ($providerMap[$row['provider_id']] ?? null) : null,
                'template_id' => $row['template_id'] ?? null,
                'template_name' => $row ? ($templateMap[$row['template_id']] ?? null) : null,
                'sign_id' => $row['sign_id'] ?? null,
                'sign_name' => $row ? ($signMap[$row['sign_id']] ?? null) : null,
                'status' => $row['status'] ?? 0,
                'update_time' => $row['update_time'] ?? null,
            ];
        }

        return $list;
    }

    public function bind(array $data): void
    {
        $sceneCode = (string) $data['scene_code'];
        if (!SmsScene::isValid($sceneCode)) {
            throw new BusinessException('未知场景');
        }

        $providerId = (int) $data['provider_id'];
        $templateId = (int) $data['template_id'];
        $signId = (int) $data['sign_id'];

        $template = SmsTemplate::find($templateId);
        if ($template === null || $template->provider_id !== $providerId) {
            throw new BusinessException('模板与服务商不匹配');
        }
        if ($template->audit_status !== SmsTemplate::AUDIT_PASSED) {
            throw new BusinessException('模板尚未审核通过,不能绑定');
        }

        $sign = SmsSign::find($signId);
        if ($sign === null || $sign->provider_id !== $providerId) {
            throw new BusinessException('签名与服务商不匹配');
        }
        if ($sign->audit_status !== SmsSign::AUDIT_PASSED) {
            throw new BusinessException('签名尚未审核通过,不能绑定');
        }

        $row = $this->model()->where('scene_code', $sceneCode)->find();
        $payload = [
            'scene_code' => $sceneCode,
            'provider_id' => $providerId,
            'template_id' => $templateId,
            'sign_id' => $signId,
            'status' => (int) ($data['status'] ?? 1),
        ];
        if ($row === null) {
            $this->model()->save($payload);
        } else {
            $row->save($payload);
        }
    }

    public function unbind(string $sceneCode): void
    {
        $this->model()->where('scene_code', $sceneCode)->delete();
    }
}
