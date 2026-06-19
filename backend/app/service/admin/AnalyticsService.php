<?php

declare(strict_types=1);

namespace app\service\admin;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\goods\Goods;
use app\model\order\Order;
use app\model\order\RefundOrder;
use app\model\user\User;
use mall_base\base\BaseService;

/**
 * 后台经营分析服务
 */
class AnalyticsService extends BaseService
{
    private const STOCK_WARNING_THRESHOLD = 10;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cards(): array
    {
        [$todayStart, $todayEnd] = $this->dayRange(0);

        return $this->buildCards($todayStart, $todayEnd);
    }

    /**
     * @return array{labels:array<int,string>,amount:array<int,float>,orders:array<int,int>}
     */
    public function trend(): array
    {
        return $this->buildTrend(7);
    }

    /**
     * @return array{labels:array<int,string>,orders:array<int,int>}
     */
    public function monthlyOrders(): array
    {
        return $this->buildMonthlyOrders(12);
    }

    /**
     * @return array{indicators:array<int,string>,current:array<int,int>,previous:array<int,int>}
     */
    public function health(): array
    {
        return $this->buildHealth();
    }

    /**
     * @return array<int, array{name:string,value:int}>
     */
    public function orderChannels(): array
    {
        return $this->buildOrderChannels();
    }

    /**
     * @return array<int, array{name:string,value:int}>
     */
    public function salesStructure(): array
    {
        return $this->buildSalesStructure();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCards(string $todayStart, string $todayEnd): array
    {
        return [
            [
                'key'         => 'gmv',
                'title'       => '今日成交额',
                'value'       => $this->paidAmount($todayStart, $todayEnd),
                'total_title' => '累计成交额',
                'total_value' => $this->paidAmount(),
            ],
            [
                'key'         => 'orders',
                'title'       => '今日支付订单',
                'value'       => $this->paidOrderCount($todayStart, $todayEnd),
                'total_title' => '累计支付订单',
                'total_value' => $this->paidOrderCount(),
            ],
            [
                'key'         => 'users',
                'title'       => '今日新增用户',
                'value'       => $this->userCount($todayStart, $todayEnd),
                'total_title' => '总用户数',
                'total_value' => $this->userCount(),
            ],
            [
                'key'         => 'refunds',
                'title'       => '待处理售后',
                'value'       => $this->activeRefundCount(),
                'total_title' => '累计售后单',
                'total_value' => $this->refundCount(),
            ],
        ];
    }

    /**
     * @return array{labels:array<int,string>,amount:array<int,float>,orders:array<int,int>}
     */
    private function buildTrend(int $days): array
    {
        $labels = [];
        $amount = [];
        $orders = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            [$start, $end] = $this->dayRange($offset);
            $labels[] = date('m-d', strtotime($start));
            $amount[] = $this->paidAmount($start, $end);
            $orders[] = $this->paidOrderCount($start, $end);
        }

        return compact('labels', 'amount', 'orders');
    }

    /**
     * @return array{labels:array<int,string>,orders:array<int,int>}
     */
    private function buildMonthlyOrders(int $months): array
    {
        $labels = [];
        $orders = [];
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $startTs = strtotime("-{$offset} months", $monthStart);
            $endTs = strtotime('+1 month -1 second', $startTs);
            $labels[] = date('Y-m', $startTs);
            $orders[] = $this->paidOrderCount(date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs));
        }

        return compact('labels', 'orders');
    }

    /**
     * @return array{indicators:array<int,string>,current:array<int,int>,previous:array<int,int>}
     */
    private function buildHealth(): array
    {
        [$weekStart, $weekEnd] = $this->rangeByDays(6);
        [$previousStart, $previousEnd] = $this->previousRangeByDays(6);

        return [
            'indicators' => ['商品上新', '库存维护', '订单履约', '售后响应', '用户增长', '营销转化'],
            'current'    => [
                $this->scoreByCount($this->goodsCreatedCount($weekStart, $weekEnd), 10),
                100 - $this->scoreByCount($this->stockWarningCount(), 30),
                $this->fulfillmentScore(),
                100 - $this->scoreByCount($this->activeRefundCount(), 20),
                $this->scoreByCount($this->userCount($weekStart, $weekEnd), 30),
                $this->scoreByCount($this->paidOrderCount($weekStart, $weekEnd), 50),
            ],
            'previous'   => [
                $this->scoreByCount($this->goodsCreatedCount($previousStart, $previousEnd), 10),
                100 - $this->scoreByCount($this->stockWarningCount(), 30),
                $this->fulfillmentScore(),
                100 - $this->scoreByCount($this->refundCount($previousStart, $previousEnd), 20),
                $this->scoreByCount($this->userCount($previousStart, $previousEnd), 30),
                $this->scoreByCount($this->paidOrderCount($previousStart, $previousEnd), 50),
            ],
        ];
    }

    /**
     * @return array<int, array{name:string,value:int}>
     */
    private function buildOrderChannels(): array
    {
        $channels = [
            1 => '微信小程序',
            2 => '微信公众号',
            3 => 'H5商城',
            0 => '其他来源',
        ];

        $rows = [];
        foreach ($channels as $scene => $label) {
            $query = $this->paidOrderQuery();
            if ($scene === 0) {
                $query->where(function ($q): void {
                    $q->whereNull('pay_scene')->whereOr('pay_scene', 0);
                });
            } else {
                $query->where('pay_scene', $scene);
            }
            $rows[] = ['name' => $label, 'value' => (int) $query->count()];
        }

        return $rows;
    }

    /**
     * @return array<int, array{name:string,value:int}>
     */
    private function buildSalesStructure(): array
    {
        return [
            ['name' => '出售中商品', 'value' => $this->goodsCount(['status' => 1, 'is_on_sale' => 1])],
            ['name' => '推荐商品', 'value' => $this->goodsCount(['is_recommend' => 1])],
            ['name' => '新品商品', 'value' => $this->goodsCount(['is_new' => 1])],
            ['name' => '热销商品', 'value' => $this->goodsCount(['is_hot' => 1])],
        ];
    }

    private function paidAmount(?string $start = null, ?string $end = null): float
    {
        return round((float) $this->applyTimeRange($this->paidOrderQuery(), $start, $end)->sum('pay_amount'), 2);
    }

    private function paidOrderCount(?string $start = null, ?string $end = null): int
    {
        return (int) $this->applyTimeRange($this->paidOrderQuery(), $start, $end)->count();
    }

    private function userCount(?string $start = null, ?string $end = null): int
    {
        return (int) $this->applyTimeRange(
            $this->model(User::class)->whereNull('delete_time'),
            $start,
            $end
        )->count();
    }

    private function refundCount(?string $start = null, ?string $end = null): int
    {
        return (int) $this->applyTimeRange(
            $this->model(RefundOrder::class)->whereNull('delete_time'),
            $start,
            $end
        )->count();
    }

    private function activeRefundCount(): int
    {
        return (int) $this->model(RefundOrder::class)
            ->whereIn('status', RefundOrderStatus::activeStatuses())
            ->whereNull('delete_time')
            ->count();
    }

    private function goodsCreatedCount(string $start, string $end): int
    {
        return (int) $this->model(Goods::class)
            ->whereNull('delete_time')
            ->whereBetweenTime('create_time', $start, $end)
            ->count();
    }

    private function stockWarningCount(): int
    {
        return (int) $this->model(Goods::class)
            ->where('status', 1)
            ->where('is_on_sale', 1)
            ->where('stock', '<=', self::STOCK_WARNING_THRESHOLD)
            ->whereNull('delete_time')
            ->count();
    }

    /**
     * @param array<string, int> $conditions
     */
    private function goodsCount(array $conditions): int
    {
        $query = $this->model(Goods::class)->whereNull('delete_time');
        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return (int) $query->count();
    }

    private function fulfillmentScore(): int
    {
        $paid = (int) $this->model(Order::class)
            ->where('status', OrderStatus::PAID)
            ->whereNull('delete_time')
            ->count();

        return 100 - $this->scoreByCount($paid, 50);
    }

    private function paidOrderQuery()
    {
        return $this->model(Order::class)
            ->whereIn('status', [
                OrderStatus::PAID,
                OrderStatus::SHIPPED,
                OrderStatus::RECEIVED,
                OrderStatus::COMPLETED,
            ])
            ->whereNull('delete_time');
    }

    private function applyTimeRange($query, ?string $start, ?string $end)
    {
        if ($start !== null && $end !== null) {
            $query->whereBetweenTime('create_time', $start, $end);
        }

        return $query;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function dayRange(int $offset): array
    {
        $date = strtotime("-{$offset} days");
        return [
            date('Y-m-d 00:00:00', $date),
            date('Y-m-d 23:59:59', $date),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function rangeByDays(int $daysBack): array
    {
        return [date('Y-m-d 00:00:00', strtotime("-{$daysBack} days")), date('Y-m-d 23:59:59')];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function previousRangeByDays(int $daysBack): array
    {
        $endDaysBack = $daysBack + 1;
        $startDaysBack = $daysBack * 2 + 1;
        return [
            date('Y-m-d 00:00:00', strtotime("-{$startDaysBack} days")),
            date('Y-m-d 23:59:59', strtotime("-{$endDaysBack} days")),
        ];
    }

    private function scoreByCount(int $count, int $target): int
    {
        if ($target <= 0) {
            return 0;
        }

        return max(0, min(100, (int) round($count / $target * 100)));
    }
}
