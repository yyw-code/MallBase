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
        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('sign_name', "%{$where['keyword']}%");
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
            throw new BusinessException('签名不存在');
        }
        return $row->toArray();
    }

    /**
     * 本地登记 PNVS 系统赠送签名
     *
     * 业务约束:
     *  - 仅 PNVS(无远端管理 API)的服务商支持新增签名,且仅做本地登记
     *  - 普通阿里云/腾讯等渠道的签名必须通过 importFromRemote 从平台拉已审核记录
     *  - 控制台已审核通过的签名 → 走 importFromRemote;尚未审核的 → 用户先去阿里云控制台申请
     *
     * @param array{
     *     provider_id:int,
     *     sign_name:string,
     *     remark?:string
     * } $data
     */
    public function create(array $data): int
    {
        $provider = $this->model(SmsProvider::class)->find($data['provider_id']);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        if (SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException(
                '签名仅支持 PNVS 服务商本地登记;其他渠道的签名请在阿里云控制台申请通过后,使用「导入已审核签名」功能拉到本地'
            );
        }

        $row = $this->model();
        $row->save([
            'provider_id' => (int) $data['provider_id'],
            'sign_name' => trim($data['sign_name']),
            'sign_source' => 0,
            'sign_type' => 1,
            'remark' => $data['remark'] ?? null,
            'audit_status' => SmsSign::AUDIT_LOCAL_ONLY,
            'audit_reason' => 'PNVS 系统赠送签名,无需远端审核',
        ]);
        return (int) $row->id;
    }

    /**
     * 从阿里云导入已经存在并审核通过的签名(只查询,不调 AddSmsSign)
     *
     * 适用场景:
     *  - 你在阿里云控制台已经申请并审核通过了某签名,想接入到 mallbase
     *  - 旧线上数据迁移
     *
     * PNVS 服务商不支持导入:其签名由平台预置无 QuerySmsSign API,请走 create() 本地登记
     */
    public function importFromRemote(int $providerId, string $signName): int
    {
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('PNVS 服务商不支持导入,请使用「新增签名」在本地登记');
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

        $provider = $this->model(SmsProvider::class)->find($row->provider_id);
        if ($provider !== null && SmsDriverFactory::supportsRemoteSignManagement($provider)) {
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
        $provider = $this->model(SmsProvider::class)->find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('PNVS 签名为系统赠送,无需同步');
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
