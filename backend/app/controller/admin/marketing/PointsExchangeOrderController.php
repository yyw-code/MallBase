<?php
declare(strict_types=1);

namespace app\controller\admin\marketing;

use app\service\admin\marketing\PointsExchangeOrderService;
use app\validate\admin\marketing\PointsExchangeOrderValidate;
use mall_base\base\BaseController;

/**
 * 后台积分兑换单控制器
 *
 * @extends BaseController<PointsExchangeOrderService>
 */
class PointsExchangeOrderController extends BaseController
{
    protected string $serviceClass = PointsExchangeOrderService::class;

    public function list()
    {
        $where = $this->request->param(['user_id', 'status', 'sn']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($orderId), '获取成功');
    }

    public function statusOptions()
    {
        return $this->success($this->service()->statusOptions(), '获取成功');
    }

    public function ship($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['logistics_company', 'logistics_no', 'admin_remark']);
        $this->validate($data, PointsExchangeOrderValidate::class . '.ship');
        $this->service()->ship($orderId, $data, (int) ($this->request->admin_id ?? 0));

        return $this->success(null, '发货成功');
    }

    public function complete($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->complete($orderId, (int) ($this->request->admin_id ?? 0));

        return $this->success(null, '操作成功');
    }

    public function close($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['admin_remark']);
        $this->validate($data, PointsExchangeOrderValidate::class . '.close');
        $this->service()->close($orderId, (string) ($data['admin_remark'] ?? ''), (int) ($this->request->admin_id ?? 0));

        return $this->success(null, '关闭成功');
    }
}
