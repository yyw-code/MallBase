<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsTemplate;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
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
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('template_name|template_code', "%{$where['keyword']}%");
            })
            ->when(!empty($where['provider_id']), function ($q) use ($where) {
                $q->where('provider_id', (int) $where['provider_id']);
            })
            ->when(!empty($where['audit_status']), function ($q) use ($where) {
                $q->where('audit_status', $where['audit_status']);
            });

        $total = $query->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        return $row->toArray();
    }

    /**
     * 创建本地记录 + 调用阿里云 AddSmsTemplate
     * 远端失败时本地仍入库,状态置为 local_only,便于用户后续修复重试
     */
    public function create(array $data): int
    {
        $provider = SmsProvider::find($data['provider_id']);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $payload = [
            'provider_id' => (int) $data['provider_id'],
            'template_name' => trim($data['template_name']),
            'template_type' => (int) ($data['template_type'] ?? 0),
            'template_content' => $data['template_content'],
            'remark' => $data['remark'] ?? null,
            'template_code' => '',
            'audit_status' => SmsTemplate::AUDIT_LOCAL_ONLY,
            'audit_reason' => null,
        ];

        try {
            $manager = SmsDriverFactory::manager($provider);
            $remote = $manager->addTemplate([
                'template_name' => $payload['template_name'],
                'template_content' => $payload['template_content'],
                'template_type' => $payload['template_type'],
                'remark' => $payload['remark'] ?? '',
            ]);
            $payload['template_code'] = $remote['template_code'];
            $payload['audit_status'] = SmsTemplate::AUDIT_PENDING;
            $payload['last_synced_at'] = date('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $payload['audit_reason'] = $e->getMessage();
        }

        $row = $this->model();
        $row->save($payload);
        return (int) $row->id;
    }

    public function update(int $id, array $data): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        $provider = SmsProvider::find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $newData = [
            'template_name' => trim($data['template_name']),
            'template_type' => (int) ($data['template_type'] ?? $row->template_type),
            'template_content' => $data['template_content'],
            'remark' => $data['remark'] ?? null,
        ];

        // 只有已提交远端的模板才调修改接口;local_only 状态走"重新创建"路径
        if ($row->template_code !== '') {
            try {
                $manager = SmsDriverFactory::manager($provider);
                $manager->modifyTemplate((string) $row->template_code, $newData);
                $newData['audit_status'] = SmsTemplate::AUDIT_PENDING;
                $newData['audit_reason'] = null;
                $newData['last_synced_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $newData['audit_reason'] = $e->getMessage();
            }
        } else {
            try {
                $manager = SmsDriverFactory::manager($provider);
                $remote = $manager->addTemplate($newData);
                $newData['template_code'] = $remote['template_code'];
                $newData['audit_status'] = SmsTemplate::AUDIT_PENDING;
                $newData['audit_reason'] = null;
                $newData['last_synced_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $newData['audit_reason'] = $e->getMessage();
            }
        }

        $row->save($newData);
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }

        if ($row->template_code !== '') {
            $provider = SmsProvider::find($row->provider_id);
            if ($provider !== null) {
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

    public function syncStatus(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        if ($row->template_code === '') {
            throw new BusinessException('模板未提交远端,无法查询状态');
        }
        $provider = SmsProvider::find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        try {
            $manager = SmsDriverFactory::manager($provider);
            $remote = $manager->queryTemplate((string) $row->template_code);
            $row->audit_status = $remote['audit_status'];
            $row->audit_reason = $remote['audit_reason'];
            $row->last_synced_at = date('Y-m-d H:i:s');
            $row->save();
            return $row->toArray();
        } catch (Throwable $e) {
            throw new BusinessException('同步失败: ' . $e->getMessage());
        }
    }

    public function syncAll(int $providerId): array
    {
        $rows = $this->model()->where('provider_id', $providerId)->where('template_code', '<>', '')->select();
        $success = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->syncStatus((int) $row->id);
                $success++;
            } catch (Throwable) {
                $failed++;
            }
        }
        return ['success' => $success, 'failed' => $failed];
    }
}
