<?php
declare(strict_types=1);

namespace app\controller\client;

use app\service\client\CustomerServiceContextService;
use mall_base\base\BaseController;

/**
 * C 端在线客服上下文入口。
 *
 * @extends BaseController<CustomerServiceContextService>
 */
class CustomerServiceController extends BaseController
{
    protected string $serviceClass = CustomerServiceContextService::class;

    public function contextToken()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $payload = (array) $this->request->param();

        return $this->success($this->service()->issue($userId, $payload), '获取成功');
    }
}
