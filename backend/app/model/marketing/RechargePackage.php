<?php
declare(strict_types=1);

namespace app\model\marketing;

use mall_base\base\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 充值套餐模型
 */
class RechargePackage extends BaseModel
{
    use SoftDelete;

    protected $name = 'recharge_package';

    protected $deleteTime = 'delete_time';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}
