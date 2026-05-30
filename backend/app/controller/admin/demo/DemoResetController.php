<?php

declare(strict_types=1);

namespace app\controller\admin\demo;

use app\service\admin\demo\DemoResetService;
use mall_base\base\BaseController;

/**
 * 演示站数据恢复控制器
 *
 * @extends BaseController<DemoResetService>
 */
class DemoResetController extends BaseController
{
    protected string $serviceClass = DemoResetService::class;

    public function reset()
    {
        $result = $this->service()->startQueuedReset();

        return $this->success($result, '演示数据恢复任务已开始');
    }

    public function start()
    {
        $result = $this->service()->startQueuedReset();

        return $this->success($result, '演示数据恢复任务已开始');
    }

    public function status()
    {
        return $this->success($this->service()->getResetStatus(), '获取成功');
    }
}
