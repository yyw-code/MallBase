<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSign;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

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

    public function importLocal(array $data): int
    {
        $providerId = (int) ($data['provider_id'] ?? 0);
        $signName = trim((string) ($data['sign_name'] ?? ''));
        $provider = $this->model(SmsProvider::class)->find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if ($signName === '') {
            throw new BusinessException('签名名称不能为空');
        }

        $exists = $this->model()
            ->where('provider_id', $providerId)
            ->where('sign_name', $signName)
            ->find();
        if ($exists !== null) {
            throw new BusinessException("签名 [{$signName}] 已存在本地");
        }

        $row = $this->model();
        $row->save([
            'provider_id' => $providerId,
            'sign_name' => $signName,
            'sign_source' => 0,
            'sign_type' => 1,
            'remark' => trim((string) ($data['remark'] ?? '')) ?: null,
            'qualification_id' => null,
            'audit_status' => SmsSign::AUDIT_LOCAL_ONLY,
            'audit_reason' => null,
            'last_synced_at' => null,
        ]);
        return (int) $row->id;
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('签名不存在');
        }

        $row->delete();
    }
}
