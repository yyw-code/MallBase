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

    public function getList(array $where = [], int $page = 1, int $limit = 15): array
    {
        $list = $this->filterSceneRows($this->buildSceneRows(), $where);
        $total = count($list);
        $list = array_slice($list, ($page - 1) * $limit, $limit);

        return compact('total', 'list');
    }

    /**
     * 列出所有内置场景 + 当前绑定情况(无绑定的也要返回行)
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildSceneRows(): array
    {
        $bindings = $this->model()->select()->toArray();
        $byScene = [];
        foreach ($bindings as $row) {
            $byScene[$row['scene_code']] = $row;
        }

        $providerMap = $this->model(SmsProvider::class)->column('name', 'id');
        $templateMap = [];
        foreach ($this->model(SmsTemplate::class)->select()->toArray() as $template) {
            $templateMap[$template['id']] = $template;
        }
        $signMap = [];
        foreach ($this->model(SmsSign::class)->select()->toArray() as $sign) {
            $signMap[$sign['id']] = $sign;
        }

        $list = [];
        foreach (SmsScene::allValues() as $code) {
            $row = $byScene[$code] ?? null;
            $isBound = $row !== null
                && (int) ($row['provider_id'] ?? 0) > 0
                && !empty($row['template_id'])
                && !empty($row['sign_id']);
            $template = $isBound ? ($templateMap[$row['template_id']] ?? null) : null;
            $sign = $isBound ? ($signMap[$row['sign_id']] ?? null) : null;
            $draft = $this->draftFromRow($row);
            $list[] = [
                'id' => $row['id'] ?? null,
                'scene_code' => $code,
                'scene_name' => SmsScene::textOf($code),
                'provider_id' => $isBound ? (int) $row['provider_id'] : null,
                'provider_name' => $isBound ? ($providerMap[$row['provider_id']] ?? null) : null,
                'template_id' => $isBound ? (int) $row['template_id'] : null,
                'template_name' => $template['template_name'] ?? null,
                'template_code' => $template['template_code'] ?? null,
                'template_audit_status' => $template['audit_status'] ?? null,
                'sign_id' => $isBound ? (int) $row['sign_id'] : null,
                'sign_name' => $sign['sign_name'] ?? null,
                'status' => $isBound ? (int) $row['status'] : null,
                'update_time' => $row['update_time'] ?? null,
                'available_params' => SmsScene::availableParamNames($code),
                'draft_template_name' => $draft['draft_template_name'],
                'draft_template_content' => $draft['draft_template_content'],
                'draft_template_type' => $draft['draft_template_type'],
                'draft_template_remark' => $draft['draft_template_remark'],
            ];
        }

        return $list;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{draft_template_name:string,draft_template_content:string,draft_template_type:int,draft_template_remark:string}
     */
    private function draftFromRow(?array $row): array
    {
        return [
            'draft_template_name' => trim((string) ($row['draft_template_name'] ?? '')),
            'draft_template_content' => (string) ($row['draft_template_content'] ?? ''),
            'draft_template_type' => isset($row['draft_template_type'])
                ? (int) $row['draft_template_type']
                : 0,
            'draft_template_remark' => trim((string) ($row['draft_template_remark'] ?? '')),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    protected function filterSceneRows(array $list, array $where): array
    {
        $keyword = trim((string) ($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $list = array_filter($list, static function (array $row) use ($keyword): bool {
                foreach (['scene_code', 'scene_name', 'provider_name', 'template_name', 'sign_name', 'draft_template_name', 'draft_template_content'] as $field) {
                    if (str_contains((string) ($row[$field] ?? ''), $keyword)) {
                        return true;
                    }
                }
                return false;
            });
        }

        if (($where['provider_id'] ?? null) !== null && $where['provider_id'] !== '') {
            $providerId = (int) $where['provider_id'];
            $list = array_filter($list, static fn(array $row): bool => (int) ($row['provider_id'] ?? 0) === $providerId);
        }

        if (($where['status'] ?? null) !== null && $where['status'] !== '') {
            $status = (int) $where['status'];
            $list = array_filter(
                $list,
                static fn(array $row): bool => ($row['status'] ?? null) !== null
                    && (int) $row['status'] === $status
            );
        }

        return array_values($list);
    }

    /**
     * @return array{draft_template_name:string,draft_template_content:string,draft_template_type:int,draft_template_remark:string}
     */
    private function draftFromInput(string $sceneCode, array $data): array
    {
        $row = $this->model()->where('scene_code', $sceneCode)->find();
        $current = $this->draftFromRow($row ? $row->toArray() : null);

        $name = trim((string) ($data['draft_template_name'] ?? $current['draft_template_name']));
        $content = (string) ($data['draft_template_content'] ?? $current['draft_template_content']);
        $remark = trim((string) ($data['draft_template_remark'] ?? $current['draft_template_remark']));

        if ($name === '') {
            throw new BusinessException('场景模板草稿名称不能为空');
        }
        if (trim($content) === '') {
            throw new BusinessException('场景模板草稿内容不能为空');
        }

        return [
            'draft_template_name' => $name,
            'draft_template_content' => $content,
            'draft_template_type' => isset($data['draft_template_type'])
                ? (int) $data['draft_template_type']
                : (int) $current['draft_template_type'],
            'draft_template_remark' => $remark,
        ];
    }

    private function assertPlaceholdersSupported(string $sceneCode, string $content): void
    {
        $placeholders = SmsTemplate::extractPlaceholders($content);
        $unsupported = array_values(array_diff($placeholders, SmsScene::availableParamNames($sceneCode)));
        if (!empty($unsupported)) {
            throw new BusinessException(
                '模板包含占位符 [' . implode(',', $unsupported) . '] 当前场景未提供;'
                . '请联系开发扩展 SmsScene::availableParamNames 或更换不含该占位符的模板'
            );
        }
    }

    private function saveBindingRow(string $sceneCode, array $payload): void
    {
        $row = $this->model()->where('scene_code', $sceneCode)->find();
        if ($row === null) {
            $this->model()->save($payload);
            return;
        }
        $row->save($payload);
    }

    public function bind(array $data): void
    {
        $sceneCode = (string) $data['scene_code'];
        if (!SmsScene::isValid($sceneCode)) {
            throw new BusinessException('未知场景');
        }

        $providerId = (int) $data['provider_id'];
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $templateId = !empty($data['template_id']) ? (int) $data['template_id'] : 0;
        $signId = !empty($data['sign_id']) ? (int) $data['sign_id'] : 0;

        if ($templateId <= 0) {
            throw new BusinessException('请选择模板');
        }
        if ($signId <= 0) {
            throw new BusinessException('请选择签名');
        }

        $template = $this->model(SmsTemplate::class)->find($templateId);
        if ($template === null || (int) $template->provider_id !== $providerId) {
            throw new BusinessException('模板与服务商不匹配');
        }
        $sign = $this->model(SmsSign::class)->find($signId);
        if ($sign === null || (int) $sign->provider_id !== $providerId) {
            throw new BusinessException('签名与服务商不匹配');
        }

        if (trim((string) $template->template_code) === '') {
            throw new BusinessException('模板缺少平台模板编码,请先填写模板编码或提交平台申请');
        }
        if (trim((string) $sign->sign_name) === '') {
            throw new BusinessException('签名名称不能为空');
        }

        $this->assertPlaceholdersSupported($sceneCode, (string) $template->template_content);
        $draft = $this->draftFromInput($sceneCode, $data);

        $payload = [
            'scene_code' => $sceneCode,
            'provider_id' => $providerId,
            'template_id' => $templateId,
            'sign_id' => $signId,
            'status' => (int) ($data['status'] ?? 1),
            'draft_template_name' => $draft['draft_template_name'],
            'draft_template_content' => $draft['draft_template_content'],
            'draft_template_type' => $draft['draft_template_type'],
            'draft_template_remark' => $draft['draft_template_remark'],
        ];
        $this->saveBindingRow($sceneCode, $payload);
    }

    public function saveDraft(array $data): void
    {
        $sceneCode = (string) ($data['scene_code'] ?? '');
        if (!SmsScene::isValid($sceneCode)) {
            throw new BusinessException('未知场景');
        }

        $draft = $this->draftFromInput($sceneCode, $data);
        $this->assertPlaceholdersSupported($sceneCode, $draft['draft_template_content']);

        $row = $this->model()->where('scene_code', $sceneCode)->find();
        $payload = [
            'scene_code' => $sceneCode,
            'provider_id' => $row ? (int) $row->provider_id : 0,
            'template_id' => $row ? $row->template_id : null,
            'sign_id' => $row ? $row->sign_id : null,
            'status' => $row ? (int) $row->status : 0,
            'draft_template_name' => $draft['draft_template_name'],
            'draft_template_content' => $draft['draft_template_content'],
            'draft_template_type' => $draft['draft_template_type'],
            'draft_template_remark' => $draft['draft_template_remark'],
        ];
        $this->saveBindingRow($sceneCode, $payload);
    }

    /**
     * 根据场景草稿创建模板并绑定到当前场景。
     *
     * @return array{template_id:int,template_code:string,audit_status:string}
     */
    public function createTemplateAndBind(array $data): array
    {
        $sceneCode = (string) ($data['scene_code'] ?? '');
        if (!SmsScene::isValid($sceneCode)) {
            throw new BusinessException('未知场景');
        }

        $draft = $this->draftFromInput($sceneCode, $data);
        $this->assertPlaceholdersSupported($sceneCode, $draft['draft_template_content']);

        /** @var SmsTemplateService $templateService */
        $templateService = app()->make(SmsTemplateService::class);
        $templatePayload = $templateService->prepareCreatePayload([
            'provider_id' => (int) ($data['provider_id'] ?? 0),
            'sign_id' => (int) ($data['sign_id'] ?? 0),
            'template_name' => $draft['draft_template_name'],
            'template_content' => $draft['draft_template_content'],
            'template_type' => $draft['draft_template_type'],
            'remark' => $draft['draft_template_remark'],
            'template_code' => $data['template_code'] ?? '',
            'submit_to_platform' => $data['submit_to_platform'] ?? 0,
        ], false);

        $result = $this->transaction(function () use ($sceneCode, $data, $draft, $templatePayload): array {
            $template = $this->model(SmsTemplate::class);
            $template->save($templatePayload);

            $this->saveBindingRow($sceneCode, [
                'scene_code' => $sceneCode,
                'provider_id' => (int) $templatePayload['provider_id'],
                'template_id' => (int) $template->id,
                'sign_id' => (int) $templatePayload['sign_id'],
                'status' => (int) ($data['status'] ?? 1),
                'draft_template_name' => $draft['draft_template_name'],
                'draft_template_content' => $draft['draft_template_content'],
                'draft_template_type' => $draft['draft_template_type'],
                'draft_template_remark' => $draft['draft_template_remark'],
            ]);

            return [
                'template_id' => (int) $template->id,
                'template_code' => (string) $templatePayload['template_code'],
                'audit_status' => (string) $templatePayload['audit_status'],
            ];
        });
        /** @var SmsTemplateService $templateService */
        $templateService = app()->make(SmsTemplateService::class);
        $templateService->dispatchSyncIfSubmitting(
            (int) $result['template_id'],
            (string) $result['audit_status'],
        );
        return $result;
    }

    public function unbind(string $sceneCode): void
    {
        $row = $this->model()->where('scene_code', $sceneCode)->find();
        if ($row === null) {
            return;
        }

        $row->save([
            'provider_id' => 0,
            'template_id' => null,
            'sign_id' => null,
            'status' => 0,
        ]);
    }
}
