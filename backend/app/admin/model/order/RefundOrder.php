<?php
declare(strict_types=1);

namespace app\admin\model\order;

use app\common\enum\RefundOrderStatus;
use mall_base\base\BaseModel;

/**
 * 售后订单模型（预留骨架）
 *
 * 当前版本仅建模，用于：
 *  1. OrderService 列表接口聚合售后标签文案
 *  2. 后台售后菜单占位，权限码 SystemRefundOrder* 预注册
 *
 * 主体审核/退款流程在后续迭代实现
 */
class RefundOrder extends BaseModel
{
    protected $name = 'refund_order';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';

    protected array $append = ['status_text', 'type_text'];

    public function getStatusTextAttr($value, $data): string
    {
        return RefundOrderStatus::textOf((int) ($data['status'] ?? 0));
    }

    public function getTypeTextAttr($value, $data): string
    {
        return RefundOrderStatus::typeTextOf((int) ($data['type'] ?? 0));
    }
}
