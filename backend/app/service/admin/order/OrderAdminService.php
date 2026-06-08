<?php

declare(strict_types=1);

namespace app\service\admin\order;

use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\OrderLog;
use app\model\order\PaymentLog;
use app\model\order\RefundOrder;
use app\service\order\OrderStatusMachine;
use app\service\order\OrderSettingService;
use app\service\order\StockService;
use app\service\order\WechatPrepayCloseService;
use app\service\upload\AssetHydrator;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台订单服务（发货 / 关闭 / 超时关单 / 管理列表）
 *
 * 原则：
 *  - 状态流转统一走 {@see OrderStatusMachine}
 *  - 取消/关闭路径必须同步回滚库存（调用 {@see StockService::restoreBatch}）
 *  - 列表接口实时聚合 refund_order 得到 after_sale_tag_text，**不落库**
 *
 * @extends BaseService<Order>
 */
class OrderAdminService extends BaseService
{
    protected string $modelClass = Order::class;

    /**
     * 发货（PAID → SHIPPED）
     *
     * @param int    $orderId
     * @param string $logisticsCompany
     * @param string $logisticsSn
     * @param int    $adminId
     */
    public function ship(int $orderId, string $logisticsCompany, string $logisticsSn, int $adminId): void
    {
        $company = trim($logisticsCompany);
        $sn      = trim($logisticsSn);
        if ($company === '' || $sn === '') {
            throw new BusinessException('物流公司和运单号必填');
        }

        $order = $this->findOrder($orderId);
        if ((int) $order->status !== OrderStatus::PAID) {
            throw new BusinessException('仅已支付订单允许发货');
        }

        /** @var OrderStatusMachine $machine */
        $machine = app()->make(OrderStatusMachine::class);

        $this->transaction(function () use ($order, $company, $sn, $machine, $adminId): void {
            $order->logistics_company = mb_substr($company, 0, 100);
            $order->logistics_sn      = mb_substr($sn, 0, 100);
            $order->save();

            $machine->transit(
                order: $order,
                toStatus: OrderStatus::SHIPPED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: sprintf('发货：%s %s', $company, $sn),
            );
        });
    }

    /**
     * 订单改价（仅 PENDING_PAY）
     *
     * 业务规则：
     *  - 运费 ≥ 0；优惠允许负数（视为加价）
     *  - 应付金额由后端权威重算：pay_amount = total + freight - discount，且必须 > 0
     *  - 事务内同步将旧 PREPAY 流水改为 SUPERSEDED，避免 PrepayService::findReusablePrepay
     *    用过期金额的 prepay_id 拉起支付
     *  - 仅写入 OrderLog 做审计，不触发状态机（同状态自环）
     *
     * @param int         $orderId
     * @param string      $freight  非负金额字符串
     * @param string      $discount 任意金额字符串（负数=加价）
     * @param int         $adminId
     * @param string|null $reason
     */
    public function adjustPrice(
        int $orderId,
        string $freight,
        string $discount,
        int $adminId,
        ?string $reason = null
    ): void {
        $order = $this->findOrder($orderId);
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('仅待支付订单允许改价');
        }
        if (bccomp($freight, '0', 2) < 0) {
            throw new BusinessException('运费不能为负');
        }

        $newPay = bcsub(bcadd((string) $order->total_amount, $freight, 2), $discount, 2);
        if (bccomp($newPay, '0', 2) !== 1) {
            throw new BusinessException('改价后应付金额必须大于 0');
        }

        // 事务外快照旧值，事务内只做写入
        $oldPay      = (string) $order->pay_amount;
        $oldFreight  = (string) $order->freight_amount;
        $oldDiscount = (string) $order->discount_amount;
        $ip          = $this->requestIp();
        $remarkPart  = $reason !== null && $reason !== ''
            ? '; 原因:' . mb_substr($reason, 0, 200)
            : '';
        $remark = sprintf(
            '改价: 应付 %s→%s,运费 %s→%s,优惠 %s→%s%s',
            $oldPay,
            $newPay,
            $oldFreight,
            $freight,
            $oldDiscount,
            $discount,
            $remarkPart
        );
        $prepayClose = $this->prepayCloseService();
        $prepayLogs = $prepayClose->activePrepayLogs((int) $order->id);
        $prepayClose->closeLogs($prepayLogs);
        $prepayLogIds = $prepayClose->idsOf($prepayLogs);

        $this->transaction(function () use ($order, $freight, $discount, $newPay, $adminId, $remark, $ip, $prepayLogIds): void {
            // 1) 写订单金额
            $order->freight_amount  = $freight;
            $order->discount_amount = $discount;
            $order->pay_amount      = $newPay;
            $order->save();

            // 2) 顶替旧 prepay 流水，避免复用过期金额
            $this->model(PaymentLog::class)
                ->where('order_id', (int) $order->id)
                ->where('event_type', PaymentLog::EVENT_PREPAY)
                ->update(['event_type' => PaymentLog::EVENT_SUPERSEDED]);
            if ($prepayLogIds !== []) {
                $this->model(PaymentLog::class)
                    ->whereIn('id', $prepayLogIds)
                    ->update(['event_type' => PaymentLog::EVENT_SUPERSEDED]);
            }

            // 3) 审计日志（同状态自环，仅记录改价动作）
            $this->model(OrderLog::class)->save([
                'order_id'      => (int) $order->id,
                'from_status'   => OrderStatus::PENDING_PAY,
                'to_status'     => OrderStatus::PENDING_PAY,
                'operator_type' => OperatorType::ADMIN,
                'operator_id'   => $adminId,
                'remark'        => mb_substr($remark, 0, 255),
                'ip'            => $ip !== '' ? $ip : null,
            ]);
        });
    }

    protected function prepayCloseService(): WechatPrepayCloseService
    {
        /** @var WechatPrepayCloseService $service */
        $service = app()->make(WechatPrepayCloseService::class);
        return $service;
    }

    protected function requestIp(): string
    {
        if (!function_exists('request')) {
            return '';
        }
        return (string) request()->ip();
    }

    /**
     * 后台主动关闭订单（PENDING_PAY / PAID 可关闭）
     *
     * 事务内：状态机流转 CLOSED → 批量回滚库存
     */
    public function closeOrder(int $orderId, int $adminId, ?string $reason = null): void
    {
        $order = $this->findOrder($orderId);
        $from  = (int) $order->status;
        if (!in_array($from, [OrderStatus::PENDING_PAY, OrderStatus::PAID], true)) {
            throw new BusinessException('当前订单状态不允许关闭');
        }

        /** @var OrderStatusMachine $machine */
        $machine = app()->make(OrderStatusMachine::class);
        /** @var StockService $stock */
        $stock = app()->make(StockService::class);
        $items = $this->loadOrderItemsForStock($orderId);
        $prepayLogIds = [];
        if ($from === OrderStatus::PENDING_PAY) {
            /** @var WechatPrepayCloseService $prepayClose */
            $prepayClose = app()->make(WechatPrepayCloseService::class);
            $prepayLogs = $prepayClose->activePrepayLogs((int) $order->id);
            $prepayClose->closeLogs($prepayLogs);
            $prepayLogIds = $prepayClose->idsOf($prepayLogs);
        }

        $this->transaction(function () use ($order, $items, $machine, $stock, $adminId, $reason, $prepayLogIds): void {
            $machine->transit(
                order: $order,
                toStatus: OrderStatus::CLOSED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : '管理员关闭订单',
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
     * 扫描并关闭超时未支付订单（定时任务入口）
     *
     * 每分钟调用一次：status=PENDING_PAY 且 expire_at<now
     * 每个订单独立事务，避免单条失败影响整批
     *
     * @param int $limit 单次最大处理量，防止长事务
     * @return array{scanned:int, closed:int}
     */
    public function closeExpired(int $limit = 500): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = $this->model()
            ->where('status', OrderStatus::PENDING_PAY)
            ->where('expire_at', '<', $now)
            ->whereNull('delete_time')
            ->limit($limit)
            ->column('id');

        $scanned = count($rows);
        $closed  = 0;

        /** @var OrderStatusMachine $machine */
        $machine = app()->make(OrderStatusMachine::class);
        /** @var StockService $stock */
        $stock = app()->make(StockService::class);

        foreach ($rows as $id) {
            $orderId = (int) $id;
            try {
                /** @var Order|null $order */
                $order = $this->model()->where('id', $orderId)->whereNull('delete_time')->find();
                if ($order === null || (int) $order->status !== OrderStatus::PENDING_PAY) {
                    continue;
                }
                $items = $this->loadOrderItemsForStock($orderId);
                /** @var WechatPrepayCloseService $prepayClose */
                $prepayClose = app()->make(WechatPrepayCloseService::class);
                $prepayLogs = $prepayClose->activePrepayLogs($orderId);
                $prepayClose->closeLogs($prepayLogs);
                $prepayLogIds = $prepayClose->idsOf($prepayLogs);

                $this->transaction(function () use ($order, $items, $machine, $stock, $prepayLogIds): void {
                    $machine->transit(
                        order: $order,
                        toStatus: OrderStatus::CLOSED,
                        operatorType: OperatorType::SYSTEM,
                        operatorId: null,
                        remark: '支付超时自动关闭',
                    );
                    $stock->restoreBatch($items);
                    if ($prepayLogIds !== []) {
                        $this->model(PaymentLog::class)
                            ->whereIn('id', $prepayLogIds)
                            ->update(['event_type' => PaymentLog::EVENT_CLOSED]);
                    }
                });
                $closed++;
            } catch (\Throwable $e) {
                // 单条异常不中断批量，写入日志后继续
                OrderLog::create([
                    'order_id'      => $orderId,
                    'from_status'   => OrderStatus::PENDING_PAY,
                    'to_status'     => OrderStatus::PENDING_PAY,
                    'operator_type' => OperatorType::SYSTEM,
                    'operator_id'   => null,
                    'remark'        => '超时关单失败：' . mb_substr($e->getMessage(), 0, 200),
                    'ip'            => null,
                ]);
            }
        }

        return ['scanned' => $scanned, 'closed' => $closed];
    }

    /**
     * 扫描并自动确认收货（定时任务入口）
     *
     * @param int $limit 单次最大处理量
     * @return array{scanned:int, received:int}
     */
    public function autoReceiveExpired(int $limit = 500): array
    {
        /** @var OrderSettingService $setting */
        $setting = app()->make(OrderSettingService::class);
        $deadline = date('Y-m-d H:i:s', time() - $setting->autoReceiveDays() * 86400);
        $rows = $this->model()
            ->where('status', OrderStatus::SHIPPED)
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', $deadline)
            ->whereNull('delete_time')
            ->limit($limit)
            ->column('id');

        $scanned = count($rows);
        $received = 0;
        /** @var OrderStatusMachine $machine */
        $machine = app()->make(OrderStatusMachine::class);

        foreach ($rows as $id) {
            $orderId = (int) $id;
            try {
                /** @var Order|null $order */
                $order = $this->model()->where('id', $orderId)->whereNull('delete_time')->find();
                if ($order === null || (int) $order->status !== OrderStatus::SHIPPED) {
                    continue;
                }

                $this->transaction(function () use ($order, $machine): void {
                    $machine->transit(
                        order: $order,
                        toStatus: OrderStatus::RECEIVED,
                        operatorType: OperatorType::SYSTEM,
                        operatorId: null,
                        remark: '发货后超时自动确认收货',
                    );
                });
                $received++;
            } catch (\Throwable $e) {
                OrderLog::create([
                    'order_id'      => $orderId,
                    'from_status'   => OrderStatus::SHIPPED,
                    'to_status'     => OrderStatus::SHIPPED,
                    'operator_type' => OperatorType::SYSTEM,
                    'operator_id'   => null,
                    'remark'        => '自动确认收货失败：' . mb_substr($e->getMessage(), 0, 200),
                    'ip'            => null,
                ]);
            }
        }

        return ['scanned' => $scanned, 'received' => $received];
    }

    /**
     * 后台订单列表（分页）
     *
     * 条件同源 + 实时聚合 refund_order 得出 after_sale_tag_text
     *
     * @param array{sn?:string, status?:int|null, user_id?:int|null, logistics_sn?:string, created_start?:string, created_end?:string, has_after_sale?:bool|null} $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function adminList(array $filter = [], int $page = 1, int $pageSize = 10): array
    {
        $query = $this->model()->whereNull('delete_time');

        if (!empty($filter['sn'])) {
            $query->where('sn', 'like', '%' . trim((string) $filter['sn']) . '%');
        }
        if (isset($filter['status']) && $filter['status'] !== null && $filter['status'] !== '') {
            $query->where('status', (int) $filter['status']);
        }
        if (!empty($filter['user_id'])) {
            $query->where('user_id', (int) $filter['user_id']);
        }
        if (!empty($filter['logistics_sn'])) {
            $query->where('logistics_sn', 'like', '%' . trim((string) $filter['logistics_sn']) . '%');
        }
        if (!empty($filter['created_start'])) {
            $query->where('create_time', '>=', (string) $filter['created_start']);
        }
        if (!empty($filter['created_end'])) {
            $query->where('create_time', '<=', (string) $filter['created_end']);
        }

        // has_after_sale：两步查询兼容多种数据库前缀
        // 先查出所有存在进行中售后单的 order_id，再 whereIn/whereNotIn
        // MVP 量级够用，百万级可改冗余字段 after_sale_tag
        if (isset($filter['has_after_sale']) && $filter['has_after_sale'] !== null && $filter['has_after_sale'] !== '') {
            $activeOrderIds = $this->model(RefundOrder::class)
                ->whereIn('status', RefundOrderStatus::activeStatuses())
                ->whereNull('delete_time')
                ->distinct(true)
                ->column('order_id');
            $activeOrderIds = array_values(array_unique(array_map('intval', $activeOrderIds)));

            if ((bool) $filter['has_after_sale']) {
                // 无任何进行中售后单 → 命中集合为空，列表必定为空
                $query->whereIn('id', $activeOrderIds ?: [0]);
            } else {
                if ($activeOrderIds !== []) {
                    $query->whereNotIn('id', $activeOrderIds);
                }
            }
        }

        $total = (clone $query)->count();
        $list = $query
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $orderIds = array_map(static fn(array $r): int => (int) $r['id'], $list);
        $itemsMap = $this->fetchItemsByOrderIds($orderIds);
        $tagMap   = $this->aggregateAfterSaleTags($orderIds);

        foreach ($list as &$row) {
            $oid = (int) $row['id'];
            $row['items']               = $itemsMap[$oid] ?? [];
            $row['after_sale_tag_text'] = $tagMap[$oid] ?? '';
        }
        unset($row);

        return compact('total', 'list');
    }

    /**
     * 后台订单详情（含订单项 + 日志时间轴 + 售后标签）
     */
    public function adminDetail(int $orderId): array
    {
        $order = $this->findOrder($orderId);
        $data  = $order->toArray();

        $data['items']               = $this->fetchItemsByOrderIds([$orderId])[$orderId] ?? [];
        $data['logs']                = $this->model(OrderLog::class)
            ->where('order_id', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $data['after_sale_tag_text'] = $this->aggregateAfterSaleTags([$orderId])[$orderId] ?? '';

        return $data;
    }

    // ---------------- 内部 ----------------

    protected function findOrder(int $orderId): Order
    {
        /** @var Order|null $order */
        $order = $this->model()->where('id', $orderId)->whereNull('delete_time')->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }
        return $order;
    }

    /**
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
     * 批量查订单项
     *
     * @param array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function fetchItemsByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = $this->model(OrderItem::class)
            ->whereIn('order_id', $orderIds)
            ->select()
            ->toArray();
        $rows = app()->make(AssetHydrator::class)->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['order_id']][] = $row;
        }
        return $map;
    }

    /**
     * 按订单 ID 聚合进行中的售后标签
     *
     * 规则：若订单下存在任一进行中售后单，取最新一条的 status_text 作为 after_sale_tag_text
     *
     * @param array<int, int> $orderIds
     * @return array<int, string>
     */
    private function aggregateAfterSaleTags(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = $this->model(RefundOrder::class)
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', RefundOrderStatus::activeStatuses())
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->field('order_id, status')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $oid = (int) $row['order_id'];
            // order desc 后第一条即最新
            if (!isset($map[$oid])) {
                $map[$oid] = RefundOrderStatus::textOf((int) $row['status']);
            }
        }
        return $map;
    }
}
