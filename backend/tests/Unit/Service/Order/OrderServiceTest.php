<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\service\client\order\OrderService;
use app\service\dto\RegionPathDto;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 买家订单服务纯逻辑单元测试
 *
 * 覆盖（全部在 DB 访问之前生效的分支）：
 *  - calcAmounts      金额计算（bcmath 2 位精度，含运费汇总）
 *  - calcFreight      无 DB 分支：空 items / 全包邮组 → 运费 0
 *  - sumChargeCount   按件 / 按重（克→千克换算）计费数量累计
 *  - buildIdempotencyKey  幂等 key 装配（scope + 清洗 + 64 位截断 + 随机兜底）
 *  - assertUserId     登录校验
 *  - assertSaleable   SKU / Goods 可售校验
 *  - createFromSku    items 空数组的前置拒绝
 *  - DEFAULT_PAY_EXPIRE_SECONDS 契约
 *
 * 下单事务 / 幂等抢占 / 真实库存扣减路径依赖 MySQL + Redis，
 * 留到 OrderService 集成测试里通过真实数据库覆盖（见 CartServiceTest 同款分层）。
 * calcFreight 命中运费模板的真实计算（单模板、多模板分组、停用降级）依赖
 * FreightTemplate 表，归 Feature 层经 /client/api/order/preview 覆盖；
 * 运费算法本身已由 FreightCalculatorServiceTest 完整覆盖。
 */
final class OrderServiceTest extends TestCase
{
    private OrderService $service;

    protected function setUp(): void
    {
        $this->service = new OrderService();
    }

    // ------------------------- calcAmounts -------------------------

    public function testCalcAmountsWithEmptyItemsReturnsZeroes(): void
    {
        $result = $this->invokePrivate('calcAmounts', [[], $this->beijingPath()]);
        $this->assertSame(
            [
                'total_amount'    => '0.00',
                'freight_amount'  => '0.00',
                'discount_amount' => '0.00',
                'pay_amount'      => '0.00',
            ],
            $result,
        );
    }

    public function testCalcAmountsWithSingleItem(): void
    {
        $items = [
            ['unit_price' => '12.50', 'quantity' => 2],
        ];
        $result = $this->invokePrivate('calcAmounts', [$items, $this->beijingPath()]);

        $this->assertSame('25.00', $result['total_amount']);
        $this->assertSame('0.00', $result['freight_amount']);
        $this->assertSame('0.00', $result['discount_amount']);
        $this->assertSame('25.00', $result['pay_amount']);
    }

    public function testCalcAmountsWithMultipleItemsAccumulates(): void
    {
        $items = [
            ['unit_price' => '9.99',  'quantity' => 3],   // 29.97
            ['unit_price' => '100.00','quantity' => 1],   // 100.00
            ['unit_price' => '0.50',  'quantity' => 10],  // 5.00
        ];
        $result = $this->invokePrivate('calcAmounts', [$items, $this->beijingPath()]);

        // 29.97 + 100.00 + 5.00 = 134.97
        $this->assertSame('134.97', $result['total_amount']);
        $this->assertSame('134.97', $result['pay_amount']);
    }

    public function testCalcAmountsUsesBcmathTwoDecimalPrecision(): void
    {
        // 0.1 + 0.2 在 float 下是 0.30000000000000004，bcmath 必须保持 0.30
        $items = [
            ['unit_price' => '0.10', 'quantity' => 1],
            ['unit_price' => '0.20', 'quantity' => 1],
        ];
        $result = $this->invokePrivate('calcAmounts', [$items, $this->beijingPath()]);

        $this->assertSame('0.30', $result['total_amount']);
        $this->assertSame('0.30', $result['pay_amount']);
    }

    public function testCalcAmountsTruncatesBeyondTwoDecimals(): void
    {
        // 33.333 * 3 = 99.999 —— bcmath scale=2 截断为 99.99
        $items = [
            ['unit_price' => '33.333', 'quantity' => 3],
        ];
        $result = $this->invokePrivate('calcAmounts', [$items, $this->beijingPath()]);

        $this->assertSame('99.99', $result['total_amount']);
    }

    public function testCalcAmountsWithFreightTemplateZeroStillFreeShipping(): void
    {
        // 商品未绑定运费模板（freight_template_id=0）→ 包邮，运费 0
        $items = [
            ['unit_price' => '50.00', 'quantity' => 1, 'freight_template_id' => 0, 'weight' => 0.0],
        ];
        $result = $this->invokePrivate('calcAmounts', [$items, $this->beijingPath()]);

        $this->assertSame('50.00', $result['total_amount']);
        $this->assertSame('0.00', $result['freight_amount']);
        $this->assertSame('50.00', $result['pay_amount']);
    }

    // ------------------------- calcFreight -------------------------

    public function testCalcFreightWithEmptyItemsReturnsZero(): void
    {
        $result = $this->invokePrivate('calcFreight', [[], $this->beijingPath()]);
        $this->assertSame('0.00', $result);
    }

    public function testCalcFreightAllItemsWithoutTemplateReturnsZero(): void
    {
        // 全部 freight_template_id=0 → 不触发 DB 查询，直接包邮
        $items = [
            ['quantity' => 2, 'freight_template_id' => 0, 'weight' => 500.0],
            ['quantity' => 1, 'freight_template_id' => 0, 'weight' => 0.0],
        ];
        $result = $this->invokePrivate('calcFreight', [$items, $this->beijingPath()]);
        $this->assertSame('0.00', $result);
    }

    // ------------------------- sumChargeCount -------------------------

    public function testSumChargeCountByPieceAccumulatesQuantity(): void
    {
        // 按件：忽略 weight，累计 quantity = 2 + 3 = 5
        $items = [
            ['quantity' => 2, 'weight' => 999.0],
            ['quantity' => 3, 'weight' => 10.0],
        ];
        $result = $this->invokePrivate('sumChargeCount', [$items, 'piece']);
        $this->assertSame(5.0, $result);
    }

    public function testSumChargeCountByWeightConvertsGramToKilogram(): void
    {
        // 按重：(500×2 + 250×1) 克 / 1000 = 1.25 千克
        $items = [
            ['quantity' => 2, 'weight' => 500.0],
            ['quantity' => 1, 'weight' => 250.0],
        ];
        $result = $this->invokePrivate('sumChargeCount', [$items, 'weight']);
        $this->assertSame(1.25, $result);
    }

    public function testSumChargeCountByWeightTreatsMissingWeightAsZero(): void
    {
        $items = [
            ['quantity' => 3], // 无 weight 字段
        ];
        $result = $this->invokePrivate('sumChargeCount', [$items, 'weight']);
        $this->assertSame(0.0, $result);
    }

    public function testSumChargeCountWithEmptyItemsReturnsZero(): void
    {
        $this->assertSame(0.0, $this->invokePrivate('sumChargeCount', [[], 'piece']));
        $this->assertSame(0.0, $this->invokePrivate('sumChargeCount', [[], 'weight']));
    }

    // ------------------------- buildIdempotencyKey -------------------------

    public function testBuildIdempotencyKeyScopesByUserId(): void
    {
        $keyForUser1 = $this->invokePrivate('buildIdempotencyKey', [1, 'abc-123']);
        $keyForUser2 = $this->invokePrivate('buildIdempotencyKey', [2, 'abc-123']);

        $this->assertSame('1:abc-123', $keyForUser1);
        $this->assertSame('2:abc-123', $keyForUser2);
        $this->assertNotSame($keyForUser1, $keyForUser2, '不同用户同 key 必须隔离');
    }

    public function testBuildIdempotencyKeyStripsSpecialChars(): void
    {
        // 斜杠 / 空格 / 尖括号 / 中文 / emoji 一律被清洗掉
        $key = $this->invokePrivate('buildIdempotencyKey', [7, '<abc> /12 3中文🚀']);
        $this->assertSame('7:abc123', $key);
    }

    public function testBuildIdempotencyKeyPreservesSafeChars(): void
    {
        // 允许的字符集：A-Z a-z 0-9 - _ .
        $key = $this->invokePrivate('buildIdempotencyKey', [1, 'A1-b_2.c']);
        $this->assertSame('1:A1-b_2.c', $key);
    }

    public function testBuildIdempotencyKeyTruncatesToSixtyFourChars(): void
    {
        $longKey = str_repeat('a', 200);
        $key = $this->invokePrivate('buildIdempotencyKey', [9, $longKey]);

        // 结构是 "userId:sanitizedKey"，其中 sanitizedKey 被截断到 64
        [, $sanitized] = explode(':', $key, 2);
        $this->assertSame(64, mb_strlen($sanitized));
    }

    public function testBuildIdempotencyKeyFallsBackWhenNull(): void
    {
        $key = $this->invokePrivate('buildIdempotencyKey', [3, null]);

        [$uid, $sanitized] = explode(':', $key, 2);
        $this->assertSame('3', $uid);
        // bin2hex(random_bytes(16)) = 32 位 hex
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sanitized);
    }

    public function testBuildIdempotencyKeyFallsBackWhenEmptyString(): void
    {
        $key = $this->invokePrivate('buildIdempotencyKey', [4, '']);

        [$uid, $sanitized] = explode(':', $key, 2);
        $this->assertSame('4', $uid);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sanitized);
    }

    public function testBuildIdempotencyKeyFallbackIsUniquePerCall(): void
    {
        // 连续调用必须产出不同 key，否则幂等机制会被意外复用
        $k1 = $this->invokePrivate('buildIdempotencyKey', [1, null]);
        $k2 = $this->invokePrivate('buildIdempotencyKey', [1, null]);
        $this->assertNotSame($k1, $k2);
    }

    // ------------------------- assertUserId -------------------------

    public function testAssertUserIdRejectsZero(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->invokePrivate('assertUserId', [0]);
    }

    public function testAssertUserIdRejectsNegative(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('用户未登录');

        $this->invokePrivate('assertUserId', [-1]);
    }

    public function testAssertUserIdAcceptsPositive(): void
    {
        $this->invokePrivate('assertUserId', [1]);
        $this->invokePrivate('assertUserId', [999999]);
        $this->assertTrue(true); // 不抛异常即通过
    }

    // ------------------------- assertSaleable -------------------------

    public function testAssertSaleableRejectsNullSku(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('商品规格已下架');

        $this->invokePrivate('assertSaleable', [null, $this->validGoods()]);
    }

    public function testAssertSaleableRejectsDisabledSku(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('商品规格已下架');

        $sku = $this->validSku();
        $sku['status'] = 0; // 禁用
        $this->invokePrivate('assertSaleable', [$sku, $this->validGoods()]);
    }

    public function testAssertSaleableRejectsNullGoods(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('商品已下架');

        $this->invokePrivate('assertSaleable', [$this->validSku(), null]);
    }

    public function testAssertSaleableRejectsDisabledGoods(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('商品已下架');

        $goods = $this->validGoods();
        $goods['status'] = 0;
        $this->invokePrivate('assertSaleable', [$this->validSku(), $goods]);
    }

    public function testAssertSaleableRejectsOffSaleGoods(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('商品已下架');

        $goods = $this->validGoods();
        $goods['is_on_sale'] = 0;
        $this->invokePrivate('assertSaleable', [$this->validSku(), $goods]);
    }

    public function testAssertSaleableAcceptsValidPair(): void
    {
        $this->invokePrivate('assertSaleable', [$this->validSku(), $this->validGoods()]);
        $this->assertTrue(true); // 不抛异常即通过
    }

    // ------------------------- 其它契约 -------------------------

    /**
     * createFromSku 传空 items 必须在进入幂等/DB 前就拒绝
     *
     * 这是一个“纯校验路径”：assertUserId 通过后立刻抛“请选择要购买的商品”，
     * 不会走到 IdempotencyService / DB，可在单测环境下直接触发。
     */
    public function testCreateFromSkuRejectsEmptyItems(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('请选择要购买的商品');

        $this->service->createFromSku(userId: 1, items: [], addressId: 1);
    }

    /**
     * 默认支付超时 15 分钟契约：调优必须同步单测
     */
    public function testDefaultPayExpireSecondsIsFifteenMinutes(): void
    {
        $this->assertSame(900, OrderService::DEFAULT_PAY_EXPIRE_SECONDS);
    }

    // ------------------------- helpers -------------------------

    private function beijingPath(): RegionPathDto
    {
        return new RegionPathDto(
            provinceId: 1001,
            cityId: 2001,
            districtId: 3001,
            streetId: 4001,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validSku(): array
    {
        return [
            'id'          => 1,
            'goods_id'    => 10,
            'spec_values' => '红色/XL',
            'price'       => '99.00',
            'stock'       => 5,
            'status'      => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validGoods(): array
    {
        return [
            'id'         => 10,
            'name'       => '测试商品',
            'main_image' => 'uploads/test.jpg',
            'status'     => 1,
            'is_on_sale' => 1,
        ];
    }

    /**
     * 反射调用 OrderService 的私有方法
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionClass(OrderService::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }
}
