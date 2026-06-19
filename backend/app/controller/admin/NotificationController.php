<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\admin\NotificationService;
use mall_base\base\BaseController;

/**
 * 后台通知控制器
 *
 * @extends BaseController<NotificationService>
 */
class NotificationController extends BaseController
{
    protected string $serviceClass = NotificationService::class;

    public function pendingShipment()
    {
        return $this->success($this->service()->pendingShipment(), '获取成功');
    }

    public function refundPending()
    {
        return $this->success($this->service()->refundPending(), '获取成功');
    }

    public function stockWarning()
    {
        return $this->success($this->service()->stockWarning(), '获取成功');
    }

    public function logisticsConfig()
    {
        return $this->success($this->service()->logisticsConfig(), '获取成功');
    }

    public function smsProviderConfig()
    {
        return $this->success($this->service()->smsProviderConfig(), '获取成功');
    }
}
