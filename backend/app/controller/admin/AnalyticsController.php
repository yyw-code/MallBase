<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\admin\AnalyticsService;
use mall_base\base\BaseController;

/**
 * 后台经营分析控制器
 *
 * @extends BaseController<AnalyticsService>
 */
class AnalyticsController extends BaseController
{
    protected string $serviceClass = AnalyticsService::class;

    public function cards()
    {
        return $this->success($this->service()->cards(), '获取成功');
    }

    public function trend()
    {
        return $this->success($this->service()->trend(), '获取成功');
    }

    public function monthlyOrders()
    {
        return $this->success($this->service()->monthlyOrders(), '获取成功');
    }

    public function health()
    {
        return $this->success($this->service()->health(), '获取成功');
    }

    public function orderChannels()
    {
        return $this->success($this->service()->orderChannels(), '获取成功');
    }

    public function salesStructure()
    {
        return $this->success($this->service()->salesStructure(), '获取成功');
    }
}
