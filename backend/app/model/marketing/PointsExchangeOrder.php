<?php
declare(strict_types=1);

namespace app\model\marketing;

use mall_base\base\BaseModel;

/**
 * 积分兑换单
 */
class PointsExchangeOrder extends BaseModel
{
    public const DELIVERY_TYPE_PHYSICAL = 'physical';
    public const DELIVERY_TYPE_VIRTUAL = 'virtual';

    protected $name = 'points_exchange_order';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const STATUS_PENDING_SHIP = 10;
    public const STATUS_SHIPPED = 20;
    public const STATUS_COMPLETED = 30;
    public const STATUS_CLOSED = 90;

    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_SHIP => '待发货',
            self::STATUS_SHIPPED => '已发货',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_CLOSED => '已关闭',
            default => '未知状态',
        };
    }

    /**
     * @return array<int, array{value:int,label:string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_PENDING_SHIP, 'label' => self::statusText(self::STATUS_PENDING_SHIP)],
            ['value' => self::STATUS_SHIPPED, 'label' => self::statusText(self::STATUS_SHIPPED)],
            ['value' => self::STATUS_COMPLETED, 'label' => self::statusText(self::STATUS_COMPLETED)],
            ['value' => self::STATUS_CLOSED, 'label' => self::statusText(self::STATUS_CLOSED)],
        ];
    }

    public static function deliveryTypeLabel(string $type): string
    {
        return match ($type) {
            self::DELIVERY_TYPE_VIRTUAL => '虚拟发货',
            default => '实物快递',
        };
    }
}
