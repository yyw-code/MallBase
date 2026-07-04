<?php

declare(strict_types=1);

namespace app\service\client\order;

use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\model\order\Cart;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\OrderLog;
use app\model\order\OrderMemberDiscount;
use app\model\order\PaymentLog;
use app\model\order\RefundOrder;
use app\model\setting\FreightTemplate;
use app\service\FreightCalculatorService;
use app\service\dto\RegionPathDto;
use app\service\order\OrderSnGenerator;
use app\service\order\OrderSettingService;
use app\service\order\OrderStatusMachine;
use app\service\order\StockService;
use app\service\order\WechatPrepayCloseService;
use app\service\user\UserMemberService;
use app\service\user\UserPointsAccountService;
use app\service\upload\AssetHydrator;
use app\model\user\UserAddress;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use app\common\enum\RefundOrderStatus;
use app\common\service\IdempotencyService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 买家订单服务（下单主链路）
 *
 * 原则：
 *  - 「先校验再事务」：SKU/地址/购物车归属/金额计算全部放事务外
 *  - 事务内只做：库存乐观锁扣减 → 落订单 → 订单项快照 → 清购物车 → 初始 OrderLog
 *  - 幂等：Redis SETNX 按 user+idempotency_key 抢占，重复请求 recall 历史结果
 *  - 状态流转统一走 {@see OrderStatusMachine}，禁止直接 $order->status = X
 *
 * @extends BaseService<Order>
 */
class OrderService extends BaseService
{
    protected string $modelClass = Order::class;

    /**
     * 下单默认支付超时（秒）：后台配置缺失时兜底 30 分钟
     */
    public const DEFAULT_PAY_EXPIRE_SECONDS = 1800;

    /**
     * 幂等业务域
     */
    private const IDEM_SCOPE_CREATE = 'order:create';

    /**
     * 购物车结算下单
     *
     * @param int           $userId          下单买家
     * @param array<int>    $cartIds         选中的购物车行 ID
     * @param int           $addressId       收货地址 ID
     * @param string|null   $buyerRemark     买家备注（可选，≤255）
     * @param string|null   $idempotencyKey  客户端幂等 key（UUID，强烈建议提供）
     *
     * @return array{order_id:int, sn:string}
     */
    public function createFromCart(
        int $userId,
        array $cartIds,
        int $addressId,
        ?string $buyerRemark = null,
        ?string $idempotencyKey = null,
        bool $usePoints = false,
        int $pointsUsed = 0
    ): array {
        $this->assertUserId($userId);

        // 幂等抢占（事务外，避免事务内触发网络 IO）
        $idem = app()->make(IdempotencyService::class);
        $idemKey = $this->buildIdempotencyKey($userId, $idempotencyKey);
        if (!$idem->acquire(self::IDEM_SCOPE_CREATE, $idemKey)) {
            $recalled = $idem->recall(self::IDEM_SCOPE_CREATE, $idemKey);
            if ($recalled !== null && isset($recalled['order_id'], $recalled['sn'])) {
                return ['order_id' => (int) $recalled['order_id'], 'sn' => (string) $recalled['sn']];
            }
            throw new BusinessException('订单正在处理中，请稍后重试');
        }

        try {
            $address = $this->assertOwnedAddress($userId, $addressId);
            $items   = $this->loadSelectedCartItems($userId, $cartIds);
            $amounts = $this->calcAmounts($items, $this->regionPathOf($address), $userId, $usePoints, $pointsUsed);

            $result = $this->persistOrder(
                userId: $userId,
                address: $address,
                items: $items,
                amounts: $amounts,
                buyerRemark: $buyerRemark,
                cartIdsToClear: array_map(static fn(array $i): int => (int) $i['cart_id'], $items),
            );

            $idem->bind(self::IDEM_SCOPE_CREATE, $idemKey, $result);
            return $result;
        } catch (\Throwable $e) {
            // 业务失败释放幂等 key，允许客户端重试
            $idem->release(self::IDEM_SCOPE_CREATE, $idemKey);
            throw $e;
        }
    }

    /**
     * 立即购买（从 SKU 直接下单，不经过购物车）
     *
     * @param array<int, array{sku_id:int, quantity:int}> $items
     * @return array{order_id:int, sn:string}
     */
    public function createFromSku(
        int $userId,
        array $items,
        int $addressId,
        ?string $buyerRemark = null,
        ?string $idempotencyKey = null,
        bool $usePoints = false,
        int $pointsUsed = 0
    ): array {
        $this->assertUserId($userId);
        if ($items === []) {
            throw new BusinessException('请选择要购买的商品');
        }

        $idem = app()->make(IdempotencyService::class);
        $idemKey = $this->buildIdempotencyKey($userId, $idempotencyKey);
        if (!$idem->acquire(self::IDEM_SCOPE_CREATE, $idemKey)) {
            $recalled = $idem->recall(self::IDEM_SCOPE_CREATE, $idemKey);
            if ($recalled !== null && isset($recalled['order_id'], $recalled['sn'])) {
                return ['order_id' => (int) $recalled['order_id'], 'sn' => (string) $recalled['sn']];
            }
            throw new BusinessException('订单正在处理中，请稍后重试');
        }

        try {
            $address = $this->assertOwnedAddress($userId, $addressId);
            $resolved = $this->loadDirectBuyItems($items);
            $amounts  = $this->calcAmounts($resolved, $this->regionPathOf($address), $userId, $usePoints, $pointsUsed);

            $result = $this->persistOrder(
                userId: $userId,
                address: $address,
                items: $resolved,
                amounts: $amounts,
                buyerRemark: $buyerRemark,
                cartIdsToClear: [],
            );

            $idem->bind(self::IDEM_SCOPE_CREATE, $idemKey, $result);
            return $result;
        } catch (\Throwable $e) {
            $idem->release(self::IDEM_SCOPE_CREATE, $idemKey);
            throw $e;
        }
    }

    /**
     * 订单试算
     *
     * 与下单同源的校验装配链路，但不进事务、不占幂等、不扣库存：
     * 仅做地址校验 + 商品装配 + 金额计算，供确认页展示权威金额（含运费）。
     *
     * @param array<int>                                  $cartIds  source=cart 时的购物车行 ID
     * @param array<int, array{sku_id:int, quantity:int}> $items    source=sku 时的直购项
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   total_amount: string, freight_amount: string, discount_amount: string, pay_amount: string,
     *   address: array<string, mixed>
     * }
     */
    public function preview(int $userId, string $source, array $cartIds, array $items, int $addressId, bool $usePoints = false, int $pointsUsed = 0): array
    {
        $this->assertUserId($userId);

        $address    = $this->assertOwnedAddress($userId, $addressId);
        $regionPath = $this->regionPathOf($address);

        if ($source === 'cart') {
            $resolved = $this->loadSelectedCartItems($userId, array_map('intval', $cartIds));
        } else {
            if ($items === []) {
                throw new BusinessException('请选择要购买的商品');
            }
            $resolved = $this->loadDirectBuyItems($items);
        }

        $amounts = $this->calcAmounts($resolved, $regionPath, $userId, $usePoints, $pointsUsed);

        $previewItems = array_map(static fn(array $i): array => [
            'sku_id'               => $i['sku_id'],
            'goods_id'             => $i['goods_id'],
            'goods_name'           => $i['goods_name'],
            'goods_image'          => $i['goods_image'],
            'goods_image_full_url' => '',
            'sku_spec'             => $i['sku_spec'],
            'unit_price'           => $i['unit_price'],
            'quantity'             => $i['quantity'],
            'subtotal'             => bcmul($i['unit_price'], (string) $i['quantity'], 2),
        ], $resolved);
        $previewItems = app()->make(AssetHydrator::class)->hydrateFields($previewItems, [
            'goods_image' => 'goods_image_full_url',
        ]);
        foreach ($previewItems as &$item) {
            $item['goods_image_url'] = $item['goods_image_full_url'] ?? '';
            if (($item['goods_image_url'] ?? '') !== '') {
                $item['goods_image'] = $item['goods_image_url'];
            }
        }
        unset($item);

        return [
            'items'           => $previewItems,
            'total_amount'    => $amounts['total_amount'],
            'freight_amount'  => $amounts['freight_amount'],
            'discount_amount' => $amounts['discount_amount'],
            'pay_amount'      => $amounts['pay_amount'],
            'member_discount' => $amounts['member_discount'] ?? null,
            'points_deduction' => $amounts['points_deduction'] ?? null,
            'address'         => [
                'id'       => $addressId,
                'name'     => $address['name'],
                'phone'    => $address['phone'],
                'province' => $address['province'],
                'city'     => $address['city'],
                'district' => $address['district'],
                'address'  => $address['address'],
            ],
        ];
    }

    /**
     * 旧同步测试支付入口已下线，仅保留方法签名用于兼容反射契约。
     */
    public function pay(int $orderId, int $userId, int $payMethod, ?string $tradeNo = null): array
    {
        throw new BusinessException('该支付方式暂未开放');
    }

    /**
     * 回调驱动：确认订单已支付（真实渠道走此入口）
     *
     * confirmPaid() 是异步回调入口，由 NotifyService 在验签 + 金额比对通过后调用。
     *
     * 幂等：订单已是 PAID 状态时直接返回，不抛异常（应对重复回调）
     *
     * @return array{order_id:int, sn:string, status:int}
     */
    public function confirmPaid(string $sn, string $transactionId, int $payMethod, int $payScene): array
    {
        if (!PayMethod::isValid($payMethod)) {
            throw new BusinessException('支付方式不合法');
        }

        /** @var Order|null $order */
        $order = $this->model()->where('sn', $sn)->whereNull('delete_time')->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }
        if ((int) $order->status === OrderStatus::PAID) {
            // 幂等：重复回调直接返回，不报错
            return [
                'order_id' => (int) $order->id,
                'sn'       => (string) $order->sn,
                'status'   => OrderStatus::PAID,
            ];
        }
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('订单已关闭或状态不允许支付');
        }

        $machine = app()->make(OrderStatusMachine::class);
        $this->transaction(function () use ($order, $transactionId, $payMethod, $payScene, $machine): void {
            $order->pay_method = $payMethod;
            $order->pay_scene  = $payScene;
            $order->trade_no   = mb_substr($transactionId, 0, 64);
            $order->save();

            $machine->transit(
                order: $order,
                toStatus: OrderStatus::PAID,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: sprintf('支付成功（%s）', PayMethod::textOf($payMethod)),
            );
        });

        return [
            'order_id' => (int) $order->id,
            'sn'       => (string) $order->sn,
            'status'   => OrderStatus::PAID,
        ];
    }

    /**
     * 买家主动取消（仅 PENDING_PAY 可取消）
     *
     * 事务内：状态机流转 CLOSED → 批量回滚库存
     */
    public function cancel(int $userId, int $orderId, ?string $reason = null): void
    {
        $order = $this->findOwnedOrder($userId, $orderId);
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('当前订单状态不允许取消');
        }

        /** @var WechatPrepayCloseService $prepayCloser */
        $prepayCloser = app()->make(WechatPrepayCloseService::class);
        $prepayLogs = $prepayCloser->activePrepayLogs((int) $order->id);
        $prepayCloser->closeLogs($prepayLogs);
        $prepayLogIds = $prepayCloser->idsOf($prepayLogs);

        $machine = app()->make(OrderStatusMachine::class);
        $stock   = app()->make(StockService::class);

        $items = $this->loadOrderItemsForStock((int) $order->id);

        $this->transaction(function () use ($order, $items, $machine, $stock, $reason, $userId, $prepayLogIds): void {
            $machine->transit(
                order: $order,
                toStatus: OrderStatus::CLOSED,
                operatorType: OperatorType::BUYER,
                operatorId: $userId,
                remark: $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : '买家取消订单',
            );
            $stock->restoreBatch($items);
            if ($prepayLogIds !== []) {
                $this->model(PaymentLog::class)
                    ->whereIn('id', $prepayLogIds)
                    ->update(['event_type' => PaymentLog::EVENT_CLOSED]);
            }
        });
    }

    /**
     * 确认收货（SHIPPED → RECEIVED）
     */
    public function confirmReceive(int $userId, int $orderId): void
    {
        $order = $this->findOwnedOrder($userId, $orderId);
        if ((int) $order->status !== OrderStatus::SHIPPED) {
            throw new BusinessException('当前订单状态不允许确认收货');
        }
        if ($this->hasActiveRefund((int) $order->id)) {
            throw new BusinessException('订单存在进行中的售后申请，暂不能确认收货');
        }

        app()->make(OrderStatusMachine::class)->transit(
            order: $order,
            toStatus: OrderStatus::RECEIVED,
            operatorType: OperatorType::BUYER,
            operatorId: $userId,
            remark: '买家确认收货',
        );
    }

    /**
     * 买家订单列表（分页）
     *
     * 条件同源：先统计 total 再查 list，使用同一 builder 克隆
     *
     * @param array{status?:int|null, sn?:string|null} $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function list(int $userId, array $filter = [], int $page = 1, int $pageSize = 10): array
    {
        $query = $this->buildListQuery($userId, $filter);

        $total = (clone $query)->count();
        $list = $query
            ->with(['items' => static function ($q): void {
                $q->order('id', 'asc');
            }])
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $orderIds = array_map(static fn(array $r): int => (int) $r['id'], $list);
        $afterSaleMap = $this->aggregateAfterSaleInfo($orderIds);
        $list = $this->hydrateOrderItems($list);
        foreach ($list as &$row) {
            $row['after_sale'] = $afterSaleMap[(int) $row['id']] ?? null;
            $row['after_sale_tag_text'] = (string) ($row['after_sale']['status_text'] ?? '');
            $row['can_refund'] = $this->canApplyRefund($row);
        }
        unset($row);

        return compact('total', 'list');
    }

    /**
     * @param array{status?:int|null, sn?:string|null} $filter
     */
    private function buildListQuery(int $userId, array $filter = [])
    {
        $query = $this->model()
            ->where('user_id', $userId)
            ->whereNull('delete_time');

        if (isset($filter['status']) && $filter['status'] !== null && $filter['status'] !== '') {
            $query->where('status', (int) $filter['status']);
        }

        $sn = trim((string) ($filter['sn'] ?? ''));
        if ($sn !== '') {
            $query->where('sn', $sn);
        }

        return $query;
    }

    /**
     * 买家订单详情
     */
    public function detail(int $userId, int $orderId): array
    {
        /** @var Order|null $order */
        $order = $this->model()
            ->with([
                'items' => static function ($q): void {
                    $q->order('id', 'asc');
                },
                'logs' => static function ($q): void {
                    $q->order('id', 'asc');
                },
            ])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }

        $data = $order->toArray();
        $data = $this->hydrateOrderItems([$data])[0];
        $afterSaleMap = $this->aggregateAfterSaleInfo([(int) $order->id]);
        $data['after_sale'] = $afterSaleMap[(int) $order->id] ?? null;
        $data['after_sale_tag_text'] = (string) ($data['after_sale']['status_text'] ?? '');
        $data['can_refund'] = $this->canApplyRefund($data);

        return $data;
    }

    // ---------------- 内部：校验 / 装配 / 落库 ----------------

    /**
     * 事务内：原子化下单落库
     *
     * @param array<int, array{sku_id:int, goods_id:int, goods_name:string, goods_image:string, sku_spec:string, unit_price:string, quantity:int, cart_id?:int}> $items
     * @param array{total_amount:string, freight_amount:string, discount_amount:string, pay_amount:string, item_discounts?:array<int,string>, member_discount?:array<string,mixed>, points_deduction?:array<string,mixed>} $amounts
     * @param array<int, int> $cartIdsToClear
     * @return array{order_id:int, sn:string}
     */
    private function persistOrder(
        int $userId,
        array $address,
        array $items,
        array $amounts,
        ?string $buyerRemark,
        array $cartIdsToClear,
    ): array {
        /** @var StockService $stock */
        $stock = app()->make(StockService::class);
        /** @var OrderSnGenerator $snGen */
        $snGen = app()->make(OrderSnGenerator::class);
        /** @var CartService $cart */
        $cart = app()->make(CartService::class);

        return $this->transaction(function () use (
            $userId, $address, $items, $amounts, $buyerRemark, $cartIdsToClear,
            $stock, $snGen, $cart
        ) {
            // 1. 乐观锁扣库存（任一失败整体回滚）
            $stock->decreaseBatch(array_map(
                static fn(array $i): array => ['sku_id' => $i['sku_id'], 'quantity' => $i['quantity']],
                $items,
            ));

            // 2. 落主订单
            $sn = $snGen->next();
            /** @var Order $order */
            $order = $this->model()->create([
                'sn'                => $sn,
                'user_id'           => $userId,
                'status'            => OrderStatus::PENDING_PAY,
                'total_amount'      => $amounts['total_amount'],
                'freight_amount'    => $amounts['freight_amount'],
                'discount_amount'   => $amounts['discount_amount'],
                'pay_amount'        => $amounts['pay_amount'],
                'receiver_name'     => $address['name'],
                'receiver_phone'    => $address['phone'],
                'receiver_province' => $address['province'],
                'receiver_city'     => $address['city'],
                'receiver_district' => $address['district'],
                'receiver_address'  => $address['address'],
                'buyer_remark'      => $buyerRemark !== null ? mb_substr($buyerRemark, 0, 255) : null,
                'expire_at'         => date(
                    'Y-m-d H:i:s',
                    time() + app()->make(OrderSettingService::class)->pendingPayTimeoutSeconds()
                ),
            ]);

            $pointsDeduction = $amounts['points_deduction'] ?? [];
            $usedPoints = (int) ($pointsDeduction['used_points'] ?? 0);
            if ($usedPoints > 0) {
                app()->make(UserPointsAccountService::class)->deductForOrder(
                    userId: $userId,
                    orderId: (int) $order->id,
                    orderSn: (string) $order->sn,
                    usedPoints: $usedPoints,
                    discountAmount: (string) ($pointsDeduction['discount_amount'] ?? '0.00'),
                );
            }

            $memberDiscount = $amounts['member_discount'] ?? [];
            $memberDiscountAmount = (string) ($memberDiscount['discount_amount'] ?? '0.00');
            if ($this->decimalToCents($memberDiscountAmount) > 0) {
                $level = is_array($memberDiscount['level'] ?? null) ? $memberDiscount['level'] : [];
                $this->model(OrderMemberDiscount::class)->save([
                    'order_id' => (int) $order->id,
                    'order_sn' => (string) $order->sn,
                    'user_id' => $userId,
                    'level_id' => (int) ($level['id'] ?? 0),
                    'level_name' => (string) ($level['name'] ?? ''),
                    'discount_amount' => $memberDiscountAmount,
                ]);
            }

            // 3. 落订单项快照
            $itemModel = app()->make(OrderItem::class);
            $itemDiscounts = $amounts['item_discounts'] ?? $this->allocateItemDiscounts($items, $amounts['discount_amount']);
            foreach ($items as $index => $item) {
                $subtotal = bcmul($item['unit_price'], (string) $item['quantity'], 2);
                $itemDiscount = $itemDiscounts[$index] ?? '0.00';
                $itemPayAmount = $this->centsToDecimal(max(
                    0,
                    $this->decimalToCents($subtotal) - $this->decimalToCents($itemDiscount)
                ));
                $itemModel->newInstance()->save([
                    'order_id'        => (int) $order->id,
                    'goods_id'        => $item['goods_id'],
                    'sku_id'          => $item['sku_id'],
                    'goods_name'      => mb_substr($item['goods_name'], 0, 200),
                    'goods_image'     => $item['goods_image'],
                    'sku_spec'        => mb_substr($item['sku_spec'], 0, 500),
                    'unit_price'      => $item['unit_price'],
                    'quantity'        => $item['quantity'],
                    'subtotal'        => $subtotal,
                    'discount_amount' => $itemDiscount,
                    'pay_amount'      => $itemPayAmount,
                ]);
            }

            // 4. 清购物车选中行（立即购买路径 cartIdsToClear 为空）
            if ($cartIdsToClear !== []) {
                $cart->removeSelected($userId, $cartIdsToClear);
            }

            // 5. 初始状态日志（from=null → PENDING_PAY）
            // 首条日志不走 OrderStatusMachine：状态机只负责状态变更，
            // 创建订单属于初始化动作，直接落一条 append-only 日志作为审计起点。
            OrderLog::create([
                'order_id'      => (int) $order->id,
                'from_status'   => null,
                'to_status'     => OrderStatus::PENDING_PAY,
                'operator_type' => OperatorType::BUYER,
                'operator_id'   => $userId,
                'remark'        => '下单',
                'ip'            => null,
            ]);

            return [
                'order_id' => (int) $order->id,
                'sn'       => (string) $order->sn,
            ];
        });
    }

    /**
     * 校验地址归属并返回落库 / 运费计算所需字段
     *
     * @return array{
     *   name:string, phone:string, province:string, city:string, district:string, address:string,
     *   province_id:int, city_id:int, district_id:int, street_id:int
     * }
     */
    private function assertOwnedAddress(int $userId, int $addressId): array
    {
        /** @var UserAddress|null $address */
        $address = $this->model(UserAddress::class)
            ->where('id', $addressId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($address === null) {
            throw new BusinessException('收货地址不存在');
        }

        return [
            'name'        => (string) $address->receiver_name,
            'phone'       => (string) $address->receiver_mobile,
            'province'    => (string) $address->province_name,
            'city'        => (string) $address->city_name,
            'district'    => (string) $address->district_name,
            'address'     => (string) $address->address_detail,
            'province_id' => (int) $address->province_id,
            'city_id'     => (int) $address->city_id,
            'district_id' => (int) $address->district_id,
            'street_id'   => (int) $address->street_id,
        ];
    }

    /**
     * 由地址数组构造运费计算用的四级区域路径
     *
     * @param array{province_id:int, city_id:int, district_id:int, street_id:int} $address
     */
    private function regionPathOf(array $address): RegionPathDto
    {
        return new RegionPathDto(
            provinceId: (int) $address['province_id'],
            cityId: (int) $address['city_id'],
            districtId: (int) $address['district_id'],
            streetId: (int) $address['street_id'],
        );
    }

    /**
     * 加载选中的购物车行并聚合为下单项
     *
     * @param array<int, int> $cartIds
     * @return array<int, array{sku_id:int, goods_id:int, goods_name:string, goods_image:string, sku_spec:string, unit_price:string, quantity:int, cart_id:int, freight_template_id:int, weight:float}>
     */
    private function loadSelectedCartItems(int $userId, array $cartIds): array
    {
        $ids = [];
        foreach ($cartIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = $intId;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            throw new BusinessException('请选择要结算的购物车商品');
        }

        $carts = $this->model(Cart::class)
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->select()
            ->toArray();
        if (count($carts) !== count($ids)) {
            throw new BusinessException('部分购物车记录不存在，请刷新后重试');
        }

        $skuIds   = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['sku_id'], $carts)));
        $skuMap   = $this->fetchSkuMap($skuIds);
        $goodsIds = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['goods_id'], $carts)));
        $goodsMap = $this->fetchGoodsMap($goodsIds);

        $items = [];
        foreach ($carts as $row) {
            $skuId   = (int) $row['sku_id'];
            $goodsId = (int) $row['goods_id'];
            $qty     = (int) $row['quantity'];

            $sku   = $skuMap[$skuId]   ?? null;
            $goods = $goodsMap[$goodsId] ?? null;
            $this->assertSaleable($sku, $goods);

            $items[] = [
                'cart_id'             => (int) $row['id'],
                'sku_id'              => $skuId,
                'goods_id'            => $goodsId,
                'goods_name'          => (string) ($goods['name'] ?? ''),
                'goods_image'         => (string) ($goods['main_image'] ?? ''),
                'sku_spec'            => (string) ($sku['spec_values'] ?? ''),
                'unit_price'          => (string) $sku['price'],
                'quantity'            => $qty,
                'freight_template_id' => (int) ($goods['freight_template_id'] ?? 0),
                'weight'              => (float) ($sku['weight'] ?? 0),
                'member_benefit_mode' => (string) ($goods['member_benefit_mode'] ?? 'global'),
                'member_price'        => $sku['member_price'] ?? null,
            ];
        }
        return $items;
    }

    /**
     * 立即购买：解析传入的 SKU/数量为下单项
     *
     * @param array<int, array{sku_id:int, quantity:int}> $items
     * @return array<int, array{sku_id:int, goods_id:int, goods_name:string, goods_image:string, sku_spec:string, unit_price:string, quantity:int, freight_template_id:int, weight:float}>
     */
    private function loadDirectBuyItems(array $items): array
    {
        $skuIds = [];
        foreach ($items as $it) {
            $skuId = (int) ($it['sku_id'] ?? 0);
            $qty   = (int) ($it['quantity'] ?? 0);
            if ($skuId <= 0 || $qty <= 0) {
                throw new BusinessException('商品规格或数量不合法');
            }
            $skuIds[] = $skuId;
        }
        $skuMap = $this->fetchSkuMap(array_values(array_unique($skuIds)));

        $goodsIds = [];
        foreach ($skuMap as $sku) {
            $goodsIds[] = (int) $sku['goods_id'];
        }
        $goodsMap = $this->fetchGoodsMap(array_values(array_unique($goodsIds)));

        $resolved = [];
        foreach ($items as $it) {
            $skuId = (int) $it['sku_id'];
            $sku   = $skuMap[$skuId] ?? null;
            if ($sku === null) {
                throw new BusinessException('商品规格不存在');
            }
            $goodsId = (int) $sku['goods_id'];
            $goods   = $goodsMap[$goodsId] ?? null;
            $this->assertSaleable($sku, $goods);

            $resolved[] = [
                'sku_id'              => $skuId,
                'goods_id'            => $goodsId,
                'goods_name'          => (string) ($goods['name'] ?? ''),
                'goods_image'         => (string) ($goods['main_image'] ?? ''),
                'sku_spec'            => (string) ($sku['spec_values'] ?? ''),
                'unit_price'          => (string) $sku['price'],
                'quantity'            => (int) $it['quantity'],
                'freight_template_id' => (int) ($goods['freight_template_id'] ?? 0),
                'weight'              => (float) ($sku['weight'] ?? 0),
                'member_benefit_mode' => (string) ($goods['member_benefit_mode'] ?? 'global'),
                'member_price'        => $sku['member_price'] ?? null,
            ];
        }
        return $resolved;
    }

    /**
     * 计算订单金额
     *
     * 运费按 freight_template_id 分组、各组接 FreightCalculatorService 计算后求和；
     * 会员优惠先作用于商品金额，积分抵扣再基于会员优惠后的商品实付金额计算。
     *
     * @param array<int, array{unit_price:string, quantity:int, freight_template_id?:int, weight?:float, member_benefit_mode?:string, member_price?:mixed}> $items
     * @return array{total_amount:string, freight_amount:string, discount_amount:string, pay_amount:string, item_discounts:array<int,string>, member_discount?:array<string,mixed>, points_deduction?:array<string,mixed>}
     */
    private function calcAmounts(array $items, RegionPathDto $regionPath, int $userId = 0, bool $usePoints = false, int $pointsUsed = 0): array
    {
        $total = '0.00';
        foreach ($items as $item) {
            $sub = bcmul($item['unit_price'], (string) $item['quantity'], 2);
            $total = bcadd($total, $sub, 2);
        }
        $freight  = $this->calcFreight($items, $regionPath);
        $memberDiscount = null;
        $memberItemDiscounts = array_fill(0, count($items), '0.00');
        if ($userId > 0) {
            /** @var UserMemberService $memberService */
            $memberService = app()->make(UserMemberService::class);
            $memberDiscount = $memberService->pricingQuote($userId, $items);
            $memberItemDiscounts = $memberDiscount['item_discounts'] ?? $memberItemDiscounts;
        }
        $memberDiscountAmount = (string) ($memberDiscount['discount_amount'] ?? '0.00');
        $memberDiscountCents = $this->decimalToCents($memberDiscountAmount);
        $pointsEligibleAmount = $this->centsToDecimal(max(0, $this->decimalToCents($total) - $memberDiscountCents));

        $pointsDeduction = null;
        if ($userId > 0) {
            /** @var UserPointsAccountService $pointsService */
            $pointsService = app()->make(UserPointsAccountService::class);
            $pointsRequested = $usePoints || $pointsUsed > 0;
            if (!$pointsService->isDeductionEnabled()) {
                if ($pointsRequested) {
                    throw new BusinessException('积分抵扣未开启');
                }
            } else {
                $pointsDeduction = $pointsService->deductionQuote($userId, $pointsEligibleAmount, $usePoints, max(0, $pointsUsed));
            }
        }
        $pointsDiscount = (string) ($pointsDeduction['discount_amount'] ?? '0.00');
        $discount = $this->centsToDecimal($memberDiscountCents + $this->decimalToCents($pointsDiscount));
        $pay      = bcsub(bcadd($total, $freight, 2), $discount, 2);
        if ($this->decimalToCents($pay) < 0) {
            $pay = '0.00';
        }

        $itemDiscounts = $this->mergeItemDiscounts(
            $items,
            $memberItemDiscounts,
            $this->decimalToCents($pointsDiscount)
        );

        $result = [
            'total_amount'    => $total,
            'freight_amount'  => $freight,
            'discount_amount' => $discount,
            'pay_amount'      => $pay,
            'item_discounts'   => $itemDiscounts,
        ];
        if ($memberDiscount !== null) {
            $result['member_discount'] = $memberDiscount;
        }
        if ($pointsDeduction !== null) {
            $result['points_deduction'] = $pointsDeduction;
        }

        return $result;
    }

    /**
     * @param array<int, array{unit_price:string, quantity:int}> $items
     * @param array<int, string> $memberItemDiscounts
     * @return array<int,string>
     */
    private function mergeItemDiscounts(array $items, array $memberItemDiscounts, int $pointsDiscountCents): array
    {
        $memberDiscountCents = [];
        $pointBases = [];
        foreach ($items as $index => $item) {
            $subtotalCents = $this->decimalToCents(bcmul($item['unit_price'], (string) $item['quantity'], 2));
            $memberCents = min($subtotalCents, $this->decimalToCents((string) ($memberItemDiscounts[$index] ?? '0.00')));
            $memberDiscountCents[$index] = $memberCents;
            $pointBases[$index] = max(0, $subtotalCents - $memberCents);
        }

        $pointDiscounts = $this->allocateCentsByBases($pointBases, $pointsDiscountCents);
        $result = [];
        foreach ($items as $index => $item) {
            $subtotalCents = $this->decimalToCents(bcmul($item['unit_price'], (string) $item['quantity'], 2));
            $discountCents = min(
                $subtotalCents,
                ($memberDiscountCents[$index] ?? 0) + ($pointDiscounts[$index] ?? 0)
            );
            $result[$index] = $this->centsToDecimal($discountCents);
        }

        return $result;
    }

    /**
     * @param array<int,int> $bases
     * @return array<int,int>
     */
    private function allocateCentsByBases(array $bases, int $discountCents): array
    {
        $discountCents = max(0, $discountCents);
        $totalBase = array_sum(array_map(static fn(int $basis): int => max(0, $basis), $bases));
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
            $result[$index] = min($itemDiscount, $basis);
        }

        return $result;
    }

    /**
     * @param array<int, array{unit_price:string, quantity:int}> $items
     * @return array<int, string>
     */
    private function allocateItemDiscounts(array $items, string $discountAmount): array
    {
        $discountCents = $this->decimalToCents($discountAmount);
        if ($discountCents <= 0 || $items === []) {
            return array_fill(0, count($items), '0.00');
        }

        $subtotals = [];
        $totalCents = 0;
        foreach ($items as $item) {
            $subtotalCents = $this->decimalToCents(bcmul($item['unit_price'], (string) $item['quantity'], 2));
            $subtotals[] = $subtotalCents;
            $totalCents += $subtotalCents;
        }
        if ($totalCents <= 0) {
            return array_fill(0, count($items), '0.00');
        }

        $discountCents = min($discountCents, $totalCents);
        $allocated = 0;
        $lastIndex = array_key_last($subtotals);
        $result = [];
        foreach ($subtotals as $index => $subtotalCents) {
            if ($index === $lastIndex) {
                $itemDiscount = max(0, $discountCents - $allocated);
            } else {
                $itemDiscount = intdiv($discountCents * $subtotalCents, $totalCents);
                $allocated += $itemDiscount;
            }
            $result[$index] = $this->centsToDecimal(min($itemDiscount, $subtotalCents));
        }

        return $result;
    }

    /**
     * 计算订单运费
     *
     * 规则：
     *  - items 按 freight_template_id 分组，每组按模板 charge_type 累计件数/重量后独立计算，求和
     *  - freight_template_id 为空/0、模板不存在或已停用 → 该组归为包邮（运费 0）
     *  - 按重计费时 totalCount 单位为千克：SKU weight 字段单位为克，故 ÷1000
     *
     * @param array<int, array{quantity:int, freight_template_id?:int, weight?:float}> $items
     */
    private function calcFreight(array $items, RegionPathDto $regionPath): string
    {
        if ($items === []) {
            return '0.00';
        }

        // 按运费模板分组
        $groups = [];
        foreach ($items as $item) {
            $templateId = (int) ($item['freight_template_id'] ?? 0);
            $groups[$templateId][] = $item;
        }

        // 批量查启用中的模板，停用/不存在者后续降级为包邮
        $templateIds = array_values(array_filter(
            array_keys($groups),
            static fn(int $id): bool => $id > 0,
        ));
        $chargeTypeMap = [];
        if ($templateIds !== []) {
            $rows = $this->model(FreightTemplate::class)
                ->whereIn('id', $templateIds)
                ->where('status', 1)
                ->column('charge_type', 'id');
            $chargeTypeMap = is_array($rows) ? $rows : [];
        }

        if ($chargeTypeMap === []) {
            return '0.00'; // 无启用中的运费模板，全部包邮
        }

        $calculator = app()->make(FreightCalculatorService::class);
        $freight = '0.00';
        foreach ($groups as $templateId => $groupItems) {
            if ($templateId <= 0 || !isset($chargeTypeMap[$templateId])) {
                continue; // 包邮 / 模板失效降级包邮
            }
            $totalCount = $this->sumChargeCount($groupItems, (string) $chargeTypeMap[$templateId]);
            if ($totalCount <= 0) {
                continue; // 0 件 / 0 重量不计运费
            }
            try {
                $result = $calculator->calculate($templateId, $regionPath, $totalCount);
            } catch (BusinessException) {
                // 并发停用等竞态：该组降级包邮，不阻断下单 / 试算
                continue;
            }
            $freight = bcadd($freight, sprintf('%.2f', $result->fee), 2);
        }

        return $freight;
    }

    /**
     * 按模板计费方式累计 totalCount：piece 按件数，weight 按千克
     *
     * @param array<int, array{quantity:int, weight?:float}> $items
     */
    private function sumChargeCount(array $items, string $chargeType): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            if ($chargeType === 'weight') {
                $weightGram = (float) ($item['weight'] ?? 0);
                $total += $weightGram * $quantity / 1000.0;
            } else {
                $total += $quantity;
            }
        }
        return $total;
    }

    /**
     * 校验 SKU / Goods 可售
     *
     * @param array<string, mixed>|null $sku
     * @param array<string, mixed>|null $goods
     */
    private function assertSaleable(?array $sku, ?array $goods): void
    {
        if ($sku === null || (int) $sku['status'] !== 1) {
            throw new BusinessException('商品规格已下架');
        }
        if ($goods === null || (int) $goods['status'] !== 1 || (int) $goods['is_on_sale'] !== 1) {
            throw new BusinessException('商品已下架');
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private function canApplyRefund(array $order): bool
    {
        $status = (int) ($order['status'] ?? 0);
        if (!in_array($status, [OrderStatus::PAID, OrderStatus::SHIPPED, OrderStatus::RECEIVED, OrderStatus::COMPLETED], true)) {
            return false;
        }

        $afterSaleDays = $this->afterSaleDays();
        if ($afterSaleDays > 0) {
            $receivedAt = (string) ($order['received_at'] ?? '');
            if ($receivedAt !== '' && strtotime($receivedAt) + ($afterSaleDays * 86400) < time()) {
                return false;
            }
        }

        return $this->hasRefundableItem($order['items'] ?? []);
    }

    /**
     * @param mixed $items
     */
    private function hasRefundableItem(mixed $items): bool
    {
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['has_active_refund'])) {
                continue;
            }
            if ((int) ($item['refundable_quantity'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveRefund(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        return $this->model(RefundOrder::class)
            ->where('order_id', $orderId)
            ->whereIn('status', RefundOrderStatus::activeStatuses())
            ->whereNull('delete_time')
            ->count() > 0;
    }

    /**
     * @param array<int, int> $orderIds
     * @return array<int, array<string, mixed>>
     */
    private function aggregateAfterSaleInfo(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->model(RefundOrder::class)
            ->whereIn('order_id', $orderIds)
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->field('id, sn, order_id, order_item_id, type, receive_status, status, quantity, refund_amount, intercept_status, create_time, reviewed_at, refunded_at, canceled_at')
            ->select()
            ->toArray();

        $itemMap = $this->afterSaleItemSnapshotMap($rows);
        $map = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $status = (int) ($row['status'] ?? 0);
            $type = (int) ($row['type'] ?? 0);
            $receiveStatus = (int) ($row['receive_status'] ?? 0);
            $interceptStatus = (string) ($row['intercept_status'] ?? '');
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            if (!isset($map[$orderId])) {
                $map[$orderId] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'sn' => (string) ($row['sn'] ?? ''),
                    'order_item_id' => $orderItemId,
                    'type' => $type,
                    'type_text' => RefundOrderStatus::typeTextOf($type),
                    'receive_status' => $receiveStatus,
                    'receive_status_text' => RefundOrderStatus::receiveTextOf($receiveStatus),
                    'status' => $status,
                    'status_text' => RefundOrderStatus::textOf($status),
                    'refund_amount' => (string) ($row['refund_amount'] ?? '0.00'),
                    'intercept_status' => $interceptStatus,
                    'intercept_status_text' => RefundOrderStatus::interceptTextOf($interceptStatus),
                    'create_time' => (string) ($row['create_time'] ?? ''),
                    'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                    'refunded_at' => (string) ($row['refunded_at'] ?? ''),
                    'canceled_at' => (string) ($row['canceled_at'] ?? ''),
                    'total' => 0,
                    'total_refund_amount' => '0.00',
                    'items' => [],
                ];
            }

            $item = $itemMap[$orderItemId] ?? [];
            $map[$orderId]['total']++;
            $map[$orderId]['total_refund_amount'] = $this->centsToDecimal(
                $this->decimalToCents((string) ($map[$orderId]['total_refund_amount'] ?? '0.00'))
                + $this->decimalToCents((string) ($row['refund_amount'] ?? '0.00'))
            );
            $map[$orderId]['items'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'sn' => (string) ($row['sn'] ?? ''),
                'order_item_id' => $orderItemId,
                'goods_name' => (string) ($item['goods_name'] ?? ''),
                'goods_image' => (string) ($item['goods_image'] ?? ''),
                'goods_image_full_url' => (string) ($item['goods_image_full_url'] ?? ''),
                'sku_spec' => (string) ($item['sku_spec'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'type' => $type,
                'type_text' => RefundOrderStatus::typeTextOf($type),
                'receive_status' => $receiveStatus,
                'receive_status_text' => RefundOrderStatus::receiveTextOf($receiveStatus),
                'status' => $status,
                'status_text' => RefundOrderStatus::textOf($status),
                'refund_amount' => (string) ($row['refund_amount'] ?? '0.00'),
            ];
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $refundRows
     * @return array<int, array<string, mixed>>
     */
    private function afterSaleItemSnapshotMap(array $refundRows): array
    {
        $orderItemIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['order_item_id'] ?? 0),
            $refundRows
        ))));
        if ($orderItemIds === []) {
            return [];
        }

        $rows = $this->model(OrderItem::class)
            ->whereIn('id', $orderItemIds)
            ->field('id, goods_name, goods_image, sku_spec')
            ->select()
            ->toArray();
        $rows = app()->make(AssetHydrator::class)->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $row;
            }
        }
        return $map;
    }

    private function afterSaleDays(): int
    {
        if (!function_exists('app')) {
            return 0;
        }

        try {
            return \app()->make(OrderSettingService::class)->afterSaleDays();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 查询订单并校验归属
     */
    private function findOwnedOrder(int $userId, int $orderId): Order
    {
        /** @var Order|null $order */
        $order = $this->model()
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }
        return $order;
    }

    /**
     * 取订单项（库存回滚用）
     *
     * @return array<int, array{sku_id:int, quantity:int}>
     */
    private function loadOrderItemsForStock(int $orderId): array
    {
        return $this->model(OrderItem::class)
            ->where('order_id', $orderId)
            ->field('sku_id, quantity')
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function hydrateOrderItems(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $orderIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $orders
        ))));
        if ($orderIds === []) {
            return $orders;
        }

        $refundBases = $this->refundBasesByOrderIds($orderIds);

        $rows = [];
        $indexes = [];
        foreach ($orders as $orderIndex => $order) {
            if (!is_array($order['items'] ?? null)) {
                continue;
            }
            foreach ($order['items'] as $itemIndex => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rows[] = $item;
                $indexes[] = [$orderIndex, $itemIndex];
            }
        }
        if ($rows === []) {
            return $orders;
        }

        $rows = app()->make(AssetHydrator::class)->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);
        $orderItemIds = array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $rows
        );
        $itemOccupiedCents = $this->refundOccupiedCentsByOrderItemIds($orderItemIds);
        $activeRefundItemIds = $this->activeRefundOrderItemIds($orderItemIds);
        $itemAfterSaleInfo = $this->latestRefundInfoByOrderItemIds($orderItemIds);

        foreach ($rows as $offset => $row) {
            $orderId = (int) $row['order_id'];
            $orderItemId = (int) ($row['id'] ?? 0);
            $refundableQuantity = max(
                0,
                (int) ($row['quantity'] ?? 0) - (int) ($row['refunded_quantity'] ?? 0)
            );
            $row['has_active_refund'] = isset($activeRefundItemIds[$orderItemId]);
            $row['after_sale'] = $itemAfterSaleInfo[$orderItemId] ?? null;
            $row['after_sale_status_text'] = (string) ($row['after_sale']['status_text'] ?? '');
            $row['refundable_quantity'] = $refundableQuantity;
            $row['refundable_amount'] = $this->calcItemRefundableAmount(
                $refundBases[$orderId] ?? null,
                $row,
                $refundableQuantity,
                $itemOccupiedCents[$orderItemId] ?? 0
            );
            [$orderIndex, $itemIndex] = $indexes[$offset];
            $orders[$orderIndex]['items'][$itemIndex] = $row;
        }
        return $orders;
    }

    /**
     * @param array<int, int> $orderItemIds
     * @return array<int, array<string, mixed>>
     */
    private function latestRefundInfoByOrderItemIds(array $orderItemIds): array
    {
        $orderItemIds = array_values(array_unique(array_filter(array_map('intval', $orderItemIds))));
        if ($orderItemIds === []) {
            return [];
        }

        $rows = $this->model(RefundOrder::class)
            ->whereIn('order_item_id', $orderItemIds)
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->field('id, order_item_id, type, receive_status, status, quantity, refund_amount')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            if ($orderItemId <= 0 || isset($map[$orderItemId])) {
                continue;
            }
            $status = (int) ($row['status'] ?? 0);
            $type = (int) ($row['type'] ?? 0);
            $receiveStatus = (int) ($row['receive_status'] ?? 0);
            $map[$orderItemId] = [
                'id' => (int) ($row['id'] ?? 0),
                'type' => $type,
                'type_text' => RefundOrderStatus::typeTextOf($type),
                'receive_status' => $receiveStatus,
                'receive_status_text' => RefundOrderStatus::receiveTextOf($receiveStatus),
                'status' => $status,
                'status_text' => RefundOrderStatus::textOf($status),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'refund_amount' => (string) ($row['refund_amount'] ?? '0.00'),
            ];
        }
        return $map;
    }

    /**
     * @param array<int, int> $orderIds
     * @return array<int, array{goods_paid_cents:int, remaining_cents:int}>
     */
    private function refundBasesByOrderIds(array $orderIds): array
    {
        $rows = $this->model()
            ->whereIn('id', $orderIds)
            ->field('id, total_amount, discount_amount, pay_amount')
            ->select()
            ->toArray();

        $bases = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $totalCents = $this->decimalToCents((string) ($row['total_amount'] ?? '0.00'));
            $discountCents = $this->decimalToCents((string) ($row['discount_amount'] ?? '0.00'));
            $payCents = $this->decimalToCents((string) ($row['pay_amount'] ?? '0.00'));
            $goodsPaidCents = min(max(0, $totalCents - $discountCents), $payCents);
            $bases[$orderId] = [
                'goods_paid_cents' => $goodsPaidCents,
                'remaining_cents' => $goodsPaidCents,
            ];
        }

        if ($bases === []) {
            return [];
        }

        $occupiedRows = $this->model(RefundOrder::class)
            ->whereIn('order_id', array_keys($bases))
            ->whereIn('status', $this->refundOccupiedStatuses())
            ->whereNull('delete_time')
            ->field('order_id, refund_amount')
            ->select()
            ->toArray();

        foreach ($occupiedRows as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            if (!isset($bases[$orderId])) {
                continue;
            }
            $bases[$orderId]['remaining_cents'] = max(
                0,
                $bases[$orderId]['remaining_cents']
                - $this->decimalToCents((string) ($row['refund_amount'] ?? '0.00'))
            );
        }

        return $bases;
    }

    /**
     * @param array{goods_paid_cents:int, remaining_cents:int}|null $basis
     * @param array<string, mixed> $item
     */
    private function calcItemRefundableAmount(?array $basis, array $item, int $quantity, int $itemOccupiedCents): string
    {
        if ($basis === null || $quantity <= 0) {
            return '0.00';
        }

        $goodsPaidCents = (int) ($basis['goods_paid_cents'] ?? 0);
        $remainingCents = (int) ($basis['remaining_cents'] ?? 0);
        if ($goodsPaidCents <= 0 || $remainingCents <= 0) {
            return '0.00';
        }

        $itemPaidCents = $this->decimalToCents((string) ($item['pay_amount'] ?? '0.00'));
        $itemQuantity = (int) ($item['quantity'] ?? 0);
        if ($itemPaidCents <= 0 || $itemQuantity <= 0) {
            return '0.00';
        }

        $itemRemainingCents = max(0, $itemPaidCents - $itemOccupiedCents);
        if ($itemRemainingCents <= 0) {
            return '0.00';
        }

        $remainingQuantity = max(0, $itemQuantity - (int) ($item['refunded_quantity'] ?? 0));
        if ($quantity >= $remainingQuantity) {
            $refundCents = $itemRemainingCents;
        } else {
            $refundCents = intdiv($itemPaidCents * $quantity, $itemQuantity);
            if ($refundCents <= 0) {
                $refundCents = min($itemRemainingCents, 1);
            }
        }

        return $this->centsToDecimal(min($refundCents, $itemRemainingCents, $remainingCents));
    }

    /**
     * @param array<int, int> $orderItemIds
     * @return array<int, int>
     */
    private function refundOccupiedCentsByOrderItemIds(array $orderItemIds): array
    {
        $orderItemIds = array_values(array_unique(array_filter(array_map('intval', $orderItemIds))));
        if ($orderItemIds === []) {
            return [];
        }

        $rows = $this->model(RefundOrder::class)
            ->whereIn('order_item_id', $orderItemIds)
            ->whereIn('status', $this->refundOccupiedStatuses())
            ->whereNull('delete_time')
            ->field('order_item_id, refund_amount')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            if ($orderItemId <= 0) {
                continue;
            }
            $map[$orderItemId] = ($map[$orderItemId] ?? 0)
                + $this->decimalToCents((string) ($row['refund_amount'] ?? '0.00'));
        }
        return $map;
    }

    /**
     * @param array<int, int> $orderItemIds
     * @return array<int, bool>
     */
    private function activeRefundOrderItemIds(array $orderItemIds): array
    {
        $orderItemIds = array_values(array_unique(array_filter(array_map('intval', $orderItemIds))));
        if ($orderItemIds === []) {
            return [];
        }

        $rows = $this->model(RefundOrder::class)
            ->whereIn('order_item_id', $orderItemIds)
            ->whereIn('status', RefundOrderStatus::activeStatuses())
            ->whereNull('delete_time')
            ->field('order_item_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            if ($orderItemId > 0) {
                $map[$orderItemId] = true;
            }
        }
        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function refundOccupiedStatuses(): array
    {
        return array_merge(RefundOrderStatus::activeStatuses(), [RefundOrderStatus::COMPLETED]);
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

    private function centsToDecimal(int $amountCents): string
    {
        return sprintf('%d.%02d', intdiv($amountCents, 100), $amountCents % 100);
    }

    /**
     * @param array<int, int> $skuIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchSkuMap(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $rows = $this->model(GoodsSku::class)
            ->whereIn('id', $skuIds)
            ->column('id, goods_id, spec_values, price, stock, status, weight, member_price', 'id');
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $goodsIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchGoodsMap(array $goodsIds): array
    {
        if ($goodsIds === []) {
            return [];
        }
        $rows = $this->model(Goods::class)
            ->whereIn('id', $goodsIds)
            ->whereNull('delete_time')
            ->column('id, name, main_image, status, is_on_sale, freight_template_id, member_benefit_mode', 'id');
        return is_array($rows) ? $rows : [];
    }

    /**
     * 幂等 key 装配（带用户作用域防撞）
     */
    private function buildIdempotencyKey(int $userId, ?string $clientKey): string
    {
        $key = $clientKey !== null && $clientKey !== '' ? $clientKey : bin2hex(random_bytes(16));
        // 限长并去除可疑字符
        $key = preg_replace('/[^A-Za-z0-9\-_.]/', '', $key) ?? '';
        $key = mb_substr($key, 0, 64);
        return sprintf('%d:%s', $userId, $key);
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new BusinessException('用户未登录');
        }
    }
}
