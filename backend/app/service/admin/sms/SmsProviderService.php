<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\service\sms\SmsSecret;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 短信服务商 Service
 *
 * @extends BaseService<SmsProvider>
 */
class SmsProviderService extends BaseService
{
    protected string $modelClass = SmsProvider::class;

    /**
     * 分页列表
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name', "%{$where['keyword']}%");
            })
            ->when(!empty($where['driver']), function ($q) use ($where) {
                $q->where('driver', $where['driver']);
            })
            ->when(isset($where['status']) && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });

        $total = $query->count();
        $list = $query->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 列表不返回明文 secret
        foreach ($list as &$row) {
            $row['access_key_secret_set'] = $row['access_key_secret'] !== '';
            unset($row['access_key_secret']);
        }

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('服务商不存在');
        }
        $data = $row->toArray();
        $data['access_key_secret_set'] = $data['access_key_secret'] !== '';
        unset($data['access_key_secret']);
        return $data;
    }

    public function create(array $data): int
    {
        $payload = $this->normalize($data, isCreate: true);

        return $this->transaction(function () use ($payload) {
            if (($payload['is_default'] ?? 0) === 1) {
                $this->model()->where('is_default', 1)->update(['is_default' => 0]);
            }
            $row = $this->model();
            $row->save($payload);
            return (int) $row->id;
        });
    }

    public function update(int $id, array $data): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('服务商不存在');
        }

        $payload = $this->normalize($data, isCreate: false);

        $this->transaction(function () use ($row, $payload, $id) {
            if (($payload['is_default'] ?? 0) === 1) {
                $this->model()->where('is_default', 1)->where('id', '<>', $id)->update(['is_default' => 0]);
            }
            // 空 access_key_secret 表示不修改
            if (($payload['access_key_secret'] ?? null) === '') {
                unset($payload['access_key_secret']);
            }
            $row->save($payload);
        });
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('服务商不存在');
        }
        $row->delete();
    }

    /**
     * 连通性测试:用当前配置去查询一个不存在的签名,通过远端是否报凭证错误来判断
     */
    public function testConnection(int $id): array
    {
        $provider = $this->model()->find($id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        try {
            $manager = SmsDriverFactory::manager($provider);
            // 用不存在的签名探测,若返回 OK 或 SignNotExist 等业务错误,说明凭证可用
            $manager->querySign('__mb_connection_probe__');
            return ['ok' => true, 'message' => '凭证可用'];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            // 阿里云对"签名不存在"返回业务错误码,这恰好证明凭证有效
            if (str_contains($message, 'NotFound') || str_contains($message, 'SignNotExist') || str_contains($message, '不存在')) {
                return ['ok' => true, 'message' => '凭证可用(签名探测正常)'];
            }
            return ['ok' => false, 'message' => $message];
        }
    }

    /**
     * 入参清洗 + 凭证加密
     */
    private function normalize(array $data, bool $isCreate): array
    {
        $allowed = ['name', 'driver', 'access_key_id', 'access_key_secret', 'region', 'is_default', 'status', 'remark', 'sort'];
        $payload = array_intersect_key($data, array_flip($allowed));

        $payload['region'] = $payload['region'] ?? 'cn-hangzhou';
        $payload['is_default'] = (int) ($payload['is_default'] ?? 0);
        $payload['status'] = (int) ($payload['status'] ?? 1);
        $payload['sort'] = (int) ($payload['sort'] ?? 0);

        $secret = $payload['access_key_secret'] ?? '';
        if ($secret !== '' && !SmsSecret::isEncrypted($secret)) {
            $payload['access_key_secret'] = SmsSecret::encrypt($secret);
        }

        if ($isCreate && empty($payload['name'])) {
            throw new BusinessException('服务商名称不能为空');
        }

        return $payload;
    }
}
