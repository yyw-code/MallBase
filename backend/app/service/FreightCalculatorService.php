<?php

declare(strict_types=1);

namespace app\service;

use app\model\setting\FreightTemplate;
use app\model\setting\FreightTemplateRule;
use app\service\dto\FreightCalculationResult;
use app\service\dto\RegionPathDto;
use mall_base\exception\BusinessException;

/**
 * 运费计算服务
 *
 * 匹配优先级：街道(4) > 区(3) > 市(2) > 省(1) > 默认运费（模板 default_* 字段）
 *
 * 计费公式（按件/按重统一公式，单位取决于模板 charge_type）：
 *   fee = first_fee
 *       + ceil( max(0, totalCount - first_amount) / continue_amount ) * continue_fee
 *
 * @todo Phase 2: OrderService::calcFreight —— 订单/购物车接入时封装商品-模板-地址聚合逻辑
 */
class FreightCalculatorService
{
    /**
     * 计算运费（DB 加载 + 纯算法合一）
     *
     * - templateId=0 直接返回包邮结果
     * - 模板已停用抛 BusinessException
     * - totalCount < 0 视为 0（上层应在下单时保证 > 0）
     */
    public function calculate(
        int $templateId,
        RegionPathDto $regionPath,
        float $totalCount,
    ): FreightCalculationResult {
        if ($templateId === 0) {
            return FreightCalculationResult::free();
        }

        $template = FreightTemplate::find($templateId);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }
        if ((int) $template->status !== 1) {
            throw new BusinessException('运费模板已停用');
        }

        $rules = FreightTemplateRule::where('template_id', $templateId)
            ->where('region_status', 1)
            ->order('match_level', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->calculateWithRules(
            $template->toArray(),
            $rules,
            $regionPath,
            $totalCount,
        );
    }

    /**
     * 纯算法入口，便于单元测试
     *
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $rules
     */
    public function calculateWithRules(
        array $template,
        array $rules,
        RegionPathDto $regionPath,
        float $totalCount,
    ): FreightCalculationResult {
        $totalCount = max(0.0, $totalCount);
        $matched = $this->matchRule($rules, $regionPath);

        if ($matched === null) {
            $fee = $this->computeFee(
                firstAmount: (float) ($template['default_first_amount'] ?? 0),
                firstFee: (float) ($template['default_first_fee'] ?? 0),
                continueAmount: (float) ($template['default_continue_amount'] ?? 0),
                continueFee: (float) ($template['default_continue_fee'] ?? 0),
                totalCount: $totalCount,
            );
            return FreightCalculationResult::default($fee);
        }

        $fee = $this->computeFee(
            firstAmount: (float) ($matched['first_amount'] ?? 0),
            firstFee: (float) ($matched['first_fee'] ?? 0),
            continueAmount: (float) ($matched['continue_amount'] ?? 0),
            continueFee: (float) ($matched['continue_fee'] ?? 0),
            totalCount: $totalCount,
        );

        return FreightCalculationResult::rule(
            fee: $fee,
            ruleId: (int) ($matched['id'] ?? 0),
            level: (int) ($matched['match_level'] ?? 0),
        );
    }

    /**
     * 按 4->3->2->1 层级回退查找首个命中的规则
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>|null
     */
    protected function matchRule(array $rules, RegionPathDto $regionPath): ?array
    {
        for ($level = 4; $level >= 1; $level--) {
            $targetId = $regionPath->idByLevel($level);
            if ($targetId <= 0) {
                continue;
            }

            foreach ($rules as $rule) {
                if ((int) ($rule['match_level'] ?? 0) < $level) {
                    // match_level 为规则内最深层级，低于当前层级时该规则不可能包含该层级 ID
                    continue;
                }
                $regionIds = array_map('intval', (array) ($rule['region_ids'] ?? []));
                if (in_array($targetId, $regionIds, true)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /**
     * 计费公式：fee = firstFee + ceil(max(0, count - firstAmount) / continueAmount) * continueFee
     *
     * 守护：
     * - firstAmount <= 0 时视为 0（首件/首重不限量）
     * - continueAmount <= 0 时视为不续费，只收 firstFee
     * - 结果向上取整到分（0.01）
     */
    protected function computeFee(
        float $firstAmount,
        float $firstFee,
        float $continueAmount,
        float $continueFee,
        float $totalCount,
    ): float {
        $totalUnits = $this->decimalToUnits(max(0.0, $totalCount), 3);
        $firstUnits = $this->decimalToUnits(max(0.0, $firstAmount), 3);
        $continueUnits = $this->decimalToUnits(max(0.0, $continueAmount), 3);
        $firstFeeCents = $this->decimalToCents(max(0.0, $firstFee));
        $continueFeeCents = $this->decimalToCents(max(0.0, $continueFee));

        if ($totalUnits <= $firstUnits) {
            return $this->centsToFloat($firstFeeCents);
        }

        if ($continueUnits <= 0) {
            return $this->centsToFloat($firstFeeCents);
        }

        $extraUnits = $totalUnits - $firstUnits;
        $steps = intdiv($extraUnits + $continueUnits - 1, $continueUnits);
        return $this->centsToFloat($firstFeeCents + $steps * $continueFeeCents);
    }

    protected function decimalToCents(float|string|int $amount): int
    {
        return $this->decimalToUnits($amount, 2);
    }

    protected function decimalToUnits(float|string|int $amount, int $scale): int
    {
        $scale = max(0, $scale);
        $value = is_float($amount) ? sprintf('%.6F', $amount) : trim((string) $amount);
        if ($value === '' || !preg_match('/^\d+(\.\d+)?$/', $value)) {
            return 0;
        }

        [$yuan, $cent] = array_pad(explode('.', $value, 2), 2, '0');
        $base = 10 ** $scale;
        $cent = str_pad($cent, $scale + 1, '0');
        $units = ((int) $yuan * $base) + (int) substr($cent, 0, $scale);

        return ((int) $cent[$scale] >= 5) ? $units + 1 : $units;
    }

    protected function centsToFloat(int $cents): float
    {
        return (float) sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
