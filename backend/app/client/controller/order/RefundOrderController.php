<?php
declare(strict_types=1);

namespace app\client\controller\order;

use app\service\client\order\RefundService;
use app\client\validate\order\RefundValidate;
use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use mall_base\base\BaseController;

/**
 * 买家售后控制器
 *
 * 约束：
 *  - 用户身份来自 JwtAuth 中间件注入的 request->user_id，禁止从 body 读取
 *  - 仅做传输层（参数拼装、校验、响应），业务走 RefundService
 *
 * @extends BaseController<RefundService>
 */
class RefundOrderController extends BaseController
{
    protected string $serviceClass = RefundService::class;

    /**
     * 申请售后
     *
     * body:
     *  - order_item_id: int   要退款的订单项 ID
     *  - quantity: int        申请数量（≤ 剩余可退）
     *  - type: int            0 仅退款 / 1 退货退款（MVP 仅开放 0）
     *  - reason: string       RefundReason 枚举值
     *  - remark: string       可选 ≤ 255
     */
    public function apply()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['order_item_id', 'quantity', 'type', 'reason', 'remark']);
        $this->validate($data, RefundValidate::class . '.apply');

        $payload = [
            'order_item_id' => (int) ($data['order_item_id'] ?? 0),
            'quantity'      => (int) ($data['quantity'] ?? 0),
            'type'          => isset($data['type']) ? (int) $data['type'] : RefundOrderStatus::TYPE_REFUND_ONLY,
            'reason'        => (string) ($data['reason'] ?? ''),
            'remark'        => isset($data['remark']) && $data['remark'] !== '' ? (string) $data['remark'] : null,
        ];

        $result = $this->service()->apply($userId, $payload);
        return $this->success($result, '申请成功');
    }

    /**
     * 撤销售后申请（仅 PENDING 可撤销）
     */
    public function cancel($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $this->service()->cancel($userId, (int) $id);
        return $this->success(null, '已撤销');
    }

    /**
     * 我的售后列表（分页）
     *
     * query:
     *  - status: int?         指定状态筛选
     *  - start_time / end_time: datetime?  创建时间区间
     *  - page / limit: pagination
     */
    public function list()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        [$page, $pageSize] = $this->getPagination();

        $filter = [
            'status'     => $this->request->param('status', null),
            'start_time' => $this->request->param('start_time', null),
            'end_time'   => $this->request->param('end_time', null),
        ];
        $this->validate($filter, RefundValidate::class . '.list');

        return $this->success($this->service()->list($userId, $filter, $page, $pageSize), '获取成功');
    }

    /**
     * 售后详情
     */
    public function detail($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->detail($userId, (int) $id), '获取成功');
    }

    /**
     * 售后原因枚举下拉（供前端申请表单渲染）
     */
    public function reasonOptions()
    {
        return $this->success(RefundReason::options(), '获取成功');
    }
}
