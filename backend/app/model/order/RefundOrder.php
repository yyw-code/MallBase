<?php
declare(strict_types=1);

namespace app\model\order;

use app\common\enum\RefundOrderStatus;
use mall_base\base\BaseModel;

/**
 * 售后订单模型
 *
 * 用于售后申请、后台审核与主订单列表售后标签聚合。
 */
class RefundOrder extends BaseModel
{
    protected $name = 'refund_order';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';

    protected array $append = ['status_text', 'type_text', 'receive_status_text', 'intercept_status_text'];

    public function getStatusTextAttr($value, $data): string
    {
        return RefundOrderStatus::textOf((int) ($data['status'] ?? 0));
    }

    public function getTypeTextAttr($value, $data): string
    {
        return RefundOrderStatus::typeTextOf((int) ($data['type'] ?? 0));
    }

    public function getReceiveStatusTextAttr($value, $data): string
    {
        return RefundOrderStatus::receiveTextOf((int) ($data['receive_status'] ?? 0));
    }

    public function getInterceptStatusTextAttr($value, $data): string
    {
        return RefundOrderStatus::interceptTextOf((string) ($data['intercept_status'] ?? ''));
    }
}
