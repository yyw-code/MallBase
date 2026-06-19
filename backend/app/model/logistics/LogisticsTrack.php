<?php
declare(strict_types=1);

namespace app\model\logistics;

use mall_base\base\BaseModel;

/**
 * 物流轨迹快照
 */
class LogisticsTrack extends BaseModel
{
    public const BUSINESS_ORDER = 'order';

    protected $name = 'logistics_track';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $json = ['tracks', 'raw_snapshot'];
    protected $jsonAssoc = true;
}
