<?php
declare(strict_types=1);

namespace app\controller\client\recharge;

use app\service\client\recharge\RechargePackageService;
use mall_base\base\BaseController;

/**
 * 客户端充值套餐控制器
 *
 * @extends BaseController<RechargePackageService>
 */
class RechargePackageController extends BaseController
{
    protected string $serviceClass = RechargePackageService::class;

    public function list()
    {
        return $this->success($this->service()->list(), '获取成功');
    }
}
