<?php
declare(strict_types=1);

namespace app\service\admin\marketing;

use app\model\marketing\PointsRule;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 积分规则服务
 *
 * @extends BaseService<PointsRule>
 */
class PointsRuleService extends BaseService
{
    protected string $modelClass = PointsRule::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['keyword'] ?? '') !== '', function ($q) use ($where) {
                $q->whereLike('name|scene', '%' . $where['keyword'] . '%');
            })
            ->when(($where['scene'] ?? '') !== '', function ($q) use ($where) {
                $q->where('scene', (string) $where['scene']);
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
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row): array => $this->formatRow($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);
        if (!$info) {
            throw new BusinessException('积分规则不存在');
        }

        return $this->formatRow($info->toArray());
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);

        if ($this->sceneExists($payload['scene'])) {
            throw new BusinessException('该规则场景已存在');
        }

        /** @var PointsRule $rule */
        $rule = $this->model();
        $rule->save($payload);

        return (int) $rule->id;
    }

    public function update(int $id, array $data): bool
    {
        /** @var PointsRule|null $rule */
        $rule = $this->model()->find($id);
        if (!$rule) {
            throw new BusinessException('积分规则不存在');
        }

        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);

        if ($this->sceneExists($payload['scene'], $id)) {
            throw new BusinessException('该规则场景已存在');
        }

        $rule->save($payload);

        return true;
    }

    public function delete(int $id): bool
    {
        /** @var PointsRule|null $rule */
        $rule = $this->model()->find($id);
        if (!$rule) {
            throw new BusinessException('积分规则不存在');
        }
        if ((string) $rule->scene === PointsRule::SCENE_ORDER_COMPLETE) {
            throw new BusinessException('消费返积分规则不能删除，可停用');
        }

        $rule->delete();

        return true;
    }

    public function updateStatus(int $id, int $status): bool
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }

        /** @var PointsRule|null $rule */
        $rule = $this->model()->find($id);
        if (!$rule) {
            throw new BusinessException('积分规则不存在');
        }

        $rule->save(['status' => $status]);

        return true;
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    public function sceneOptions(): array
    {
        return PointsRule::sceneOptions();
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePayload(array $data): array
    {
        return [
            'scene' => trim((string) ($data['scene'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'points_per_yuan' => max(0, (int) ($data['points_per_yuan'] ?? 0)),
            'fixed_points' => max(0, (int) ($data['fixed_points'] ?? 0)),
            'max_points' => max(0, (int) ($data['max_points'] ?? 0)),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => mb_substr(trim((string) ($data['remark'] ?? '')), 0, 255),
        ];
    }

    private function validatePayload(array $payload): void
    {
        $validScenes = array_column(PointsRule::sceneOptions(), 'value');
        if (!in_array($payload['scene'], $validScenes, true)) {
            throw new BusinessException('规则场景不合法');
        }
        if ($payload['name'] === '') {
            throw new BusinessException('规则名称不能为空');
        }
        if (!in_array($payload['status'], [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }
        if ($payload['scene'] === PointsRule::SCENE_ORDER_COMPLETE && (int) $payload['points_per_yuan'] <= 0) {
            throw new BusinessException('消费返积分规则每元奖励积分必须大于 0');
        }
        if ($payload['scene'] !== PointsRule::SCENE_ORDER_COMPLETE && (int) $payload['fixed_points'] <= 0) {
            throw new BusinessException('固定奖励积分必须大于 0');
        }
    }

    private function sceneExists(string $scene, int $excludeId = 0): bool
    {
        return $this->model()
            ->where('scene', $scene)
            ->when($excludeId > 0, function ($q) use ($excludeId) {
                $q->where('id', '<>', $excludeId);
            })
            ->count() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatRow(array $row): array
    {
        $scene = (string) ($row['scene'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'scene' => $scene,
            'scene_text' => PointsRule::sceneText($scene),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'points_per_yuan' => (int) ($row['points_per_yuan'] ?? 0),
            'fixed_points' => (int) ($row['fixed_points'] ?? 0),
            'max_points' => (int) ($row['max_points'] ?? 0),
            'sort' => (int) ($row['sort'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }
}
