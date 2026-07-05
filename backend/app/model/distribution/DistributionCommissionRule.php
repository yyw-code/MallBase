<?php
declare(strict_types=1);

namespace app\model\distribution;

use mall_base\base\BaseModel;

class DistributionCommissionRule extends BaseModel
{
    public const TARGET_CATEGORY = 'category';
    public const TARGET_GOODS = 'goods';
    public const TARGET_SKU = 'sku';
    public const COMMISSION_TYPE_RATE = 'rate';
    public const COMMISSION_TYPE_FIXED = 'fixed';

    protected $name = 'distribution_commission_rule';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public static function targetText(string $targetType): string
    {
        return match ($targetType) {
            self::TARGET_SKU => 'SKU',
            self::TARGET_GOODS => '商品',
            self::TARGET_CATEGORY => '分类',
            default => '未知',
        };
    }

    public static function commissionTypeText(string $commissionType): string
    {
        return match ($commissionType) {
            self::COMMISSION_TYPE_FIXED => '固定金额',
            self::COMMISSION_TYPE_RATE => '比例',
            default => '未知',
        };
    }
}
