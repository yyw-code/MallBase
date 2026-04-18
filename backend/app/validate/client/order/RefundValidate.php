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
        'quantity'      => 'require|integer|gt:0',
        'type'          => 'integer|in:0,1',
        'reason'        => 'require',
        'remark'        => 'max:255',
        'status'        => 'integer',
        'start_time'    => 'date',
        'end_time'      => 'date',
    ];

    protected $message = [
        'order_item_id.require' => '请选择要申请售后的商品',
        'order_item_id.gt'      => '订单项参数不合法',
        'quantity.require'      => '请填写申请数量',
        'quantity.gt'           => '申请数量必须大于 0',
        'type.in'               => '售后类型不合法',
        'reason.require'        => '请选择售后原因',
        'remark.max'            => '备注最长 255 字',
    ];

    protected $scene = [
        'apply' => ['order_item_id', 'quantity', 'type', 'reason', 'remark'],
        'list'  => ['status', 'start_time', 'end_time'],
    ];

    /**
     * scene 'apply' 动态增强：reason 必须在 RefundReason 枚举内
     */
    protected function sceneApply(): Validate
    {
        return $this->only(['order_item_id', 'quantity', 'type', 'reason', 'remark'])
            ->append('reason', 'in:' . implode(',', RefundReason::values()));
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
        return $this->only(['status', 'start_time', 'end_time'])
            ->append('status', 'in:' . implode(',', $statuses));
    }
}
