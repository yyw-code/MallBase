<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\job\SmsTemplateSyncJob;
use app\model\sms\SmsProvider;
use app\model\sms\SmsSign;
use app\model\sms\SmsTemplate;
use app\service\sms\SmsScene;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use mall_base\queue\JobQueue;
use Throwable;

/**
 * 短信模板 Service
 *
 * @extends BaseService<SmsTemplate>
 */
class SmsTemplateService extends BaseService
{
    protected string $modelClass = SmsTemplate::class;

    public function getList(array $where, int $page, int $limit): array
    {
        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['placeholders'] = SmsTemplate::extractPlaceholders((string) ($row['template_content'] ?? ''));
        }
        unset($row);

        return compact('total', 'list');
    }

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('template_name|template_code', "%{$where['keyword']}%");
            })
            ->when(!empty($where['provider_id']), function ($q) use ($where) {
                $q->where('provider_id', (int) $where['provider_id']);
            })
            ->when(!empty($where['audit_status']), function ($q) use ($where) {
                $q->where('audit_status', $where['audit_status']);
            });
    }

    public function getInfo(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        $info = $row->toArray();
        $info['placeholders'] = SmsTemplate::extractPlaceholders((string) ($info['template_content'] ?? ''));
        return $info;
    }

    /**
     * 校验并返回模板要关联的签名 ID
     *
     * 模板建议关联一个本服务商下的签名:
     * 提交阿里云时 CreateSmsTemplate/UpdateSmsTemplate 需要 RelatedSignName。
     * 签名缺失或跨服务商时抛 BusinessException。
     */
    private function resolveSignId(int $providerId, int $signId): int
    {
        if ($signId <= 0) {
            throw new BusinessException('请选择关联签名');
        }
        $sign = $this->model(SmsSign::class)->find($signId);
        if ($sign === null || (int) $sign->provider_id !== $providerId) {
            throw new BusinessException('关联签名不存在或不属于当前服务商');
        }
        return $signId;
    }

    private function resolveSignName(int $signId): string
    {
        $signName = (string) $this->model(SmsSign::class)
            ->where('id', $signId)
            ->value('sign_name');
        if ($signName === '') {
            throw new BusinessException('关联签名不存在');
        }
        return $signName;
    }

    private function shouldSubmitToPlatform(array $data, bool $default = true): bool
    {
        if (!array_key_exists('submit_to_platform', $data)) {
            return $default;
        }

        return in_array($data['submit_to_platform'], [true, 1, '1', 'true', 'on'], true);
    }

    private function ensureTemplateCodeUnique(int $providerId, string $templateCode, int $excludeId = 0): void
    {
        $query = $this->model()
            ->where('provider_id', $providerId)
            ->where('template_code', $templateCode);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->find() !== null) {
            throw new BusinessException("模板编码 [{$templateCode}] 已存在于当前服务商");
        }
    }

    /**
     * 校验并构造模板创建数据。仅返回可落库 payload,不执行数据库写入。
     *
     * @return array<string, mixed>
     */
    public function prepareCreatePayload(array $data, bool $defaultSubmitToPlatform = true): array
    {
        $providerId = (int) ($data['provider_id'] ?? 0);
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $templateName = trim((string) ($data['template_name'] ?? ''));
        if ($templateName === '') {
            throw new BusinessException('模板名称必填');
        }

        $templateContent = (string) ($data['template_content'] ?? '');
        if (trim($templateContent) === '') {
            throw new BusinessException('模板内容必填');
        }

        $signId = $this->resolveSignId($providerId, (int) ($data['sign_id'] ?? 0));
        $remark = trim((string) ($data['remark'] ?? ''));
        if ($remark === '') {
            $remark = $templateName;
        }

        $payload = [
            'provider_id' => $providerId,
            'sign_id' => $signId,
            'template_name' => $templateName,
            'template_type' => (int) ($data['template_type'] ?? 0),
            'template_content' => $templateContent,
            'remark' => $remark,
            'template_code' => null,
            'audit_status' => SmsTemplate::AUDIT_LOCAL_ONLY,
            'audit_reason' => null,
            'last_synced_at' => null,
        ];

        if ($this->shouldSubmitToPlatform($data, $defaultSubmitToPlatform)) {
            if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
                throw new BusinessException('当前服务商不支持提交平台审核');
            }
            $this->resolveSignName($signId);
            $payload['audit_status'] = SmsTemplate::AUDIT_SUBMITTING;
            $payload['audit_reason'] = '已提交平台同步队列';
            return $payload;
        }

        $templateCode = trim((string) ($data['template_code'] ?? ''));
        if ($templateCode === '') {
            throw new BusinessException('本地登记模板需填写平台模板编码');
        }
        $this->ensureTemplateCodeUnique($providerId, $templateCode);

        $payload['template_code'] = $templateCode;
        $payload['audit_status'] = SmsTemplate::AUDIT_LOCAL_ONLY;
        $payload['audit_reason'] = '本地登记模板,未提交平台审核';
        return $payload;
    }

    /**
     * 创建短信模板。
     *
     * submit_to_platform=1 时本地先保存为 submitting,再派发队列提交平台;
     * submit_to_platform=0 时仅本地登记平台模板编码。
     */
    public function create(array $data): int
    {
        $payload = $this->prepareCreatePayload($data);

        $row = $this->model();
        $row->save($payload);
        $this->dispatchSyncIfSubmitting((int) $row->id, (string) $payload['audit_status']);
        return (int) $row->id;
    }

    public function dispatchSyncIfSubmitting(int $templateId, string $auditStatus): void
    {
        if ($templateId <= 0 || $auditStatus !== SmsTemplate::AUDIT_SUBMITTING) {
            return;
        }

        try {
            JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => $templateId]);
        } catch (Throwable $e) {
            $this->model()
                ->where('id', $templateId)
                ->update([
                    'audit_status' => SmsTemplate::AUDIT_LOCAL_ONLY,
                    'audit_reason' => '平台同步队列派发失败:' . $e->getMessage(),
                    'last_synced_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    public function update(int $id, array $data): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        $provider = $this->model(SmsProvider::class)->find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        $templateName = trim((string) ($data['template_name'] ?? ''));
        if ($templateName === '') {
            throw new BusinessException('模板名称必填');
        }
        $templateContent = (string) ($data['template_content'] ?? $row->template_content);
        if (trim($templateContent) === '') {
            throw new BusinessException('模板内容必填');
        }

        $newData = [
            'template_name' => $templateName,
            'template_type' => (int) ($data['template_type'] ?? $row->template_type),
            'template_content' => $templateContent,
            'remark' => $data['remark'] ?? null,
        ];

        // 模板必须关联签名;用户未传 sign_id 时沿用原值
        $signId = $this->resolveSignId(
            (int) $row->provider_id,
            (int) ($data['sign_id'] ?? $row->sign_id ?? 0),
        );
        $newData['sign_id'] = $signId;

        // remark 兜底:Aliyun ModifyTemplate / AddSmsTemplate 都要求 Remark 非空
        $remark = trim((string) ($newData['remark'] ?? ''));
        if ($remark === '') {
            $remark = $newData['template_name'];
        }
        $newData['remark'] = $remark;

        if (!$this->shouldSubmitToPlatform($data, (string) $row->audit_status !== SmsTemplate::AUDIT_LOCAL_ONLY)) {
            $templateCode = trim((string) ($data['template_code'] ?? $row->template_code));
            if ($templateCode === '') {
                throw new BusinessException('本地登记模板需填写平台模板编码');
            }
            $this->ensureTemplateCodeUnique((int) $row->provider_id, $templateCode, (int) $row->id);

            $newData['template_code'] = $templateCode;
            $newData['audit_status'] = SmsTemplate::AUDIT_LOCAL_ONLY;
            $newData['audit_reason'] = '本地登记模板,未提交平台审核';
            $row->save($newData);
            return;
        }

        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('当前服务商不支持提交平台审核');
        }

        $this->resolveSignName($signId);
        $newData['audit_status'] = SmsTemplate::AUDIT_SUBMITTING;
        $newData['audit_reason'] = '已提交平台同步队列';
        $row->save($newData);
        $this->dispatchSyncIfSubmitting((int) $row->id, SmsTemplate::AUDIT_SUBMITTING);
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }

        if ((string) $row->template_code !== '' && (string) $row->audit_status !== SmsTemplate::AUDIT_LOCAL_ONLY) {
            $provider = $this->model(SmsProvider::class)->find($row->provider_id);
            if ($provider !== null && SmsDriverFactory::supportsRemoteSignManagement($provider)) {
                try {
                    $manager = SmsDriverFactory::manager($provider);
                    $manager->deleteTemplate((string) $row->template_code);
                } catch (Throwable) {
                    // 远端删除失败不阻塞本地清理
                }
            }
        }

        $row->delete();
    }

    /**
     * 单条同步:派发 SmsTemplateSyncJob 异步处理。
     *
     * 入参校验仍在本方法做(模板存在、服务商存在、支持远端管理),失败抛 BusinessException
     * 让控制器走标准失败响应;校验通过即 enqueue,真实远端调用由 Job 在后台完成。
     *
     * template_code 为空也允许重试(对应"首次提交失败 → local_only"的场景)。
     *
     * @return array{dispatched:int}
     */
    public function syncStatus(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        $provider = $this->model(SmsProvider::class)->find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('当前服务商不支持同步模板状态');
        }

        JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => (int) $row->id]);
        return ['dispatched' => 1];
    }

    /**
     * 按 provider 批量派发同步任务。
     *
     * 不再过滤 template_code(允许把 submitting/local_only
     * 的失败行一并重试)。
     *
     * @return array{dispatched:int}
     */
    public function syncAll(int $providerId): array
    {
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('当前服务商不支持同步模板状态');
        }
        $rows = $this->model()->where('provider_id', $providerId)->select();
        $dispatched = 0;
        foreach ($rows as $row) {
            JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => (int) $row->id]);
            $dispatched++;
        }
        return ['dispatched' => $dispatched];
    }

    /**
     * 按 id 列表批量派发同步任务
     *
     * 与 syncAll 的区别:
     *  - syncAll 按 provider 整体扫
     *  - syncBatch 严格按入参 id 数组执行,非法 id 计入 invalid;不支持同步 / 不存在的行计入 skipped
     *
     * @param array<int> $ids
     * @return array{dispatched:int, invalid:int, skipped:int}
     */
    public function syncBatch(array $ids): array
    {
        $dispatched = 0;
        $invalid = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                $invalid++;
                continue;
            }
            $row = $this->model()->find($id);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $provider = $this->model(SmsProvider::class)->find($row->provider_id);
            if ($provider === null || !SmsDriverFactory::supportsRemoteSignManagement($provider)) {
                $skipped++;
                continue;
            }
            JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => $id]);
            $dispatched++;
        }
        return ['dispatched' => $dispatched, 'invalid' => $invalid, 'skipped' => $skipped];
    }

    /**
     * 按内置场景批量创建模板。
     *
     * 复用 create() 完成单条创建;可选择本地登记或提交平台。
     *
     * @param array<int,array{scene_code:string,template_name:string,template_content:string,template_type?:int,remark?:string,template_code?:string}> $items
     * @return array{created:int,failed:int,results:array<int,array{scene_code:string,scene_name:string,success:bool,message:string,template_id:int}>}
     */
    public function createByScenes(int $providerId, int $signId, array $items, bool $submitToPlatform = true): array
    {
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if ($submitToPlatform && !SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('该方式仅支持阿里云短信服务商');
        }
        if (empty($items)) {
            throw new BusinessException('请至少选择一个场景');
        }
        // 整批模板共用同一个签名,提前校验以便快速失败
        $signId = $this->resolveSignId($providerId, $signId);

        $created = 0;
        $failed = 0;
        $results = [];
        foreach ($items as $item) {
            $sceneCode = (string) ($item['scene_code'] ?? '');
            $templateId = 0;
            $ok = false;
            if (!SmsScene::isValid($sceneCode)) {
                $message = '未知场景';
            } else {
                $sceneName = SmsScene::textOf($sceneCode);
                $remark = isset($item['remark']) ? trim((string) $item['remark']) : '';
                if ($remark === '') {
                    $remark = "「{$sceneName}」场景验证码短信,用于下发动态验证码";
                }
                try {
                    $templateId = $this->create([
                        'provider_id' => $providerId,
                        'sign_id' => $signId,
                        'template_name' => (string) ($item['template_name'] ?? ''),
                        'template_content' => (string) ($item['template_content'] ?? ''),
                        'template_type' => (int) ($item['template_type'] ?? 0),
                        'template_code' => (string) ($item['template_code'] ?? ''),
                        'remark' => $remark,
                        'submit_to_platform' => $submitToPlatform ? 1 : 0,
                    ]);
                    $ok = true;
                    $message = $submitToPlatform ? '已加入平台同步队列' : '已本地登记';
                } catch (Throwable $e) {
                    $message = $e->getMessage();
                }
            }

            $ok ? $created++ : $failed++;
            $results[] = [
                'scene_code' => $sceneCode,
                'scene_name' => SmsScene::isValid($sceneCode) ? SmsScene::textOf($sceneCode) : $sceneCode,
                'success' => $ok,
                'message' => $message,
                'template_id' => $templateId,
            ];
        }

        return ['created' => $created, 'failed' => $failed, 'results' => $results];
    }
}
