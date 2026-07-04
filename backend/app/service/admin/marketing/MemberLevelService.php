<?php
declare(strict_types=1);

namespace app\service\admin\marketing;

use app\model\user\MemberLevel;
use app\model\user\UserMember;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 会员等级管理服务
 *
 * @extends BaseService<MemberLevel>
 */
class MemberLevelService extends BaseService
{
    protected string $modelClass = MemberLevel::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['keyword'] ?? '') !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['keyword'] . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->buildListQuery($where);
        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('growth_min', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map(fn(array $row): array => $this->formatRow($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function getInfo(int $id): array
    {
        /** @var MemberLevel|null $level */
        $level = $this->model()->find($id);
        if ($level === null) {
            throw new BusinessException('会员等级不存在');
        }

        return $this->formatRow($level->toArray());
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);

        /** @var MemberLevel $level */
        $level = $this->model();
        $level->save($payload);

        return (int) $level->id;
    }

    public function update(int $id, array $data): bool
    {
        /** @var MemberLevel|null $level */
        $level = $this->model()->find($id);
        if ($level === null) {
            throw new BusinessException('会员等级不存在');
        }

        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);
        $level->save($payload);
        $this->model(UserMember::class)
            ->where('level_id', $id)
            ->update(['level_name' => $payload['name']]);

        return true;
    }

    public function delete(int $id): bool
    {
        /** @var MemberLevel|null $level */
        $level = $this->model()->find($id);
        if ($level === null) {
            throw new BusinessException('会员等级不存在');
        }
        if ($this->model(UserMember::class)->where('level_id', $id)->count() > 0) {
            throw new BusinessException('该等级下已有会员，不能删除');
        }

        $level->delete();

        return true;
    }

    public function updateStatus(int $id, int $status): bool
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }

        /** @var MemberLevel|null $level */
        $level = $this->model()->find($id);
        if ($level === null) {
            throw new BusinessException('会员等级不存在');
        }

        $level->save(['status' => $status]);

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePayload(array $data): array
    {
        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 50),
            'growth_min' => max(0, (int) ($data['growth_min'] ?? 0)),
            'discount_percent' => number_format(max(0, min(100, (float) ($data['discount_percent'] ?? 100))), 2, '.', ''),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => mb_substr(trim((string) ($data['remark'] ?? '')), 0, 255),
        ];
    }

    private function validatePayload(array $payload): void
    {
        if ($payload['name'] === '') {
            throw new BusinessException('等级名称不能为空');
        }
        if (!in_array((int) $payload['status'], [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'growth_min' => (int) ($row['growth_min'] ?? 0),
            'discount_percent' => number_format((float) ($row['discount_percent'] ?? 100), 2, '.', ''),
            'sort' => (int) ($row['sort'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }
}
