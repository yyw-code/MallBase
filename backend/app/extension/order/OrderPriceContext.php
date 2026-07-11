<?php
declare(strict_types=1);

namespace app\extension\order;

use mall_base\exception\BusinessException;

/**
 * 订单价格扩展上下文。
 */
class OrderPriceContext
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $items;

    /**
     * @var array<int,string>
     */
    private array $itemDiscounts;

    /**
     * @var array<int,string>
     */
    private array $memberItemDiscounts;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $memberDiscount = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $pointsDeduction = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $pointsReward = null;

    private string $discountAmount = '0.00';
    private string $payAmount = '0.00';

    /**
     * @param array<int,array<string,mixed>> $items
     */
    public function __construct(
        private readonly int $userId,
        array $items,
        private readonly string $totalAmount,
        private readonly string $freightAmount,
        private readonly bool $usePoints = false,
        private readonly int $pointsUsed = 0,
    ) {
        $this->items = array_values($items);
        $this->itemDiscounts = array_fill(0, count($this->items), '0.00');
        $this->memberItemDiscounts = array_fill(0, count($this->items), '0.00');
        $this->recalculate();
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function totalAmount(): string
    {
        return $this->totalAmount;
    }

    public function freightAmount(): string
    {
        return $this->freightAmount;
    }

    public function usePoints(): bool
    {
        return $this->usePoints;
    }

    public function pointsUsed(): int
    {
        return $this->pointsUsed;
    }

    public function pointsEligibleAmount(): string
    {
        return $this->centsToDecimal(max(0, $this->decimalToCents($this->totalAmount) - $this->memberDiscountCents()));
    }

    /**
     * @param array<string,mixed> $quote
     */
    public function withMemberDiscount(array $quote): self
    {
        $this->memberDiscount = $quote;
        $this->memberItemDiscounts = $this->normalizeDiscounts($quote['item_discounts'] ?? []);
        $this->recalculate();

        return $this;
    }

    /**
     * @param array<string,mixed> $quote
     */
    public function withPointsDeduction(array $quote): self
    {
        $this->pointsDeduction = $quote;
        $this->recalculate();

        return $this;
    }

    /**
     * @param array<string,mixed> $quote
     */
    public function withPointsReward(array $quote): self
    {
        $this->pointsReward = $quote;

        return $this;
    }

    /**
     * @return array<int,array{source_index:int,goods_id:int,sku_id:int,pay_amount:string,quantity:int}>
     */
    public function pointsRewardQuoteItems(): array
    {
        $rows = [];
        foreach ($this->items as $index => $item) {
            $goodsId = (int) ($item['goods_id'] ?? 0);
            $skuId = (int) ($item['sku_id'] ?? 0);
            if ($goodsId <= 0 || $skuId <= 0) {
                continue;
            }

            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $subtotalCents = $this->itemSubtotalCents($item);
            $discountCents = min(
                $subtotalCents,
                $this->decimalToCents((string) ($this->itemDiscounts[$index] ?? '0.00'))
            );
            $rows[] = [
                'source_index' => $index,
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'pay_amount' => $this->centsToDecimal(max(0, $subtotalCents - $discountCents)),
                'quantity' => $quantity,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *   total_amount:string, freight_amount:string, discount_amount:string, pay_amount:string,
     *   item_discounts:array<int,string>, member_item_discounts?:array<int,string>,
     *   member_discount?:array<string,mixed>, points_deduction?:array<string,mixed>, points_reward?:array<string,mixed>
     * }
     */
    public function toArray(): array
    {
        $result = [
            'total_amount' => $this->totalAmount,
            'freight_amount' => $this->freightAmount,
            'discount_amount' => $this->discountAmount,
            'pay_amount' => $this->payAmount,
            'item_discounts' => $this->itemDiscounts,
        ];

        if ($this->memberDiscount !== null) {
            $result['member_discount'] = $this->memberDiscount;
            $result['member_item_discounts'] = $this->memberItemDiscounts;
        }
        if ($this->pointsDeduction !== null) {
            $result['points_deduction'] = $this->pointsDeduction;
        }
        if ($this->pointsReward !== null) {
            $result['points_reward'] = $this->pointsReward;
        }

        return $result;
    }

    private function recalculate(): void
    {
        $memberDiscountCents = $this->memberDiscountCents();
        $pointsDiscountCents = $this->pointsDiscountCents();

        $this->discountAmount = $this->centsToDecimal($memberDiscountCents + $pointsDiscountCents);
        $this->payAmount = bcsub(bcadd($this->totalAmount, $this->freightAmount, 2), $this->discountAmount, 2);
        if ($this->decimalToCents($this->payAmount) < 0) {
            $this->payAmount = '0.00';
        }

        $this->itemDiscounts = $this->mergeItemDiscounts($pointsDiscountCents);
    }

    private function memberDiscountCents(): int
    {
        return $this->decimalToCents((string) ($this->memberDiscount['discount_amount'] ?? '0.00'));
    }

    private function pointsDiscountCents(): int
    {
        return $this->decimalToCents((string) ($this->pointsDeduction['discount_amount'] ?? '0.00'));
    }

    /**
     * @return array<int,string>
     */
    private function mergeItemDiscounts(int $pointsDiscountCents): array
    {
        $memberDiscountCents = [];
        $pointBases = [];
        foreach ($this->items as $index => $item) {
            $subtotalCents = $this->itemSubtotalCents($item);
            $memberCents = min(
                $subtotalCents,
                $this->decimalToCents((string) ($this->memberItemDiscounts[$index] ?? '0.00'))
            );
            $memberDiscountCents[$index] = $memberCents;
            $pointBases[$index] = max(0, $subtotalCents - $memberCents);
        }

        $pointDiscounts = $this->allocateCentsByBases($pointBases, $pointsDiscountCents);
        $result = [];
        foreach ($this->items as $index => $item) {
            $subtotalCents = $this->itemSubtotalCents($item);
            $discountCents = min(
                $subtotalCents,
                ($memberDiscountCents[$index] ?? 0) + ($pointDiscounts[$index] ?? 0)
            );
            $result[$index] = $this->centsToDecimal($discountCents);
        }

        return $result;
    }

    /**
     * @param array<int,mixed> $discounts
     * @return array<int,string>
     */
    private function normalizeDiscounts(array $discounts): array
    {
        $result = array_fill(0, count($this->items), '0.00');
        foreach ($this->items as $index => $_item) {
            $result[$index] = (string) ($discounts[$index] ?? '0.00');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function itemSubtotalCents(array $item): int
    {
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        return $this->decimalToCents(bcmul((string) ($item['unit_price'] ?? '0.00'), (string) $quantity, 2));
    }

    /**
     * @param array<int,int> $bases
     * @return array<int,int>
     */
    private function allocateCentsByBases(array $bases, int $discountCents): array
    {
        $discountCents = max(0, $discountCents);
        $totalBase = array_sum(array_map(static fn (int $basis): int => max(0, $basis), $bases));
        if ($discountCents <= 0 || $totalBase <= 0) {
            return array_fill(0, count($bases), 0);
        }

        $discountCents = min($discountCents, $totalBase);
        $allocated = 0;
        $lastIndex = array_key_last($bases);
        $result = [];
        foreach ($bases as $index => $basis) {
            $basis = max(0, $basis);
            if ($index === $lastIndex) {
                $itemDiscount = max(0, $discountCents - $allocated);
            } else {
                $itemDiscount = intdiv($discountCents * $basis, $totalBase);
                $allocated += $itemDiscount;
            }
            $result[$index] = min($basis, $itemDiscount);
        }

        return $result;
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }

        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToDecimal(int $cents): string
    {
        $cents = max(0, $cents);
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
