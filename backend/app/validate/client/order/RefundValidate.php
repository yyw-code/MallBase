<?php
declare(strict_types=1);

namespace app\validate\client\order;

use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use think\Validate;

/**
 * 买家售后请求校验
 *
 * scene 与 RefundOrderController 方法对应：
 *  - apply  申请售后
 *  - list   列表筛选
 *
 * 说明：
 *  - `reason` 用 RefundReason 动态取值，避免枚举漂移时两处要改
 *  - `type` 允许传 0/1 但 Service 层硬拦截 type=1（退货退款）
 *  - `remark` 为买家补充说明，不参与退款金额计算
 */
class RefundValidate extends Validate
{
    protected $rule = [
        'order_item_id' => 'require|integer|gt:0',
        'items'         => 'require|array',
        'quantity'      => 'require|integer|gt:0',
        'type'          => 'integer|in:0,1',
        'receive_status'=> 'integer|in:0,1',
        'reason'        => 'require',
        'remark'        => 'max:255',
        'return_company' => 'max:50',
        'return_tracking_no' => 'max:64',
        'status'        => 'integer',
        'order_id'      => 'integer|gt:0',
        'start_time'    => 'date',
        'end_time'      => 'date',
    ];

    protected $message = [
        'order_item_id.require' => '请选择要申请售后的商品',
        'order_item_id.gt'      => '订单项参数不合法',
        'items.require'         => '请选择要申请售后的商品',
        'items.array'           => '售后商品参数不合法',
        'quantity.require'      => '请填写申请数量',
        'quantity.gt'           => '申请数量必须大于 0',
        'type.in'               => '售后类型不合法',
        'receive_status.in'     => '收货状态不合法',
        'reason.require'        => '请选择售后原因',
        'remark.max'            => '备注最长 255 字',
        'return_company.require' => '请填写退货物流公司',
        'return_tracking_no.require' => '请填写退货物流单号',
    ];

    protected $scene = [
        'apply' => ['order_item_id', 'quantity', 'type', 'receive_status', 'reason', 'remark'],
        'batchApply' => ['items', 'type', 'receive_status', 'reason', 'remark'],
        'return' => ['return_company', 'return_tracking_no'],
        'list'  => ['status', 'order_id', 'start_time', 'end_time'],
    ];

    /**
     * scene 'apply' 动态增强：reason 必须在 RefundReason 枚举内
     */
    protected function sceneApply(): Validate
    {
        return $this->only(['order_item_id', 'quantity', 'type', 'receive_status', 'reason', 'remark'])
            ->append('reason', 'in:' . implode(',', RefundReason::values()));
    }

    protected function sceneBatchApply(): Validate
    {
        return $this->only(['items', 'type', 'receive_status', 'reason', 'remark'])
            ->append('reason', 'in:' . implode(',', RefundReason::values()));
    }

    protected function sceneReturn(): Validate
    {
        return $this->only(['return_company', 'return_tracking_no'])
            ->append('return_company', 'require')
            ->append('return_tracking_no', 'require');
    }

    /**
     * scene 'list' 动态增强：status 必须落在已定义范围
     */
    protected function sceneList(): Validate
    {
        $statuses = array_map(
            static fn(array $o): string => (string) $o['value'],
            RefundOrderStatus::options(),
        );
        return $this->only(['status', 'order_id', 'start_time', 'end_time'])
            ->append('status', 'in:' . implode(',', $statuses));
    }
}
