<?php
declare(strict_types=1);

namespace app\model\logistics;

use mall_base\base\BaseModel;

/**
 * 物流平台配置
 */
class LogisticsPlatform extends BaseModel
{
    public const DRIVER_KDNIAO = 'kdniao';

    protected $name = 'logistics_platform';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $json = ['config'];
    protected $jsonAssoc = true;
}
