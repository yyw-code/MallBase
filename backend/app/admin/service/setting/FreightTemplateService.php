<?php
declare(strict_types=1);

namespace app\admin\service\setting;

use app\admin\model\setting\FreightTemplate;
use app\admin\model\setting\FreightTemplateRule;
use app\service\RegionResolverService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * @extends BaseService<FreightTemplate>
 */
class FreightTemplateService extends BaseService
{
    protected string $modelClass = FreightTemplate::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['name']), function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['name'] . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)->order('id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$item) {
            $rules = $this->model(FreightTemplateRule::class)
                ->where('template_id', $item['id'])
                ->select()
                ->toArray();
            $ruleCount = count($rules);
            $invalidCount = 0;
            foreach ($rules as $rule) {
                $state = $this->refreshRuleRegionState($rule);
                if ((int) ($state['region_status'] ?? 0) !== 1) {
                    ++$invalidCount;
                }
            }
            $item['rule_count'] = $ruleCount;
            $item['invalid_rule_count'] = $invalidCount;
        }
        $total = $this->buildListQuery($where)->count();
        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        $data = $template->toArray();
        $rules = $this->model(FreightTemplateRule::class)
            ->where('template_id', $id)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rules as &$rule) {
            $rule = $this->refreshRuleRegionState($rule);
        }

        $data['rules'] = $rules;
        return $data;
    }

    public function create(array $data): int
    {
        return $this->transaction(function () use ($data) {
            $rules = $this->normalizeRules($data['rules'] ?? []);
            $template = $this->model()->create($this->extractTemplateData($data));
            $this->saveRules((int) $template->id, $rules);
            return (int) $template->id;
        });
    }

    public function update(int $id, array $data): bool
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        return $this->transaction(function () use ($template, $data, $id) {
            $rules = $this->normalizeRules($data['rules'] ?? []);
            $template->save($this->extractTemplateData($data));
            $this->model(FreightTemplateRule::class)->where('template_id', $id)->delete();
            $this->saveRules($id, $rules);
            return true;
        });
    }

    public function delete(int $id): bool
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        return $this->transaction(function () use ($template, $id) {
            $this->model(FreightTemplateRule::class)->where('template_id', $id)->delete();
            return (bool) $template->delete();
        });
    }

    public function updateStatus(int $id, int $status): bool
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        $template->save(['status' => $status]);
        return true;
    }

    /**
     * 批量重匹配失效规则
     *
     * @return array<string, int>
     */
    public function refreshInvalidData(): array
    {
        $rules = $this->model(FreightTemplateRule::class)
            ->order('template_id', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select();

        $grouped = [];
        foreach ($rules as $rule) {
            $grouped[(int) $rule->template_id][] = $rule;
        }

        $total = 0;
        $recovered = 0;
        $invalid = 0;
        $resolver = app()->make(RegionResolverService::class);
        $levelLabel = [1 => '省', 2 => '市', 3 => '区县', 4 => '街道'];

        foreach ($grouped as $templateRules) {
            $usedByLevel = [1 => [], 2 => [], 3 => [], 4 => []];
            foreach ($templateRules as $rule) {
                ++$total;
                $before = $rule->toArray();
                $state = $resolver->getRegionRuleState($before);

                if (!($state['valid'] ?? false)) {
                    $rule->save([
                        'region_status' => 0,
                        'region_invalid_reason' => $state['reason'] ?? '规则包含已失效区域，请重新选择',
                    ]);
                    ++$invalid;
                    continue;
                }

                $payload = (array) ($state['data'] ?? []);
                $items = (array) ($payload['items'] ?? []);
                $conflictReason = null;
                foreach ($items as $item) {
                    $level = (int) ($item['level'] ?? 0);
                    $id = (int) ($item['id'] ?? 0);
                    if ($level < 1 || $level > 4 || $id <= 0) {
                        continue;
                    }
                    if (in_array($id, $usedByLevel[$level] ?? [], true)) {
                        $conflictReason = sprintf(
                            '重匹配后与其它规则在%s层级重复',
                            $levelLabel[$level] ?? '区域'
                        );
                        break;
                    }
                }

                if ($conflictReason !== null) {
                    $rule->save([
                        'region_status' => 0,
                        'region_invalid_reason' => $conflictReason,
                    ]);
                    ++$invalid;
                    continue;
                }

                $matchedIds = array_map('intval', (array) ($payload['region_ids'] ?? []));
                $matchLevel = (int) ($payload['match_level'] ?? 0);
                $beforeIds = array_map('intval', (array) ($before['region_ids'] ?? []));
                $changed = (int) ($before['region_status'] ?? 0) !== 1
                    || $beforeIds !== $matchedIds
                    || (int) ($before['match_level'] ?? 0) !== $matchLevel
                    || (string) ($before['region_invalid_reason'] ?? '') !== '';

                $rule->save([
                    'region_ids' => $payload['region_ids'],
                    'region_codes' => $payload['region_codes'],
                    'region_names' => $payload['region_names'],
                    'region_path_texts' => $payload['region_path_texts'],
                    'match_level' => $matchLevel,
                    'region_status' => 1,
                    'region_invalid_reason' => null,
                ]);

                foreach ($items as $item) {
                    $level = (int) ($item['level'] ?? 0);
                    $id = (int) ($item['id'] ?? 0);
                    if ($level < 1 || $level > 4 || $id <= 0) {
                        continue;
                    }
                    $usedByLevel[$level][] = $id;
                }

                if ($changed) {
                    ++$recovered;
                }
            }
        }

        return compact('total', 'recovered', 'invalid');
    }

    /**
     * 根据地区子树标记规则失效
     *
     * @param array<int, int> $regionIds
     */
    public function invalidateRulesByRegionIds(array $regionIds, string $reason): int
    {
        $regionIds = array_values(array_unique(array_map('intval', $regionIds)));
        if ($regionIds === []) {
            return 0;
        }

        $rules = $this->model(FreightTemplateRule::class)->select();
        $affectedIds = [];
        foreach ($rules as $rule) {
            $currentIds = array_map('intval', (array) ($rule->region_ids ?? []));
            if (array_intersect($regionIds, $currentIds) !== []) {
                $affectedIds[] = (int) $rule->id;
            }
        }

        if ($affectedIds === []) {
            return 0;
        }

        return $this->model(FreightTemplateRule::class)
            ->whereIn('id', $affectedIds)
            ->update([
                'region_status' => 0,
                'region_invalid_reason' => $reason,
            ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRules(array $rules): array
    {
        $result = [];
        $usedByLevel = [1 => [], 2 => [], 3 => [], 4 => []];
        $levelLabel = [1 => '省', 2 => '市', 3 => '区县', 4 => '街道'];

        foreach ($rules as $index => $rule) {
            $normalizedRegions = app()->make(RegionResolverService::class)
                ->normalizeRegionSelections((array) ($rule['region_ids'] ?? []));

            foreach ($normalizedRegions['items'] as $item) {
                $level = (int) $item['level'];
                $id = (int) $item['id'];
                if (in_array($id, $usedByLevel[$level] ?? [], true)) {
                    throw new BusinessException(sprintf(
                        '同一%s地区不能跨规则重复配置',
                        $levelLabel[$level] ?? '区域'
                    ));
                }
                $usedByLevel[$level][] = $id;
            }

            $result[] = [
                'region_ids' => $normalizedRegions['region_ids'],
                'region_codes' => $normalizedRegions['region_codes'],
                'region_names' => $normalizedRegions['region_names'],
                'region_path_texts' => $normalizedRegions['region_path_texts'],
                'match_level' => (int) $normalizedRegions['match_level'],
                'region_status' => 1,
                'region_invalid_reason' => null,
                'first_amount' => (float) ($rule['first_amount'] ?? 1),
                'first_fee' => (float) ($rule['first_fee'] ?? 0),
                'continue_amount' => (float) ($rule['continue_amount'] ?? 1),
                'continue_fee' => (float) ($rule['continue_fee'] ?? 0),
                'sort' => (int) ($rule['sort'] ?? $index),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function extractTemplateData(array $data): array
    {
        return [
            'name' => $data['name'],
            'charge_type' => $data['charge_type'],
            'default_first_amount' => $data['default_first_amount'],
            'default_first_fee' => $data['default_first_fee'],
            'default_continue_amount' => $data['default_continue_amount'],
            'default_continue_fee' => $data['default_continue_fee'],
            'status' => $data['status'] ?? 1,
            'remark' => $data['remark'] ?? '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    protected function saveRules(int $templateId, array $rules): void
    {
        foreach ($rules as $rule) {
            $this->model(FreightTemplateRule::class)->create(array_merge($rule, [
                'template_id' => $templateId,
            ]));
        }
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    protected function refreshRuleRegionState(array $rule): array
    {
        $state = app()->make(RegionResolverService::class)->getRegionRuleState($rule);
        if (!($state['valid'] ?? false)) {
            $rule['region_status'] = 0;
            $rule['region_invalid_reason'] = $state['reason'] ?? '规则未配置区域';
            return $rule;
        }

        $rule['region_status'] = 1;
        $rule['region_invalid_reason'] = null;
        return $rule;
    }
}
