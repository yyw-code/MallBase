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
use app\service\logistics\LogisticsService;
use app\service\admin\support\CsvExportService;
use app\service\upload\AssetHydrator;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
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

    public const ADJUST_MODE_ITEM_DISCOUNT = 'item_discount';
    public const ADJUST_MODE_PAY_PERCENT = 'pay_percent';

    /**
     * 发货或修改物流信息。
     *
     * 已支付订单：写入物流信息并流转为已发货。
     * 已发货订单：仅更新物流信息和本地轨迹快照，不重复流转状态。
     *
     * @param int    $orderId
     * @param string $logisticsPlatform
     * @param int    $logisticsCompanyId
     * @param string $logisticsCompanyCode
     * @param string $logisticsCompany
     * @param string $logisticsSn
     * @param int    $adminId
     */
    public function ship(
        int $orderId,
        string $logisticsPlatform,
        int $logisticsCompanyId,
        string $logisticsCompanyCode,
        string $logisticsCompany,
        string $logisticsSn,
        int $adminId
    ): string {
        $platform    = trim($logisticsPlatform);
        $companyCode = trim($logisticsCompanyCode);
        $companyName = trim($logisticsCompany);
        $sn          = trim($logisticsSn);
        if (($logisticsCompanyId <= 0 && $companyCode === '' && $companyName === '') || $sn === '') {
            throw new BusinessException('物流公司和运单号必填');
        }

        $order = $this->findOrder($orderId);
        $status = (int) $order->status;
        if (!in_array($status, [OrderStatus::PAID, OrderStatus::SHIPPED], true)) {
            throw new BusinessException('仅已支付或已发货订单允许维护物流信息');
        }

        /** @var LogisticsService $logisticsService */
        $logisticsService = app()->make(LogisticsService::class);
        $company = $logisticsService->resolveCompany($platform, $logisticsCompanyId, $companyCode, $companyName);
        $oldLogistics = trim(sprintf('%s %s', (string) ($order->logistics_company ?? ''), (string) ($order->logistics_sn ?? '')));
        $ip = $this->requestIp();

        /** @var OrderStatusMachine $machine */
        $machine = app()->make(OrderStatusMachine::class);

        $this->transaction(function () use ($order, $status, $company, $sn, $machine, $adminId, $logisticsService, $oldLogistics, $ip): void {
            $order->logistics_platform     = mb_substr($company['platform'], 0, 32);
            $order->logistics_company_id   = (int) $company['company_id'];
            $order->logistics_company_code = mb_substr($company['code'], 0, 64);
            $order->logistics_company      = mb_substr($company['name'], 0, 100);
            $order->logistics_sn           = mb_substr($sn, 0, 64);
            $order->save();

            $logisticsService->syncOrderShipment($order);

            if ($status === OrderStatus::SHIPPED) {
                $newLogistics = trim(sprintf('%s %s', $company['name'], $sn));
                $this->model(OrderLog::class)->save([
                    'order_id'      => (int) $order->id,
                    'from_status'   => OrderStatus::SHIPPED,
                    'to_status'     => OrderStatus::SHIPPED,
                    'operator_type' => OperatorType::ADMIN,
                    'operator_id'   => $adminId,
                    'remark'        => mb_substr(sprintf('修改物流：%s -> %s', $oldLogistics !== '' ? $oldLogistics : '空', $newLogistics), 0, 255),
                    'ip'            => $ip !== '' ? $ip : null,
                ]);
                return;
            }

            $machine->transit(
                order: $order,
                toStatus: OrderStatus::SHIPPED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: sprintf('发货：%s %s', $company['name'], $sn),
            );
        });

        return $status === OrderStatus::SHIPPED ? '物流信息已更新' : '发货成功';
    }

    /**
     * 订单改价（仅 PENDING_PAY）
     *
     * 业务规则：
     *  - 运费 ≥ 0；优惠按订单项落快照，支持逐商品优惠或整单实付比例
     *  - 应付金额由后端权威重算：pay_amount = sum(item.pay_amount) + freight，且必须 > 0
     *  - 事务内同步将旧 PREPAY 流水改为 SUPERSEDED，避免 PrepayService::findReusablePrepay
     *    用过期金额的 prepay_id 拉起支付
     *  - 仅写入 OrderLog 做审计，不触发状态机（同状态自环）
     *
     * @param int         $orderId
     * @param string      $freight  非负金额字符串
     * @param string      $adjustMode item_discount=逐商品优惠，pay_percent=整单实付比例
     * @param int         $adminId
     * @param array<int, array<string, mixed>> $itemDiscounts
     * @param string|null $reason
     */
    public function adjustPrice(
        int $orderId,
        string $freight,
        string $adjustMode,
        int $adminId,
        array $itemDiscounts = [],
        ?string $payPercent = null,
        ?string $reason = null
    ): void {
        $order = $this->findOrder($orderId);
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('仅待支付订单允许改价');
        }

        if (str_starts_with(trim($freight), '-')) {
            throw new BusinessException('运费不能为负');
        }
        $freightCents = $this->moneyToCents($freight, '运费');
        $freight = $this->centsToDecimal($freightCents);
        $items = $this->loadAdjustableOrderItems((int) $order->id);
        $itemAdjustments = $this->buildItemAdjustments($items, $adjustMode, $itemDiscounts, $payPercent);

        $discountCents = 0;
        $goodsPayCents = 0;
        foreach ($itemAdjustments as $item) {
            $discountCents += $item['discount_cents'];
            $goodsPayCents += $item['pay_cents'];
        }
        $newDiscount = $this->centsToDecimal($discountCents);
        $newPay = $this->centsToDecimal($goodsPayCents + $freightCents);
        if (($goodsPayCents + $freightCents) <= 0) {
            throw new BusinessException('改价后应付金额必须大于 0');
        }

        // 事务外快照旧值，事务内只做写入
        $oldPay      = (string) $order->pay_amount;
        $oldFreight  = (string) $order->freight_amount;
        $oldDiscount = (string) $order->discount_amount;
        $modeText     = $adjustMode === self::ADJUST_MODE_PAY_PERCENT
            ? '整单实付' . $this->formatPercent($this->percentToBasisPoints($payPercent)) . '%'
            : '商品优惠';
        $ip          = $this->requestIp();
        $remarkPart  = $reason !== null && $reason !== ''
            ? '; 原因:' . mb_substr($reason, 0, 200)
            : '';
        $remark = sprintf(
            '改价: 应付 %s→%s,运费 %s→%s,优惠 %s→%s,方式:%s%s',
            $oldPay,
            $newPay,
            $oldFreight,
            $freight,
            $oldDiscount,
            $newDiscount,
            $modeText,
            $remarkPart
        );
        $prepayClose = $this->prepayCloseService();
        $prepayLogs = $prepayClose->activePrepayLogs((int) $order->id);
        $prepayClose->closeLogs($prepayLogs);
        $prepayLogIds = $prepayClose->idsOf($prepayLogs);

        $this->transaction(function () use (
            $order,
            $freight,
            $newDiscount,
            $newPay,
            $itemAdjustments,
            $adminId,
            $remark,
            $ip,
            $prepayLogIds
        ): void {
            // 1) 写订单金额
            $order->freight_amount  = $freight;
            $order->discount_amount = $newDiscount;
            $order->pay_amount      = $newPay;
            $order->save();

            // 2) 写订单项优惠/实付快照，后续退款按订单项口径计算
            foreach ($itemAdjustments as $item) {
                $this->model(OrderItem::class)
                    ->where('id', $item['id'])
                    ->where('order_id', (int) $order->id)
                    ->update([
                        'discount_amount' => $item['discount_amount'],
                        'pay_amount'      => $item['pay_amount'],
                    ]);
            }

            // 3) 顶替旧 prepay 流水，避免复用过期金额
            $this->model(PaymentLog::class)
                ->where('order_id', (int) $order->id)
                ->where('event_type', PaymentLog::EVENT_PREPAY)
                ->update(['event_type' => PaymentLog::EVENT_SUPERSEDED]);
            if ($prepayLogIds !== []) {
                $this->model(PaymentLog::class)
                    ->whereIn('id', $prepayLogIds)
                    ->update(['event_type' => PaymentLog::EVENT_SUPERSEDED]);
            }

            // 4) 审计日志（同状态自环，仅记录改价动作）
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

    /**
     * @return array<int, array{id:int, goods_name:string, subtotal:string, subtotal_cents:int}>
     */
    protected function loadAdjustableOrderItems(int $orderId): array
    {
        $rows = $this->model(OrderItem::class)
            ->where('order_id', $orderId)
            ->field('id, goods_name, subtotal')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($rows === []) {
            throw new BusinessException('订单商品不存在');
        }

        $items = [];
        foreach ($rows as $row) {
            $subtotalCents = $this->moneyToCents((string) ($row['subtotal'] ?? '0.00'), '商品小计');
            if ($subtotalCents <= 0) {
                throw new BusinessException('订单商品金额不合法');
            }
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'goods_name' => (string) ($row['goods_name'] ?? ''),
                'subtotal' => $this->centsToDecimal($subtotalCents),
                'subtotal_cents' => $subtotalCents,
            ];
        }
        return $items;
    }

    /**
     * @param array<int, array{id:int, goods_name:string, subtotal:string, subtotal_cents:int}> $items
     * @param array<int, array<string, mixed>> $itemDiscounts
     * @return array<int, array{id:int, discount_amount:string, pay_amount:string, discount_cents:int, pay_cents:int}>
     */
    private function buildItemAdjustments(array $items, string $adjustMode, array $itemDiscounts, ?string $payPercent): array
    {
        return match ($adjustMode) {
            self::ADJUST_MODE_ITEM_DISCOUNT => $this->buildItemDiscountAdjustments($items, $itemDiscounts),
            self::ADJUST_MODE_PAY_PERCENT => $this->buildPercentAdjustments($items, $payPercent),
            default => throw new BusinessException('改价方式不合法'),
        };
    }

    /**
     * @param array<int, array{id:int, goods_name:string, subtotal:string, subtotal_cents:int}> $items
     * @param array<int, array<string, mixed>> $itemDiscounts
     * @return array<int, array{id:int, discount_amount:string, pay_amount:string, discount_cents:int, pay_cents:int}>
     */
    private function buildItemDiscountAdjustments(array $items, array $itemDiscounts): array
    {
        $knownIds = [];
        foreach ($items as $item) {
            $knownIds[$item['id']] = true;
        }

        $discountMap = [];
        foreach ($itemDiscounts as $row) {
            $itemId = (int) ($row['order_item_id'] ?? $row['id'] ?? 0);
            if ($itemId <= 0) {
                throw new BusinessException('商品优惠明细不合法');
            }
            if (!isset($knownIds[$itemId])) {
                throw new BusinessException('商品优惠明细不属于该订单');
            }
            if (isset($discountMap[$itemId])) {
                throw new BusinessException('商品优惠明细重复');
            }
            $discountMap[$itemId] = $this->moneyToCents((string) ($row['discount_amount'] ?? '0'), '商品优惠');
        }

        $adjustments = [];
        foreach ($items as $item) {
            $discountCents = $discountMap[$item['id']] ?? 0;
            if ($discountCents > $item['subtotal_cents']) {
                throw new BusinessException('商品优惠不能超过商品小计');
            }
            $payCents = $item['subtotal_cents'] - $discountCents;
            $adjustments[] = [
                'id' => $item['id'],
                'discount_amount' => $this->centsToDecimal($discountCents),
                'pay_amount' => $this->centsToDecimal($payCents),
                'discount_cents' => $discountCents,
                'pay_cents' => $payCents,
            ];
        }

        return $adjustments;
    }

    /**
     * @param array<int, array{id:int, goods_name:string, subtotal:string, subtotal_cents:int}> $items
     * @return array<int, array{id:int, discount_amount:string, pay_amount:string, discount_cents:int, pay_cents:int}>
     */
    private function buildPercentAdjustments(array $items, ?string $payPercent): array
    {
        $basisPoints = $this->percentToBasisPoints($payPercent);
        $totalCents = array_sum(array_map(
            static fn(array $item): int => (int) $item['subtotal_cents'],
            $items
        ));
        if ($totalCents <= 0) {
            throw new BusinessException('订单商品金额不合法');
        }

        $targetPayCents = intdiv($totalCents * $basisPoints, 10000);
        $allocations = [];
        $allocatedCents = 0;
        foreach ($items as $item) {
            $numerator = $item['subtotal_cents'] * $basisPoints;
            $payCents = intdiv($numerator, 10000);
            $allocatedCents += $payCents;
            $allocations[] = [
                'id' => $item['id'],
                'subtotal_cents' => $item['subtotal_cents'],
                'pay_cents' => $payCents,
                'remainder' => $numerator % 10000,
            ];
        }

        usort($allocations, static function (array $left, array $right): int {
            $byRemainder = $right['remainder'] <=> $left['remainder'];
            if ($byRemainder !== 0) {
                return $byRemainder;
            }
            return $left['id'] <=> $right['id'];
        });

        $leftover = $targetPayCents - $allocatedCents;
        foreach ($allocations as &$allocation) {
            if ($leftover <= 0) {
                break;
            }
            if ($allocation['pay_cents'] >= $allocation['subtotal_cents']) {
                continue;
            }
            $allocation['pay_cents']++;
            $leftover--;
        }
        unset($allocation);

        usort($allocations, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);

        $adjustments = [];
        foreach ($allocations as $allocation) {
            $discountCents = $allocation['subtotal_cents'] - $allocation['pay_cents'];
            $adjustments[] = [
                'id' => $allocation['id'],
                'discount_amount' => $this->centsToDecimal($discountCents),
                'pay_amount' => $this->centsToDecimal($allocation['pay_cents']),
                'discount_cents' => $discountCents,
                'pay_cents' => $allocation['pay_cents'],
            ];
        }

        return $adjustments;
    }

    private function moneyToCents(string $amount, string $label): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException($label . '金额格式不正确');
        }
        [$integer, $decimal] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $integer * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function percentToBasisPoints(?string $percent): int
    {
        $percent = trim((string) $percent);
        if ($percent === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $percent)) {
            throw new BusinessException('整单实付比例格式不正确');
        }
        [$integer, $decimal] = array_pad(explode('.', $percent, 2), 2, '');
        $basisPoints = ((int) $integer * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
        if ($basisPoints > 10000) {
            throw new BusinessException('整单实付比例不能超过 100%');
        }
        return $basisPoints;
    }

    private function formatPercent(int $basisPoints): string
    {
        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    protected function requestIp(): string
    {
        if (!function_exists('request')) {
            return '';
        }
        return (string) request()->ip();
    }

    /**
     * 预支付关闭服务获取点。
     *
     * 保留为 protected，便于单元测试替换外部支付关闭行为；生产逻辑仍通过容器获取无状态服务。
     */
    protected function prepayCloseService(): WechatPrepayCloseService
    {
        return app()->make(WechatPrepayCloseService::class);
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
            $prepayClose = $this->prepayCloseService();
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
                $prepayClose = $this->prepayCloseService();
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
                    $machine->transit(
                        order: $order,
                        toStatus: OrderStatus::COMPLETED,
                        operatorType: OperatorType::SYSTEM,
                        operatorId: null,
                        remark: '自动确认收货后订单完成',
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
     * @param array{sn?:string, status?:int|null, user_id?:int|null, buyer_keyword?:string, logistics_sn?:string, created_start?:string, created_end?:string, has_after_sale?:bool|null} $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function adminList(array $filter = [], int $page = 1, int $pageSize = 10): array
    {
        $query = $this->buildAdminListQuery($filter);

        $total = (clone $query)->count();
        $list = $query
            ->with([
                'buyer' => static function ($q): void {
                    $q->field('id,nickname,mobile,email,avatar,status')
                        ->whereNull('delete_time');
                },
                'items' => static function ($q): void {
                    $q->order('id', 'asc');
                },
                'activeRefunds' => static function ($q): void {
                    $q->field('id,order_id,status')
                        ->order('id', 'desc');
                },
            ])
            ->field('o.*')
            ->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $list = $this->hydrateOrderRelationAssets($list);

        foreach ($list as &$row) {
            $row['after_sale_tag_text'] = (string) ($row['active_refunds'][0]['status_text'] ?? '');
            unset($row['active_refunds']);
        }
        unset($row);

        return compact('total', 'list');
    }

    /**
     * @param array{sn?:string, status?:int|null, user_id?:int|null, buyer_keyword?:string, logistics_sn?:string, created_start?:string, created_end?:string, has_after_sale?:bool|null} $filter
     * @return array{total:int,tabs:array<int,array{key:string,label:string,count:int}>}
     */
    public function adminStats(array $filter = []): array
    {
        $baseFilter = $filter;
        unset($baseFilter['status']);

        $total = (int) $this->buildAdminListQuery($baseFilter)->count();
        $tabs = [[
            'key' => 'all',
            'label' => '全部',
            'count' => $total,
        ]];

        foreach (OrderStatus::options() as $option) {
            $statusFilter = $baseFilter;
            $statusFilter['status'] = $option['value'];
            $tabs[] = [
                'key' => (string) $option['value'],
                'label' => (string) $option['label'],
                'count' => (int) $this->buildAdminListQuery($statusFilter)->count(),
            ];
        }

        return compact('total', 'tabs');
    }

    /**
     * @param array{sn?:string, status?:int|null, user_id?:int|null, buyer_keyword?:string, ids?:array<int,int>|string, logistics_sn?:string, created_start?:string, created_end?:string, has_after_sale?:bool|null} $filter
     */
    public function exportCsv(array $filter = []): string
    {
        $rows = $this->buildAdminListQuery($filter)
            ->with(['buyer' => static function ($q): void {
                $q->field('id,nickname,mobile,email,avatar,status')
                    ->whereNull('delete_time');
            }])
            ->field('o.*')
            ->order('o.id', 'desc')
            ->select()
            ->toArray();
        $rows = $this->hydrateOrderRelationAssets($rows);

        foreach ($rows as &$row) {
            $row['status_text'] = OrderStatus::textOf((int) ($row['status'] ?? 0));
            $row['pay_method_text'] = isset($row['pay_method']) && $row['pay_method'] !== null
                ? PayMethod::textOf((int) $row['pay_method'])
                : '';
            $row['buyer_nickname'] = $row['buyer']['nickname'] ?? '';
            $row['buyer_mobile'] = $row['buyer']['mobile'] ?? '';
            $row['buyer_email'] = $row['buyer']['email'] ?? '';
            $row['buyer_avatar_url'] = $row['buyer']['avatar_full_url'] ?? '';
        }
        unset($row);

        return app()->make(CsvExportService::class)->make([
            'id' => 'ID',
            'sn' => '订单号',
            'status_text' => '状态',
            'user_id' => '买家ID',
            'buyer_nickname' => '买家昵称',
            'buyer_mobile' => '买家手机号',
            'buyer_email' => '买家邮箱',
            'buyer_avatar_url' => '买家头像',
            'pay_amount' => '应付金额',
            'pay_method_text' => '支付方式',
            'receiver_name' => '收件人',
            'receiver_phone' => '收件手机',
            'logistics_company' => '物流公司',
            'logistics_sn' => '运单号',
            'create_time' => '下单时间',
        ], $rows);
    }

    /**
     * 后台订单详情（含订单项 + 日志时间轴 + 售后标签）
     */
    public function adminDetail(int $orderId): array
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
                'buyer' => static function ($q): void {
                    $q->field('id,nickname,mobile,email,avatar,status')
                        ->whereNull('delete_time');
                },
                'activeRefunds' => static function ($q): void {
                    $q->field('id,order_id,status')
                        ->order('id', 'desc');
                },
            ])
            ->where('id', $orderId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }

        $data = $order->toArray();
        $data = $this->hydrateOrderRelationAssets([$data])[0];
        $data['after_sale_tag_text'] = (string) ($data['active_refunds'][0]['status_text'] ?? '');
        unset($data['active_refunds']);

        return $data;
    }

    // ---------------- 内部 ----------------

    /**
     * @param array{sn?:string, status?:int|null, user_id?:int|null, buyer_keyword?:string, ids?:array<int,int>|string, logistics_sn?:string, created_start?:string, created_end?:string, has_after_sale?:bool|null} $filter
     */
    private function buildAdminListQuery(array $filter)
    {
        $query = $this->model()
            ->alias('o')
            ->whereNull('o.delete_time');

        $ids = $this->normalizeIds($filter['ids'] ?? null);
        if ($ids !== []) {
            $query->whereIn('o.id', $ids);
        }
        if (!empty($filter['sn'])) {
            $query->where('o.sn', 'like', '%' . trim((string) $filter['sn']) . '%');
        }
        if (isset($filter['status']) && $filter['status'] !== null && $filter['status'] !== '') {
            $query->where('o.status', (int) $filter['status']);
        }
        if (!empty($filter['user_id'])) {
            $query->where('o.user_id', (int) $filter['user_id']);
        }
        $buyerKeyword = trim((string) ($filter['buyer_keyword'] ?? ''));
        if ($buyerKeyword !== '') {
            $query->leftJoin('mb_user u', 'u.id = o.user_id AND u.delete_time IS NULL');
            $query->where(function ($q) use ($buyerKeyword): void {
                $like = '%' . $buyerKeyword . '%';
                if (ctype_digit($buyerKeyword)) {
                    $q->where('o.user_id', (int) $buyerKeyword)
                        ->whereOr('u.nickname', 'like', $like)
                        ->whereOr('u.mobile', 'like', $like)
                        ->whereOr('u.email', 'like', $like);
                    return;
                }

                $q->whereLike('u.nickname|u.mobile|u.email', $like);
            });
        }
        if (!empty($filter['logistics_sn'])) {
            $query->where('o.logistics_sn', 'like', '%' . trim((string) $filter['logistics_sn']) . '%');
        }
        if (!empty($filter['created_start'])) {
            $query->where('o.create_time', '>=', (string) $filter['created_start']);
        }
        if (!empty($filter['created_end'])) {
            $query->where('o.create_time', '<=', (string) $filter['created_end']);
        }

        if (isset($filter['has_after_sale']) && $filter['has_after_sale'] !== null && $filter['has_after_sale'] !== '') {
            $activeOrderIds = $this->model(RefundOrder::class)
                ->whereIn('status', RefundOrderStatus::activeStatuses())
                ->whereNull('delete_time')
                ->distinct(true)
                ->column('order_id');
            $activeOrderIds = array_values(array_unique(array_map('intval', $activeOrderIds)));

            if ((bool) $filter['has_after_sale']) {
                $query->whereIn('o.id', $activeOrderIds ?: [0]);
            } elseif ($activeOrderIds !== []) {
                $query->whereNotIn('o.id', $activeOrderIds);
            }
        }

        return $query;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateOrderRelationAssets(array $rows): array
    {
        $buyers = [];
        $rowIndexes = [];
        $items = [];
        $itemIndexes = [];
        foreach ($rows as $index => $row) {
            if (is_array($row['buyer'] ?? null)) {
                $buyers[] = $row['buyer'];
                $rowIndexes[] = $index;
            }

            if (!is_array($row['items'] ?? null)) {
                continue;
            }
            foreach ($row['items'] as $itemIndex => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = $item;
                $itemIndexes[] = [$index, $itemIndex];
            }
        }

        /** @var AssetHydrator $hydrator */
        $hydrator = app()->make(AssetHydrator::class);
        if ($buyers !== []) {
            $buyers = $hydrator->hydrateFields($buyers, [
                'avatar' => 'avatar_full_url',
            ]);
            foreach ($buyers as $buyerIndex => $buyer) {
                $rows[$rowIndexes[$buyerIndex]]['buyer'] = $buyer;
            }
        }
        if ($items !== []) {
            $items = $hydrator->hydrateFields($items, [
                'goods_image' => 'goods_image_full_url',
            ]);
            foreach ($items as $itemOffset => $item) {
                [$rowIndex, $itemIndex] = $itemIndexes[$itemOffset];
                $rows[$rowIndex]['items'][$itemIndex] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $rawIds = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

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

}
