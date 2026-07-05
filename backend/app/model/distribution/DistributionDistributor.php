<?php
declare(strict_types=1);

namespace app\model\distribution;

use mall_base\base\BaseModel;

class DistributionDistributor extends BaseModel
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    protected $name = 'distribution_distributor';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
