<?php

declare(strict_types=1);

namespace app\service\admin;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\goods\Goods;
use app\model\logistics\LogisticsCompany;
use app\model\order\Order;
use app\model\order\RefundOrder;
use app\model\sms\SmsProvider;
use mall_base\base\BaseService;

/**
 * 后台实时业务通知服务
 */
class NotificationService extends BaseService
{
    private const STOCK_WARNING_THRESHOLD = 10;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingShipment(): array
    {
        $count = $this->orderCount(OrderStatus::PAID);
        return $count > 0 ? [$this->item(
            id: 'pending_shipment',
            title: '待发货订单提醒',
            message: sprintf('当前有 %d 笔已支付订单等待发货。', $count),
            link: '/order',
            query: ['status' => OrderStatus::PAID],
        )] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refundPending(): array
    {
        $count = $this->refundCount(RefundOrderStatus::PENDING);
        return $count > 0 ? [$this->item(
            id: 'refund_pending',
            title: '售后申请待审核',
            message: sprintf('当前有 %d 条售后申请等待商家审核。', $count),
            link: '/order/refund',
            query: ['status' => RefundOrderStatus::PENDING],
        )] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stockWarning(): array
    {
        $count = $this->stockWarningCount();
        return $count > 0 ? [$this->item(
            id: 'stock_warning',
            title: '商品库存预警',
            message: sprintf('当前有 %d 个上架商品库存低于预警线。', $count),
            link: '/goods',
            query: ['view' => 'on_sale', 'stock_warning' => 1],
        )] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function logisticsConfig(): array
    {
        return $this->enabledLogisticsCompanyCount() === 0 ? [$this->item(
            id: 'logistics_config',
            title: '物流公司未配置',
            message: '未配置启用的物流公司，发货前需要先完成配置。',
            link: '/logistics/company',
        )] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function smsProviderConfig(): array
    {
        return $this->enabledDefaultSmsProviderCount() === 0 ? [$this->item(
            id: 'sms_provider_config',
            title: '短信服务未配置',
            message: '未配置启用的默认短信服务商，验证码和通知短信可能不可用。',
            link: '/sms/provider',
        )] : [];
    }

    /**
     * @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    private function item(
        string $id,
        string $title,
        string $message,
        string $link,
        array $query = [],
    ): array {
        return [
            'id'      => $id,
            'date'    => '实时',
            'is_read' => false,
            'link'    => $link,
            'message' => $message,
            'query'   => $query,
            'title'   => $title,
        ];
    }

    private function orderCount(int $status): int
    {
        return (int) $this->model(Order::class)
            ->where('status', $status)
            ->whereNull('delete_time')
            ->count();
    }

    private function refundCount(int $status): int
    {
        return (int) $this->model(RefundOrder::class)
            ->where('status', $status)
            ->whereNull('delete_time')
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

    private function enabledLogisticsCompanyCount(): int
    {
        return (int) $this->model(LogisticsCompany::class)
            ->where('status', 1)
            ->count();
    }

    private function enabledDefaultSmsProviderCount(): int
    {
        return (int) $this->model(SmsProvider::class)
            ->where('status', 1)
            ->where('is_default', 1)
            ->count();
    }
}
