<?php
declare(strict_types=1);

namespace app\service\admin\logistics;

use app\model\logistics\LogisticsTrack;
use app\model\logistics\LogisticsPlatform;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 物流平台配置服务
 *
 * @extends BaseService<LogisticsPlatform>
 */
class LogisticsPlatformService extends BaseService
{
    protected string $modelClass = LogisticsPlatform::class;

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

        $list = array_map(fn(array $row): array => $this->maskConfig($row), $list);

        return compact('total', 'list');
    }

    /**
     * 发货弹窗可用平台选项。
     *
     * @return array<int,array<string,mixed>>
     */
    public function enabledOptions(): array
    {
        return $this->model()
            ->where('status', 1)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->field('id, code, name, driver, is_default, cache_minutes')
            ->select()
            ->toArray();
    }

    /**
     * 保存平台配置，id 为空时创建。
     */
    public function savePlatform(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        /** @var LogisticsPlatform|null $row */
        $row = $id > 0 ? $this->model()->find($id) : null;
        if ($id > 0 && $row === null) {
            throw new BusinessException('物流平台不存在');
        }

        $payload = $this->normalize($data, $row);

        return $this->transaction(function () use ($row, $payload, $id): int {
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->model()
                    ->where('is_default', 1)
                    ->when($id > 0, fn($q) => $q->where('id', '<>', $id))
                    ->update(['is_default' => 0]);
            }

            if ($row === null) {
                /** @var LogisticsPlatform $created */
                $created = $this->model()->create($payload);
                return (int) $created->id;
            }

            $row->save($payload);
            return (int) $row->id;
        });
    }

    /**
     * 清理选中平台的轨迹查询缓存，保留现有轨迹快照。
     */
    public function clearCache(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id): int => (int) $id, $ids),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            throw new BusinessException('请选择物流平台');
        }

        $platformCodes = $this->model()
            ->whereIn('id', $ids)
            ->column('code');
        if ($platformCodes === []) {
            throw new BusinessException('物流平台不存在');
        }

        return (int) $this->model(LogisticsTrack::class)
            ->whereIn('provider', $platformCodes)
            ->update([
                'is_signed'     => 0,
                'last_query_at' => null,
                'next_query_at' => null,
                'last_error'    => null,
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where): void {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('name|code', "%{$keyword}%");
            })
            ->when(!empty($where['driver']), function ($q) use ($where): void {
                $q->where('driver', (string) $where['driver']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where): void {
                $q->where('status', (int) $where['status']);
            });
    }

    /**
     * @return array<string,mixed>
     */
    private function normalize(array $data, ?LogisticsPlatform $row): array
    {
        $isCreate = $row === null;
        $code = trim((string) ($data['code'] ?? ($row->code ?? '')));
        $name = trim((string) ($data['name'] ?? ($row->name ?? '')));
        $driver = trim((string) ($data['driver'] ?? ($row->driver ?? '')));

        if ($code === '') {
            throw new BusinessException('平台编码不能为空');
        }
        if ($name === '') {
            throw new BusinessException('平台名称不能为空');
        }
        if ($driver === '') {
            throw new BusinessException('平台驱动不能为空');
        }
        if ($driver !== LogisticsPlatform::DRIVER_KDNIAO) {
            throw new BusinessException('当前仅支持快递鸟物流平台');
        }

        if ($isCreate) {
            $exists = $this->model()->where('code', $code)->find();
            if ($exists !== null) {
                throw new BusinessException('平台编码已存在');
            }
        }

        $config = $this->normalizeConfig($data['config'] ?? [], $row, $driver);

        return [
            'code'          => mb_substr($code, 0, 32),
            'name'          => mb_substr($name, 0, 100),
            'driver'        => mb_substr($driver, 0, 32),
            'status'        => (int) ($data['status'] ?? ($row->status ?? 1)),
            'is_default'    => (int) ($data['is_default'] ?? ($row->is_default ?? 0)),
            'cache_minutes' => max(1, (int) ($data['cache_minutes'] ?? ($row->cache_minutes ?? 30))),
            'config'        => $config,
            'sort'          => (int) ($data['sort'] ?? ($row->sort ?? 0)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeConfig(mixed $value, ?LogisticsPlatform $row, string $driver): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        $config = is_array($value) ? $value : [];
        $oldConfig = is_array($row?->config ?? null) ? $row->config : [];

        foreach ($config as $key => $item) {
            if (is_string($item)) {
                $config[$key] = trim($item);
            }
        }

        if (array_key_exists('key', $config) && (string) $config['key'] === '' && !empty($oldConfig['key'])) {
            $config['key'] = (string) $oldConfig['key'];
        }

        if ($driver === LogisticsPlatform::DRIVER_KDNIAO) {
            $config['request_type'] = '8002';
            unset($config['map_fallback']);
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function maskConfig(array $row): array
    {
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        if (array_key_exists('key', $config)) {
            $row['key_set'] = trim((string) $config['key']) !== '';
            $config['key'] = '';
        } else {
            $row['key_set'] = false;
        }
        $row['config'] = $config;

        return $row;
    }
}
