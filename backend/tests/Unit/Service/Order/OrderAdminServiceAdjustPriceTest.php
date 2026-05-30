<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\common\enum\OrderStatus;
use app\model\order\OrderLog;
use app\model\order\Order;
use app\model\order\PaymentLog;
use app\service\admin\order\OrderAdminService;
use app\service\order\WechatPrepayCloseService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;

final class AdjustableOrderStub extends Order
{
    public int $saveCalls = 0;

    public function save(array|object $data = [], $where = [], bool $refresh = false): bool
    {
        $this->saveCalls++;
        return true;
    }
}

/**
 * 后台改价前置守卫单元测试
 *
 * 设计意图：
 *  - adjustPrice() 在事务前有三道关键守卫，必须在不访问 DB 的前提下被锁死：
 *      1) 仅 PENDING_PAY 允许改价
 *      2) 运费不能为负
 *      3) bcmath 重算后的 pay_amount 必须 > 0
 *  - 一旦守卫被绕过，事务体内才会触发 PaymentLog 顶替、OrderLog 写入；
 *    在生产环境可能造成订单金额异常或重复支付，因此必须单独兜底。
 *
 * 思路（沿用 OrderStatusMachineTest 的反射 WeakMap 技巧）：
 *  - 用 newInstanceWithoutConstructor() 跳过 Model 构造（避免触达 DB schema 查询）
 *  - 通过反射写入 \think\Model::$weakMap，铺最小数据，让 $order->status / total_amount 等
 *    走 data 短路命中，不再触发 ORM 字段解析
 *  - 用匿名子类把 findOrder() 重载为返回预制 Order，避免真正查库（findOrder 已提升为 protected）
 */
final class OrderAdminServiceAdjustPriceTest extends TestCase
{
    /**
     * 守卫 1：非 PENDING_PAY 一律拒绝
     */
    public function testAdjustPriceRejectsNonPendingPayOrder(): void
    {
        $service = $this->makeServiceReturning(
            $this->makeOrder(OrderStatus::PAID, '100.00')
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('仅待支付订单允许改价');

        $service->adjustPrice(
            orderId: 1,
            freight: '10.00',
            discount: '0',
            adminId: 1,
        );
    }

    public function testAdjustPriceRejectsClosedOrder(): void
    {
        $service = $this->makeServiceReturning(
            $this->makeOrder(OrderStatus::CLOSED, '100.00')
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('仅待支付订单允许改价');

        $service->adjustPrice(orderId: 1, freight: '0', discount: '0', adminId: 1);
    }

    /**
     * 守卫 2：运费不得为负
     */
    public function testAdjustPriceRejectsNegativeFreight(): void
    {
        $service = $this->makeServiceReturning(
            $this->makeOrder(OrderStatus::PENDING_PAY, '100.00')
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('运费不能为负');

        $service->adjustPrice(
            orderId: 1,
            freight: '-0.01',
            discount: '0',
            adminId: 1,
        );
    }

    /**
     * 守卫 3：bcmath 重算 pay_amount 必须严格 > 0；100 + 0 - 100 = 0 应被拒
     */
    public function testAdjustPriceRejectsZeroPayAmount(): void
    {
        $service = $this->makeServiceReturning(
            $this->makeOrder(OrderStatus::PENDING_PAY, '100.00')
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('改价后应付金额必须大于 0');

        $service->adjustPrice(
            orderId: 1,
            freight: '0',
            discount: '100.00',
            adminId: 1,
        );
    }

    /**
     * 守卫 3：负 pay_amount（优惠超过总额 + 运费）拒绝
     */
    public function testAdjustPriceRejectsNegativePayAmount(): void
    {
        $service = $this->makeServiceReturning(
            $this->makeOrder(OrderStatus::PENDING_PAY, '50.00')
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('改价后应付金额必须大于 0');

        $service->adjustPrice(
            orderId: 1,
            freight: '0',
            discount: '60.00', // 50 + 0 - 60 = -10
            adminId: 1,
        );
    }

    public function testAdjustPriceUpdatesAmountsAndWritesAuditLog(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING_PAY, '100.00');
        $paymentLogModel = new class {
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

            public function where(string $field, mixed $operator = null, mixed $value = null): self
            {
                return $this;
            }

            /**
             * @param array<int, int> $ids
             */
            public function whereIn(string $field, array $ids): self
            {
                return $this;
            }

            /**
             * @param array<string, mixed> $data
             */
            public function update(array $data): int
            {
                $this->updates[] = $data;
                return 1;
            }
        };
        $orderLogModel = new class {
            /** @var array<string, mixed> */
            public array $saved = [];

            /**
             * @param array<string, mixed> $data
             */
            public function save(array|object $data = [], $where = [], bool $refresh = false): bool
            {
                $this->saved = (array) $data;
                return true;
            }
        };
        $prepayClose = new class extends WechatPrepayCloseService {
            public bool $closed = false;

            public function __construct()
            {
            }

            public function activePrepayLogs(int $orderId, ?int $excludeScene = null): array
            {
                return [];
            }

            public function closeLogs(array $logs): void
            {
                $this->closed = true;
            }

            public function idsOf(array $logs): array
            {
                return [];
            }
        };
        $service = $this->makeServiceReturning(
            $order,
            [
                PaymentLog::class => $paymentLogModel,
                OrderLog::class   => $orderLogModel,
            ],
            $prepayClose
        );

        $service->adjustPrice(
            orderId: 1,
            freight: '8.00',
            discount: '3.50',
            adminId: 9,
            reason: '客服协商',
        );

        $this->assertSame('8.00', (string) $order->freight_amount);
        $this->assertSame('3.50', (string) $order->discount_amount);
        $this->assertSame('104.50', (string) $order->pay_amount);
        $this->assertSame([['event_type' => PaymentLog::EVENT_SUPERSEDED]], $paymentLogModel->updates);
        $this->assertTrue($prepayClose->closed);
        $this->assertSame(1, $orderLogModel->saved['order_id']);
        $this->assertSame(OrderStatus::PENDING_PAY, $orderLogModel->saved['from_status']);
        $this->assertSame(OrderStatus::PENDING_PAY, $orderLogModel->saved['to_status']);
        $this->assertSame(9, $orderLogModel->saved['operator_id']);
        $this->assertStringContainsString('应付 100.00→104.50', $orderLogModel->saved['remark']);
        $this->assertStringContainsString('原因:客服协商', $orderLogModel->saved['remark']);
    }

    // ----------------- 测试基础设施 -----------------

    /**
     * 构造一个仅用于守卫测试的 Order
     *
     * 与 OrderStatusMachineTest 同一套手法：反射写 \think\Model::$weakMap，
     * 让 status / total_amount / freight_amount / discount_amount / pay_amount
     * 命中 data 短路，绕开 ThinkPHP Model 的 schema 解析。
     */
    private function makeOrder(int $status, string $total): Order
    {
        $ref = new \ReflectionClass(AdjustableOrderStub::class);
        /** @var Order $order */
        $order = $ref->newInstanceWithoutConstructor();

        $weakMapProp = new \ReflectionProperty(\think\Model::class, 'weakMap');
        $weakMapProp->setAccessible(true);
        /** @var \WeakMap|null $weakMap */
        $weakMap = $weakMapProp->getValue();
        if ($weakMap === null) {
            $weakMap = new \WeakMap();
            $weakMapProp->setValue(null, $weakMap);
        }

        $weakMap[$order] = [
            'data' => [
                'id'              => 1,
                'status'          => $status,
                'total_amount'    => $total,
                'freight_amount'  => '0.00',
                'discount_amount' => '0.00',
                'pay_amount'      => $total,
            ],
            'origin'   => [],
            'get'      => [],
            'schema'   => [
                'id'              => 'int',
                'status'          => 'int',
                'total_amount'    => 'string',
                'freight_amount'  => 'string',
                'discount_amount' => 'string',
                'pay_amount'      => 'string',
            ],
            'type'     => [],
            'disuse'   => [],
            'hidden'   => [],
            'visible'  => [],
            'append'   => [],
            'mapping'  => [],
            'readonly' => [],
            'withAttr' => [],
            'relation' => [],
        ];

        return $order;
    }

    /**
     * 用匿名子类覆盖 findOrder()，让守卫测试不访问 DB
     *
     * 注意：父类的 findOrder 是 protected，这里直接重写返回预制 Order；
     * 真正被测试的 adjustPrice() 仍是父类原方法。
     */
    private function makeServiceReturning(
        Order $order,
        array $models = [],
        ?WechatPrepayCloseService $prepayClose = null
    ): OrderAdminService
    {
        return new class ($order, $models, $prepayClose) extends OrderAdminService {
            public function __construct(
                private Order $stub,
                private array $models,
                private ?WechatPrepayCloseService $prepayClose
            )
            {
                // 不调用父构造：BaseService 默认不依赖容器；保持空体更安全
            }

            protected function findOrder(int $orderId): Order
            {
                return $this->stub;
            }

            protected function model(?string $modelClass = null)
            {
                if ($modelClass !== null && isset($this->models[$modelClass])) {
                    return $this->models[$modelClass];
                }
                return parent::model($modelClass);
            }

            protected function transaction(callable $callback)
            {
                return $callback();
            }

            protected function prepayCloseService(): WechatPrepayCloseService
            {
                if ($this->prepayClose !== null) {
                    return $this->prepayClose;
                }
                return parent::prepayCloseService();
            }

            protected function requestIp(): string
            {
                return '127.0.0.1';
            }
        };
    }
}
