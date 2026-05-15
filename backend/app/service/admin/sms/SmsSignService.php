<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSign;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * 短信签名 Service
 *
 * @extends BaseService<SmsSign>
 */
class SmsSignService extends BaseService
{
    protected string $modelClass = SmsSign::class;

    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('sign_name', "%{$where['keyword']}%");
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
            throw new BusinessException('签名不存在');
        }
        return $row->toArray();
    }

    /**
     * 创建本地记录 + 调用阿里云 AddSmsSign(把资质文件 base64 透传到 SignFileList)
     * 远端失败时本地仍入库,状态置为 local_only,便于用户后续修复重试
     *
     * @param array{
     *     provider_id:int,
     *     sign_name:string,
     *     sign_source:int,
     *     sign_type:int,
     *     remark?:string,
     *     qualification_id?:int,
     *     sign_files?:array<int, array{file_contents:string, file_suffix:string}>
     * } $data
     */
    public function create(array $data): int
    {
        $provider = SmsProvider::find($data['provider_id']);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $payload = [
            'provider_id' => (int) $data['provider_id'],
            'sign_name' => trim($data['sign_name']),
            'sign_source' => (int) ($data['sign_source'] ?? 0),
            'sign_type' => (int) ($data['sign_type'] ?? 1),
            'remark' => $data['remark'] ?? null,
            'qualification_id' => $data['qualification_id'] ?? null,
            'audit_status' => SmsSign::AUDIT_LOCAL_ONLY,
            'audit_reason' => null,
        ];

        try {
            $manager = SmsDriverFactory::manager($provider);
            $manager->addSign([
                'sign_name' => $payload['sign_name'],
                'sign_source' => $payload['sign_source'],
                'sign_type' => $payload['sign_type'],
                'remark' => $payload['remark'] ?? '',
                'sign_files' => $data['sign_files'] ?? [],
            ]);
            $payload['audit_status'] = SmsSign::AUDIT_PENDING;
            $payload['last_synced_at'] = date('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $payload['audit_reason'] = $e->getMessage();
        }

        $row = $this->model();
        $row->save($payload);
        return (int) $row->id;
    }

    /**
     * 从阿里云导入已经存在并审核通过的签名(只查询,不调 AddSmsSign)
     *
     * 适用场景:
     *  - 你在阿里云控制台已经申请并审核通过了某签名,想接入到 mallbase
     *  - 旧线上数据迁移
     */
    public function importFromRemote(int $providerId, string $signName): int
    {
        $provider = SmsProvider::find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $exists = $this->model()
            ->where('provider_id', $providerId)
            ->where('sign_name', $signName)
            ->find();
        if ($exists !== null) {
            throw new BusinessException("签名 [{$signName}] 已存在本地,请直接点击同步状态");
        }

        $manager = SmsDriverFactory::manager($provider);
        $remote = $manager->querySign($signName);

        $row = $this->model();
        $row->save([
            'provider_id' => $providerId,
            'sign_name' => $signName,
            'sign_source' => 0,
            'sign_type' => 1,
            'remark' => '从阿里云导入',
            'audit_status' => $remote['audit_status'],
            'audit_reason' => $remote['audit_reason'] ?? null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $row->id;
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('签名不存在');
        }

        $provider = SmsProvider::find($row->provider_id);
        if ($provider !== null) {
            try {
                $manager = SmsDriverFactory::manager($provider);
                $manager->deleteSign((string) $row->sign_name);
            } catch (Throwable) {
                // 远端删除失败不影响本地清理,但回写一个备注
                $row->audit_reason = '远端删除失败,已本地删除';
                $row->save();
            }
        }

        $row->delete();
    }

    public function syncStatus(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('签名不存在');
        }
        $provider = SmsProvider::find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        try {
            $manager = SmsDriverFactory::manager($provider);
            $remote = $manager->querySign((string) $row->sign_name);
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
        $rows = $this->model()->where('provider_id', $providerId)->select();
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
