<?php
declare(strict_types=1);

namespace app\service\admin\logistics;

use app\model\logistics\LogisticsCompany;
use app\model\logistics\LogisticsPlatform;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 物流公司目录服务
 *
 * @extends BaseService<LogisticsCompany>
 */
class LogisticsCompanyService extends BaseService
{
    protected string $modelClass = LogisticsCompany::class;

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('total', 'list');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function options(string $platform = ''): array
    {
        $platform = $platform !== '' ? $platform : $this->defaultPlatformCode();
        if ($platform === '') {
            return [];
        }

        $rows = $this->model()
            ->where('platform', $platform)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->field('id, platform, code, name')
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => [
            'id'       => (int) ($row['id'] ?? 0),
            'platform' => (string) ($row['platform'] ?? ''),
            'label'    => (string) ($row['name'] ?? ''),
            'value'    => (int) ($row['id'] ?? 0),
            'code'     => (string) ($row['code'] ?? ''),
            'name'     => (string) ($row['name'] ?? ''),
        ], $rows);
    }

    public function updateStatus(int $id, int $status): void
    {
        /** @var LogisticsCompany|null $row */
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('物流公司不存在');
        }

        $row->save(['status' => $status === 1 ? 1 : 0]);
    }

    /**
     * 保存平台物流公司，id 为空时创建。
     */
    public function saveCompany(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        /** @var LogisticsCompany|null $row */
        $row = $id > 0 ? $this->model()->find($id) : null;
        if ($id > 0 && $row === null) {
            throw new BusinessException('物流公司不存在');
        }

        $payload = $this->normalizeCompany($data, $row);

        if ($row === null) {
            /** @var LogisticsCompany $created */
            $created = $this->model()->create($payload);
            return (int) $created->id;
        }

        $row->save($payload);
        return (int) $row->id;
    }

    public function deleteCompany(int $id): void
    {
        /** @var LogisticsCompany|null $row */
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('物流公司不存在');
        }

        $row->delete();
    }

    /**
     * 发货时解析平台公司，返回订单快照字段。
     *
     * @return array{platform:string,company_id:int,code:string,name:string}
     */
    public function resolveCompany(
        string $platform,
        int $companyId,
        string $companyCode = '',
        string $companyName = ''
    ): array {
        $platform = trim($platform) !== '' ? trim($platform) : $this->defaultPlatformCode();
        if ($platform === '') {
            throw new BusinessException('请先启用物流平台');
        }

        $query = $this->model()->where('platform', $platform)->where('status', 1);
        if ($companyId > 0) {
            $query->where('id', $companyId);
        } elseif (trim($companyCode) !== '') {
            $query->where('code', trim($companyCode));
        } elseif (trim($companyName) !== '') {
            $query->where('name', trim($companyName));
        } else {
            throw new BusinessException('请选择物流公司');
        }

        /** @var LogisticsCompany|null $company */
        $company = $query->find();
        if ($company === null) {
            throw new BusinessException('物流公司不存在或已停用');
        }

        return [
            'platform'   => (string) $company->platform,
            'company_id' => (int) $company->id,
            'code'       => (string) $company->code,
            'name'       => (string) $company->name,
        ];
    }

    private function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['platform']), function ($q) use ($where): void {
                $q->where('platform', trim((string) $where['platform']));
            })
            ->when(!empty($where['keyword']), function ($q) use ($where): void {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('name|code|remark', "%{$keyword}%");
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where): void {
                $q->where('status', (int) $where['status']);
            });
    }

    private function defaultPlatformCode(): string
    {
        /** @var LogisticsPlatform|null $platform */
        $platform = $this->model(LogisticsPlatform::class)
            ->where('status', 1)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find();

        return $platform === null ? '' : (string) $platform->code;
    }

    private function assertPlatform(string $platform): string
    {
        $platform = trim($platform);
        if ($platform === '') {
            throw new BusinessException('请选择物流平台');
        }

        /** @var LogisticsPlatform|null $platformRow */
        $platformRow = $this->model(LogisticsPlatform::class)->where('code', $platform)->find();
        if ($platformRow === null) {
            throw new BusinessException('物流平台不存在');
        }

        return $platform;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeCompany(array $data, ?LogisticsCompany $row): array
    {
        $id = (int) ($row->id ?? 0);
        $platform = $this->assertPlatform((string) ($data['platform'] ?? ($row->platform ?? '')));
        $code = trim((string) ($data['code'] ?? ($row->code ?? '')));
        $name = trim((string) ($data['name'] ?? ($row->name ?? '')));

        if ($code === '') {
            throw new BusinessException('物流公司编码不能为空');
        }
        if ($name === '') {
            throw new BusinessException('物流公司名称不能为空');
        }

        $exists = $this->model()
            ->where('platform', $platform)
            ->where('code', $code)
            ->when($id > 0, fn($q) => $q->where('id', '<>', $id))
            ->find();
        if ($exists !== null) {
            throw new BusinessException('当前平台下物流公司编码已存在');
        }

        return [
            'platform'     => mb_substr($platform, 0, 32),
            'code'         => mb_substr($code, 0, 64),
            'name'         => mb_substr($name, 0, 100),
            'remark'       => mb_substr(trim((string) ($data['remark'] ?? ($row->remark ?? ''))), 0, 255),
            'status'       => (int) ($data['status'] ?? ($row->status ?? 1)) === 1 ? 1 : 0,
            'sort'         => (int) ($data['sort'] ?? ($row->sort ?? 0)),
            'raw_snapshot' => ['source' => 'manual'],
        ];
    }
}
