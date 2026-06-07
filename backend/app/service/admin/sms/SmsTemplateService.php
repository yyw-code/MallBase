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
     * 远端管理驱动(普通阿里云)要求模板必须关联一个本服务商下的签名:
     * Aliyun CreateSmsTemplate/UpdateSmsTemplate 强制要求 RelatedSignName。
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

    /**
     * 创建本地记录 + 派发远端同步任务
     *
     * 流程分支:
     *  - 支持远端管理的驱动(普通阿里云短信):本地行先落库为 submitting,Aliyun AddSmsTemplate
     *    通过 SmsTemplateSyncJob 异步派发。失败时 Job 把 audit_status 回退为 local_only 并写
     *    audit_reason,用户可在列表手动「同步」重试。
     *  - 不支持远端管理的驱动(PNVS,模板由阿里云预置):
     *      template_code 必填(用户在控制台「赠送模板配置」查到),
     *      同一服务商下不可重复,直接入库为 local_only
     */
    public function create(array $data): int
    {
        $provider = $this->model(SmsProvider::class)->find($data['provider_id']);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $payload = [
            'provider_id' => (int) $data['provider_id'],
            // PNVS 模板无签名概念,保持 NULL;远端管理驱动在下方分支校验并写入
            'sign_id' => null,
            'template_name' => trim($data['template_name']),
            'template_type' => (int) ($data['template_type'] ?? 0),
            'template_content' => (string) ($data['template_content'] ?? ''),
            'remark' => $data['remark'] ?? null,
            // NULL 表示尚未分配远端编码;唯一键 uk_provider_template_code 对 NULL 不判重,
            // 同一服务商下允许多条 submitting 模板并存
            'template_code' => null,
            'audit_status' => SmsTemplate::AUDIT_LOCAL_ONLY,
            'audit_reason' => null,
        ];

        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            // PNVS 等无远端模板管理 API 的驱动:
            //  - template_code 必填且同服务商下唯一
            //  - template_content 必填(用于场景绑定时校验占位符 ⊆ 场景白名单)
            $templateCode = trim((string) ($data['template_code'] ?? ''));
            if ($templateCode === '') {
                throw new BusinessException('PNVS 模板编码必填,请在号码认证控制台查看赠送模板编码后输入');
            }
            if ($payload['template_content'] === '') {
                throw new BusinessException('PNVS 模板内容必填,请抄录阿里云控制台显示的模板原文,系统据此校验场景参数是否匹配');
            }
            $exists = $this->model()
                ->where('provider_id', $payload['provider_id'])
                ->where('template_code', $templateCode)
                ->find();
            if ($exists !== null) {
                throw new BusinessException("模板编码 [{$templateCode}] 已存在于当前服务商,请勿重复录入");
            }
            $payload['template_code'] = $templateCode;
            $payload['audit_reason'] = 'PNVS 系统赠送模板,无需远端审核';

            $row = $this->model();
            $row->save($payload);
            return (int) $row->id;
        }

        if ($payload['template_content'] === '') {
            throw new BusinessException('模板内容必填');
        }
        // 阿里云新接口 CreateSmsTemplate 强制要求 RelatedSignName,
        // 由用户在表单显式选择签名,异步 Job 据 sign_id 反查签名名称
        $payload['sign_id'] = $this->resolveSignId(
            $payload['provider_id'],
            (int) ($data['sign_id'] ?? 0),
        );
        // remark 兜底:Aliyun AddSmsTemplate 要求 Remark 非空,空值时用模板名补
        $remark = trim((string) ($payload['remark'] ?? ''));
        if ($remark === '') {
            $remark = $payload['template_name'];
        }
        $payload['remark'] = $remark;
        $payload['audit_status'] = SmsTemplate::AUDIT_SUBMITTING;
        $payload['audit_reason'] = null;

        $row = $this->model();
        $row->save($payload);

        JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => (int) $row->id]);

        return (int) $row->id;
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

        $newData = [
            'template_name' => trim($data['template_name']),
            'template_type' => (int) ($data['template_type'] ?? $row->template_type),
            'template_content' => (string) ($data['template_content'] ?? $row->template_content),
            'remark' => $data['remark'] ?? null,
        ];

        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            // PNVS:模板由阿里云预置,本地只维护引用信息;
            // template_code 可更新但需同服务商下唯一;
            // template_content 必填(发送时按其占位符构造 templateParam)
            $newCode = isset($data['template_code']) ? trim((string) $data['template_code']) : (string) $row->template_code;
            if ($newCode === '') {
                throw new BusinessException('PNVS 模板编码必填');
            }
            if ($newData['template_content'] === '') {
                throw new BusinessException('PNVS 模板内容必填,请抄录阿里云控制台显示的模板原文');
            }
            if ($newCode !== (string) $row->template_code) {
                $dup = $this->model()
                    ->where('provider_id', $row->provider_id)
                    ->where('template_code', $newCode)
                    ->where('id', '<>', $row->id)
                    ->find();
                if ($dup !== null) {
                    throw new BusinessException("模板编码 [{$newCode}] 已存在于当前服务商,请勿重复录入");
                }
            }
            $newData['template_code'] = $newCode;
            $newData['audit_status'] = SmsTemplate::AUDIT_LOCAL_ONLY;
            $newData['audit_reason'] = 'PNVS 系统赠送模板,无需远端审核';
            $row->save($newData);
            return;
        }

        // 远端管理驱动:模板必须关联签名;用户未传 sign_id 时沿用原值
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

        // 已提交远端的模板才调修改接口;local_only / 失败重试(template_code 为 NULL)走"重新创建"路径
        if ((string) $row->template_code !== '') {
            try {
                $manager = SmsDriverFactory::manager($provider);
                // 阿里云新接口 UpdateSmsTemplate 必填 RelatedSignName,取用户所选签名
                $newData['related_sign_name'] = (string) $this->model(SmsSign::class)
                    ->where('id', $signId)
                    ->value('sign_name');
                $manager->modifyTemplate((string) $row->template_code, $newData);
                $newData['audit_status'] = SmsTemplate::AUDIT_PENDING;
                $newData['audit_reason'] = null;
                $newData['last_synced_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $newData['audit_reason'] = $e->getMessage();
            }
            $row->save($newData);
            return;
        }

        // template_code 为空 → 走异步派发,避免在 HTTP 请求里阻塞
        $newData['audit_status'] = SmsTemplate::AUDIT_SUBMITTING;
        $newData['audit_reason'] = null;
        $row->save($newData);
        JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => (int) $row->id]);
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }

        if ((string) $row->template_code !== '') {
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
     * 从阿里云导入已经存在并审核通过的模板(只查询,不调 AddSmsTemplate)
     *
     * 适用场景:
     *  - 你在阿里云控制台已经申请并审核通过的模板,想接入 mallbase
     *  - 旧线上数据迁移
     *
     * PNVS 服务商不支持导入:其模板由平台预置无 QuerySmsTemplate API,请走 create() 本地登记
     */
    public function importFromRemote(int $providerId, string $templateCode): int
    {
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('PNVS 服务商不支持导入,请使用「新增模板」在本地登记');
        }

        $exists = $this->model()
            ->where('provider_id', $providerId)
            ->where('template_code', $templateCode)
            ->find();
        if ($exists !== null) {
            throw new BusinessException("模板编码 [{$templateCode}] 已存在本地,请直接点击同步状态");
        }

        $manager = SmsDriverFactory::manager($provider);
        $remote = $manager->queryTemplate($templateCode);

        $row = $this->model();
        $row->save([
            'provider_id' => $providerId,
            'template_name' => $remote['template_name'] ?: $templateCode,
            'template_code' => $templateCode,
            'template_type' => $remote['template_type'],
            'template_content' => $remote['template_content'] ?: '(远端未返回内容)',
            'remark' => '从阿里云导入',
            'audit_status' => $remote['audit_status'],
            'audit_reason' => $remote['audit_reason'] ?? null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $row->id;
    }

    /**
     * 单条同步:派发 SmsTemplateSyncJob 异步处理。
     *
     * 入参校验仍在本方法做(模板存在、服务商存在、非 PNVS),失败抛 BusinessException
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
            throw new BusinessException('PNVS 模板为系统赠送,无需同步');
        }

        JobQueue::push(SmsTemplateSyncJob::class, ['templateId' => (int) $row->id]);
        return ['dispatched' => 1];
    }

    /**
     * 按 provider 批量派发同步任务。
     *
     * 仅对非 PNVS 的服务商执行;不再过滤 template_code(允许把 submitting/local_only
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
            throw new BusinessException('PNVS 服务商无需同步');
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
     *  - syncBatch 严格按入参 id 数组执行,非法 id 计入 invalid;非 PNVS / 不存在的行计入 skipped
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
     * 按内置场景批量创建模板(仅支持远端管理的驱动,如普通阿里云)
     *
     * 复用 create() 完成单条创建:本地行立即落库为 submitting,远端 AddSmsTemplate
     * 由 SmsTemplateSyncJob 异步执行。created/failed 仅反映"本地落库"是否成功;
     * 阿里云结果用户需稍后刷新列表查看 audit_status 与「审核备注」。
     *
     * @param array<int,array{scene_code:string,template_name:string,template_content:string,template_type?:int,remark?:string}> $items
     * @return array{created:int,failed:int,results:array<int,array{scene_code:string,scene_name:string,success:bool,message:string,template_id:int}>}
     */
    public function createByScenes(int $providerId, int $signId, array $items): array
    {
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('该方式仅支持阿里云普通短信驱动,PNVS 模板请使用手动创建');
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
                        'remark' => $remark,
                    ]);
                    $ok = true;
                    $message = '已加入后台同步队列';
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
