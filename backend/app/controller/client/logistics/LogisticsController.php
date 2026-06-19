<?php
declare(strict_types=1);

namespace app\controller\client\logistics;

use app\service\logistics\LogisticsService;
use mall_base\base\BaseController;

/**
 * 买家物流查询控制器
 *
 * @extends BaseController<LogisticsService>
 */
class LogisticsController extends BaseController
{
    protected string $serviceClass = LogisticsService::class;

    public function detail($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->clientOrderDetail($userId, (int) $id), '获取成功');
    }
}
