<?php
declare(strict_types=1);

namespace Tests\Unit\Extension;

use app\extension\contracts\OrderPriceContributorInterface;
use app\extension\order\OrderPriceContext;
use app\extension\pipeline\OrderPricePipeline;
use app\extension\registry\ExtensionRegistry;
use app\service\user\UserMemberPriceContributor;
use app\service\user\UserPointsDeductionPriceContributor;
use app\service\user\UserPointsRewardPriceContributor;
use PHPUnit\Framework\TestCase;

final class OrderPricePipelineTest extends TestCase
{
    public function testPipelineRunsEnabledContributorsByPriority(): void
    {
        $calls = [];
        $pipeline = new OrderPricePipeline(new ExtensionRegistry(
            orderEventListeners: [],
            orderPriceContributors: [
                new FakeOrderPriceContributor('late', 30, true, $this->record($calls)),
                new FakeOrderPriceContributor('early', 10, true, $this->record($calls)),
                new FakeOrderPriceContributor('middle', 20, true, $this->record($calls)),
            ],
        ));

        $pipeline->apply($this->context());

        $this->assertSame(['early', 'middle', 'late'], $calls);
    }

    public function testPipelineSkipsDisabledContributors(): void
    {
        $calls = [];
        $pipeline = new OrderPricePipeline(new ExtensionRegistry(
            orderEventListeners: [],
            orderPriceContributors: [
                new FakeOrderPriceContributor('disabled', 10, false, $this->record($calls)),
                new FakeOrderPriceContributor('enabled', 20, true, $this->record($calls)),
            ],
        ));

        $pipeline->apply($this->context());

        $this->assertSame(['enabled'], $calls);
    }

    public function testBuiltInPriceContributorsKeepCurrentBusinessOrder(): void
    {
        $registry = new ExtensionRegistry(
            orderEventListeners: [],
            orderPriceContributors: [
                new UserPointsRewardPriceContributor(),
                new UserPointsDeductionPriceContributor(),
                new UserMemberPriceContributor(),
            ],
        );

        $this->assertSame(
            ['user_member.price', 'user_points.deduction', 'user_points.reward'],
            array_map(
                static fn (OrderPriceContributorInterface $contributor): string => $contributor->code(),
                $registry->orderPriceContributors()
            )
        );
    }

    public function testContextAppliesMemberBeforePointsAndBuildsRewardSnapshot(): void
    {
        $context = $this->context();
        $context->withMemberDiscount([
            'discount_amount' => '10.00',
            'item_discounts' => ['10.00', '0.00'],
        ]);

        $this->assertSame('90.00', $context->pointsEligibleAmount());

        $context->withPointsDeduction([
            'discount_amount' => '20.00',
        ]);
        $context->withPointsReward([
            'enabled' => true,
            'reward_points' => 10,
            'items' => [],
        ]);

        $result = $context->toArray();

        $this->assertSame('100.00', $result['total_amount']);
        $this->assertSame('10.00', $result['freight_amount']);
        $this->assertSame('30.00', $result['discount_amount']);
        $this->assertSame('80.00', $result['pay_amount']);
        $this->assertSame(['21.11', '8.89'], $result['item_discounts']);
        $this->assertSame(['10.00', '0.00'], $result['member_item_discounts']);
        $this->assertSame('10.00', $result['member_discount']['discount_amount']);
        $this->assertSame('20.00', $result['points_deduction']['discount_amount']);
        $this->assertSame(10, $result['points_reward']['reward_points']);

        $this->assertSame([
            [
                'source_index' => 0,
                'goods_id' => 10,
                'sku_id' => 100,
                'pay_amount' => '38.89',
                'quantity' => 1,
            ],
            [
                'source_index' => 1,
                'goods_id' => 20,
                'sku_id' => 200,
                'pay_amount' => '31.11',
                'quantity' => 2,
            ],
        ], $context->pointsRewardQuoteItems());
    }

    /**
     * @param array<int,string> $calls
     */
    private function record(array &$calls): \Closure
    {
        return static function (string $code) use (&$calls): void {
            $calls[] = $code;
        };
    }

    private function context(): OrderPriceContext
    {
        return new OrderPriceContext(
            userId: 1,
            items: [
                ['goods_id' => 10, 'sku_id' => 100, 'unit_price' => '60.00', 'quantity' => 1],
                ['goods_id' => 20, 'sku_id' => 200, 'unit_price' => '20.00', 'quantity' => 2],
            ],
            totalAmount: '100.00',
            freightAmount: '10.00',
            usePoints: true,
            pointsUsed: 2000,
        );
    }
}

final class FakeOrderPriceContributor implements OrderPriceContributorInterface
{
    public function __construct(
        private readonly string $code,
        private readonly int $priority,
        private readonly bool $enabled,
        private readonly \Closure $handler,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function enabled(OrderPriceContext $context): bool
    {
        return $this->enabled;
    }

    public function apply(OrderPriceContext $context): OrderPriceContext
    {
        ($this->handler)($this->code, $context);

        return $context;
    }
}
