<?php
declare(strict_types=1);

namespace app\model\distribution;

use mall_base\base\BaseModel;

class DistributionRelation extends BaseModel
{
    protected $name = 'distribution_relation';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
