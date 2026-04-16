<?php

declare(strict_types=1);

namespace app\admin\service\order;

use app\admin\model\order\Order;
use app\admin\model\order\OrderLog;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Db;

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

        $this->transaction(function () use ($order, $items, $machine, $stock, $adminId, $reason): void {
            $machine->transit(
                order: $order,
                toStatus: OrderStatus::CLOSED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : '管理员关闭订单',
            );
            $stock->restoreBatch($items);
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

                $this->transaction(function () use ($order, $items, $machine, $stock): void {
                    $machine->transit(
                        order: $order,
                        toStatus: OrderStatus::CLOSED,
                        operatorType: OperatorType::SYSTEM,
                        operatorId: null,
                        remark: '支付超时自动关闭',
                    );
                    $stock->restoreBatch($items);
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
            $activeOrderIds = Db::name('refund_order')
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
        $data['logs']                = app()->make(OrderLog::class)
            ->where('order_id', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $data['after_sale_tag_text'] = $this->aggregateAfterSaleTags([$orderId])[$orderId] ?? '';

        return $data;
    }

    // ---------------- 内部 ----------------

    private function findOrder(int $orderId): Order
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
        return Db::name('order_item')
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
        $rows = Db::name('refund_order')
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
