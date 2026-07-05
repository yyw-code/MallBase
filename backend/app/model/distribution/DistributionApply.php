<?php
declare(strict_types=1);

namespace app\model\distribution;

use mall_base\base\BaseModel;

class DistributionApply extends BaseModel
{
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 10;
    public const STATUS_REJECTED = 20;

    protected $name = 'distribution_apply';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已通过',
            self::STATUS_REJECTED => '已驳回',
            default => '未知',
        };
    }
}
