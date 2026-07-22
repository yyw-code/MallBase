<?php
declare(strict_types=1);

namespace app\controller\connector;

use app\service\connector\CustomerServiceConnectorService;
use app\service\connector\CustomerServiceContextTokenService;
use app\service\connector\CustomerServiceSettingService;
use mall_base\base\BaseController;
use think\Response;

/**
 * 客服系统服务端连接器入口。
 *
 * @extends BaseController<CustomerServiceConnectorService>
 */
class CustomerServiceController extends BaseController
{
    protected string $serviceClass = CustomerServiceConnectorService::class;

    public function health(): Response
    {
        return $this->connectorSuccess($this->service()->health());
    }

    public function contextToken(): Response
    {
        $payload = (array) $this->request->param();
        $token = $this->service(CustomerServiceContextTokenService::class)->issue($payload);

        return $this->connectorSuccess([
            'token' => $token,
            'expires_in' => $this->service(CustomerServiceSettingService::class)->contextTtl(),
        ]);
    }

    public function productSummary($id): Response
    {
        return $this->connectorSuccess($this->service()->productSummary((int) $id));
    }

    public function productSearch(): Response
    {
        return $this->connectorSuccess($this->service()->productSearch((array) $this->request->param()));
    }

    public function orderSummary($id): Response
    {
        return $this->connectorSuccess($this->service()->orderSummary((int) $id));
    }

    public function userSummary($id): Response
    {
        return $this->connectorSuccess($this->service()->userSummary((int) $id));
    }

    public function addOrderRemark($id): Response
    {
        $orderId = (int) $id;
        $remark = (string) $this->request->param('remark', '');
        $actorName = (string) $this->request->param('actor_name', '');
        $idempotencyKey = (string) $this->request->header('X-CS-Idempotency-Key', '');

        return $this->connectorSuccess(
            $this->service()->addOrderRemark($orderId, $remark, $actorName, $idempotencyKey)
        );
    }

    public function shipOrder($id): Response
    {
        $orderId = (int) $id;
        $payload = (array) $this->request->param();
        $idempotencyKey = (string) $this->request->header('X-CS-Idempotency-Key', '');

        return $this->connectorSuccess(
            $this->service()->shipOrder($orderId, $payload, $idempotencyKey)
        );
    }

    public function approveRefund($id): Response
    {
        $refundId = (int) $id;
        $payload = (array) $this->request->param();
        $idempotencyKey = (string) $this->request->header('X-CS-Idempotency-Key', '');

        return $this->connectorSuccess(
            $this->service()->approveRefund($refundId, $payload, $idempotencyKey)
        );
    }

    public function rejectRefund($id): Response
    {
        $refundId = (int) $id;
        $payload = (array) $this->request->param();
        $idempotencyKey = (string) $this->request->header('X-CS-Idempotency-Key', '');

        return $this->connectorSuccess(
            $this->service()->rejectRefund($refundId, $payload, $idempotencyKey)
        );
    }

    /**
     * @param mixed $data
     */
    private function connectorSuccess($data = null): Response
    {
        return json([
            'ok' => true,
            'data' => $data,
            'error' => null,
        ]);
    }
}
