<?php
declare(strict_types=1);

namespace app\model\distribution;

use mall_base\base\BaseModel;

class DistributionOrderCommission extends BaseModel
{
    public const STATUS_FROZEN = 10;
    public const STATUS_PENDING_SETTLE = 20;
    public const STATUS_SETTLED = 30;
    public const STATUS_RECOVERED = 80;
    public const STATUS_CANCELED = 90;

    protected $name = 'distribution_order_commission';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_FROZEN => '冻结中',
            self::STATUS_PENDING_SETTLE => '待结算',
            self::STATUS_SETTLED => '已结算',
            self::STATUS_RECOVERED => '已扣回',
            self::STATUS_CANCELED => '已取消',
            default => '未知',
        };
    }
}
