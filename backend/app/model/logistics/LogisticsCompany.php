<?php
declare(strict_types=1);

namespace app\model\logistics;

use mall_base\base\BaseModel;

/**
 * 平台物流公司编码表
 */
class LogisticsCompany extends BaseModel
{
    protected $name = 'logistics_company';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $json = ['raw_snapshot'];
    protected $jsonAssoc = true;
}
