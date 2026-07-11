<?php
declare(strict_types=1);

namespace app\service\distribution;

use app\model\distribution\DistributionCommissionRule;
use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionLevel;
use app\model\goods\Goods;
use mall_base\base\BaseService;

/**
 * 分销佣金计算器
 *
 * @extends BaseService<DistributionCommissionRule>
 */
class DistributionCommissionCalculator extends BaseService
{
    protected string $modelClass = DistributionCommissionRule::class;

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $settings
     * @return array{rate:string,amount_cents:int,rule_type:string,rule_id:int}
     */
    public function quote(array $item, DistributionDistributor $distributor, int $relationLevel, array $settings): array
    {
        $baseCents = max(0, $this->decimalToCents((string) ($item['pay_amount'] ?? '0.00')));
        if ($baseCents <= 0 || !in_array($relationLevel, [1, 2], true)) {
            return ['rate' => '0.00', 'amount_cents' => 0, 'rule_type' => 'none', 'rule_id' => 0];
        }
        $quantity = $this->normalizeQuantity($item['quantity'] ?? 1);

        $rule = $this->matchRule($item);
        if ($rule !== null) {
            if ((string) $rule->commission_type === DistributionCommissionRule::COMMISSION_TYPE_FIXED) {
                $fixedCents = $relationLevel === 1
                    ? (int) $rule->first_fixed_cents
                    : (int) $rule->second_fixed_cents;
                return $this->buildFixedQuote($fixedCents, $quantity, (string) $rule->target_type, (int) $rule->id);
            }
            $rate = $relationLevel === 1 ? (string) $rule->first_rate : (string) $rule->second_rate;
            return $this->buildQuote($baseCents, $rate, (string) $rule->target_type, (int) $rule->id);
        }

        $level = $this->activeLevel((int) $distributor->level_id);
        if ($level !== null) {
            $rate = $relationLevel === 1
                ? (string) $level->first_rate
                : (string) $level->second_rate;
            return $this->buildQuote($baseCents, $rate, 'level', (int) $level->id);
        }

        $rate = $relationLevel === 1
            ? (string) ($settings['global_first_rate'] ?? '0.00')
            : (string) ($settings['global_second_rate'] ?? '0.00');
        return $this->buildQuote($baseCents, $rate, 'global', 0);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function matchRule(array $item): ?DistributionCommissionRule
    {
        $skuId = (int) ($item['sku_id'] ?? 0);
        if ($skuId > 0) {
            $rule = $this->findRule(DistributionCommissionRule::TARGET_SKU, $skuId);
            if ($rule !== null) {
                return $rule;
            }
        }

        $goodsId = (int) ($item['goods_id'] ?? 0);
        if ($goodsId > 0) {
            $rule = $this->findRule(DistributionCommissionRule::TARGET_GOODS, $goodsId);
            if ($rule !== null) {
                return $rule;
            }

            $categoryId = $this->goodsCategoryId($goodsId);
            if ($categoryId > 0) {
                $rule = $this->findRule(DistributionCommissionRule::TARGET_CATEGORY, $categoryId);
                if ($rule !== null) {
                    return $rule;
                }
            }
        }

        return null;
    }

    private function findRule(string $targetType, int $targetId): ?DistributionCommissionRule
    {
        /** @var DistributionCommissionRule|null $rule */
        $rule = $this->model()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('status', 1)
            ->find();
        return $rule;
    }

    private function activeLevel(int $levelId): ?DistributionLevel
    {
        if ($levelId <= 0) {
            return null;
        }
        /** @var DistributionLevel|null $level */
        $level = $this->model(DistributionLevel::class)
            ->where('id', $levelId)
            ->where('status', 1)
            ->find();
        return $level;
    }

    private function goodsCategoryId(int $goodsId): int
    {
        return (int) $this->model(Goods::class)->where('id', $goodsId)->value('category_id');
    }

    /**
     * @return array{rate:string,amount_cents:int,rule_type:string,rule_id:int}
     */
    private function buildQuote(int $baseCents, string $rate, string $ruleType, int $ruleId): array
    {
        $rate = $this->normalizeRate($rate);
        $amount = (int) floor(($baseCents * (float) $rate / 100) + 0.5);

        return [
            'rate' => $rate,
            'amount_cents' => max(0, $amount),
            'rule_type' => $ruleType,
            'rule_id' => $ruleId,
        ];
    }

    /**
     * @return array{rate:string,amount_cents:int,rule_type:string,rule_id:int}
     */
    private function buildFixedQuote(int $fixedCents, int $quantity, string $ruleType, int $ruleId): array
    {
        return [
            'rate' => '0.00',
            'amount_cents' => max(0, $fixedCents) * $quantity,
            'rule_type' => $ruleType,
            'rule_id' => $ruleId,
        ];
    }

    private function normalizeQuantity(mixed $quantity): int
    {
        return is_numeric($quantity) ? max(1, (int) $quantity) : 1;
    }

    private function normalizeRate(string $rate): string
    {
        $value = max(0, min(100, (float) $rate));
        return number_format($value, 2, '.', '');
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return 0;
        }
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }
}
