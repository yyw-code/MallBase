<?php

declare(strict_types=1);

namespace app\service\client\order;

use app\model\order\Cart;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\OrderLog;
use app\service\order\OrderSnGenerator;
use app\service\order\OrderStatusMachine;
use app\service\order\StockService;
use app\model\user\UserAddress;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use app\common\service\IdempotencyService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Db;

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
     * 下单默认支付超时（秒）：15 分钟
     * 秒杀/促销场景可通过参数覆盖（本 MVP 未开放）
     */
    public const DEFAULT_PAY_EXPIRE_SECONDS = 900;

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
        ?string $idempotencyKey = null
    ): array {
        $this->assertUserId($userId);

        // 幂等抢占（事务外，避免事务内触发网络 IO）
        $idem = $this->idempotencyService();
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
            $amounts = $this->calcAmounts($items);

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
        ?string $idempotencyKey = null
    ): array {
        $this->assertUserId($userId);
        if ($items === []) {
            throw new BusinessException('请选择要购买的商品');
        }

        $idem = $this->idempotencyService();
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
            $amounts  = $this->calcAmounts($resolved);

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
     * Mock 支付（MVP 不接真实渠道）
     *
     * 真实渠道接入时，将本方法包装在 PaymentAdapter 后面，OrderService 内部流程不变
     */
    public function pay(string $sn, int $payMethod, ?string $tradeNo = null): array
    {
        if (!PayMethod::isValid($payMethod)) {
            throw new BusinessException('支付方式不合法');
        }

        /** @var Order|null $order */
        $order = $this->model()->where('sn', $sn)->whereNull('delete_time')->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('订单已支付或已关闭');
        }
        if ($order->expire_at !== null && strtotime((string) $order->expire_at) < time()) {
            throw new BusinessException('订单已超时，请重新下单');
        }

        $machine = app()->make(OrderStatusMachine::class);
        $this->transaction(function () use ($order, $payMethod, $tradeNo, $machine): void {
            $order->pay_method = $payMethod;
            $order->trade_no   = $tradeNo !== null ? mb_substr($tradeNo, 0, 64) : 'MOCK-' . $order->sn;
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

        $machine = app()->make(OrderStatusMachine::class);
        $stock   = app()->make(StockService::class);

        $items = $this->loadOrderItemsForStock((int) $order->id);

        $this->transaction(function () use ($order, $items, $machine, $stock, $reason, $userId): void {
            $machine->transit(
                order: $order,
                toStatus: OrderStatus::CLOSED,
                operatorType: OperatorType::BUYER,
                operatorId: $userId,
                remark: $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : '买家取消订单',
            );
            $stock->restoreBatch($items);
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
     * @param array{status?:int|null} $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function list(int $userId, array $filter = [], int $page = 1, int $pageSize = 10): array
    {
        $query = $this->model()
            ->where('user_id', $userId)
            ->whereNull('delete_time');

        if (isset($filter['status']) && $filter['status'] !== null && $filter['status'] !== '') {
            $query->where('status', (int) $filter['status']);
        }

        $total = (clone $query)->count();
        $list = $query
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $orderIds = array_map(static fn(array $r): int => (int) $r['id'], $list);
        $itemsMap = $this->fetchItemsByOrderIds($orderIds);
        foreach ($list as &$row) {
            $row['items'] = $itemsMap[(int) $row['id']] ?? [];
        }
        unset($row);

        return compact('total', 'list');
    }

    /**
     * 买家订单详情
     */
    public function detail(int $userId, int $orderId): array
    {
        $order = $this->findOwnedOrder($userId, $orderId);

        $data = $order->toArray();
        $data['items'] = $this->fetchItemsByOrderIds([(int) $order->id])[(int) $order->id] ?? [];
        $data['logs']  = app()->make(OrderLog::class)
            ->where('order_id', (int) $order->id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $data;
    }

    // ---------------- 内部：校验 / 装配 / 落库 ----------------

    /**
     * 事务内：原子化下单落库
     *
     * @param array<int, array{sku_id:int, goods_id:int, goods_name:string, goods_image:string, sku_spec:string, unit_price:string, quantity:int, cart_id?:int}> $items
     * @param array{total_amount:string, freight_amount:string, discount_amount:string, pay_amount:string} $amounts
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
                'expire_at'         => date('Y-m-d H:i:s', time() + self::DEFAULT_PAY_EXPIRE_SECONDS),
            ]);

            // 3. 落订单项快照
            $itemModel = app()->make(OrderItem::class);
            foreach ($items as $item) {
                $itemModel->newInstance()->save([
                    'order_id'    => (int) $order->id,
                    'goods_id'    => $item['goods_id'],
                    'sku_id'      => $item['sku_id'],
                    'goods_name'  => mb_substr($item['goods_name'], 0, 200),
                    'goods_image' => $item['goods_image'],
                    'sku_spec'    => mb_substr($item['sku_spec'], 0, 500),
                    'unit_price'  => $item['unit_price'],
                    'quantity'    => $item['quantity'],
                    'subtotal'    => bcmul($item['unit_price'], (string) $item['quantity'], 2),
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
     * 校验地址归属并返回落库所需字段
     *
     * @return array{name:string, phone:string, province:string, city:string, district:string, address:string}
     */
    private function assertOwnedAddress(int $userId, int $addressId): array
    {
        /** @var UserAddress|null $address */
        $address = app()->make(UserAddress::class)
            ->where('id', $addressId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($address === null) {
            throw new BusinessException('收货地址不存在');
        }

        return [
            'name'     => (string) $address->name,
            'phone'    => (string) $address->phone,
            'province' => (string) ($address->province ?? ''),
            'city'     => (string) ($address->city ?? ''),
            'district' => (string) ($address->district ?? ''),
            'address'  => (string) ($address->address ?? ''),
        ];
    }

    /**
     * 加载选中的购物车行并聚合为下单项
     *
     * @param array<int, int> $cartIds
     * @return array<int, array{sku_id:int, goods_id:int, goods_name:string, goods_image:string, sku_spec:string, unit_price:string, quantity:int, cart_id:int}>
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

        $carts = app()->make(Cart::class)
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
                'cart_id'     => (int) $row['id'],
                'sku_id'      => $skuId,
                'goods_id'    => $goodsId,
                'goods_name'  => (string) ($goods['name'] ?? ''),
                'goods_image' => (string) ($goods['main_image'] ?? ''),
                'sku_spec'    => (string) ($sku['spec_values'] ?? ''),
                'unit_price'  => (string) $sku['price'],
                'quantity'    => $qty,
            ];
        }
        return $items;
    }

    /**
     * 立即购买：解析传入的 SKU/数量为下单项
     *
     * @param array<int, array{sku_id:int, quantity:int}> $items
     * @return array<int, array{sku_id:int, goods_id:int, goods_name:string, goods_image:string, sku_spec:string, unit_price:string, quantity:int}>
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
                'sku_id'      => $skuId,
                'goods_id'    => $goodsId,
                'goods_name'  => (string) ($goods['name'] ?? ''),
                'goods_image' => (string) ($goods['main_image'] ?? ''),
                'sku_spec'    => (string) ($sku['spec_values'] ?? ''),
                'unit_price'  => (string) $sku['price'],
                'quantity'    => (int) $it['quantity'],
            ];
        }
        return $resolved;
    }

    /**
     * 计算订单金额
     *
     * MVP 不接入优惠/运费计算，保留字段以便后续迭代不改 Schema
     *
     * @param array<int, array{unit_price:string, quantity:int}> $items
     * @return array{total_amount:string, freight_amount:string, discount_amount:string, pay_amount:string}
     */
    private function calcAmounts(array $items): array
    {
        $total = '0.00';
        foreach ($items as $item) {
            $sub = bcmul($item['unit_price'], (string) $item['quantity'], 2);
            $total = bcadd($total, $sub, 2);
        }
        $freight  = '0.00';
        $discount = '0.00';
        $pay      = bcsub(bcadd($total, $freight, 2), $discount, 2);

        return [
            'total_amount'    => $total,
            'freight_amount'  => $freight,
            'discount_amount' => $discount,
            'pay_amount'      => $pay,
        ];
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
        return Db::name('order_item')
            ->where('order_id', $orderId)
            ->field('sku_id, quantity')
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function fetchItemsByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = Db::name('order_item')
            ->whereIn('order_id', $orderIds)
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $row['goods_image_full_url'] = buildUploadUrl((string) ($row['goods_image'] ?? ''));
            $map[(int) $row['order_id']][] = $row;
        }
        return $map;
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
        $rows = Db::name('goods_sku')
            ->whereIn('id', $skuIds)
            ->column('id, goods_id, spec_values, price, stock, status', 'id');
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
        $rows = Db::name('goods')
            ->whereIn('id', $goodsIds)
            ->whereNull('delete_time')
            ->column('id, name, main_image, status, is_on_sale', 'id');
        return is_array($rows) ? $rows : [];
    }

    private function idempotencyService(): IdempotencyService
    {
        return app()->make(IdempotencyService::class);
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
