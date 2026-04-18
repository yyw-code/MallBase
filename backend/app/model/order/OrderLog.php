<?php
declare(strict_types=1);

namespace app\model\order;

use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use mall_base\base\BaseModel;

/**
 * 订单流转日志模型（append-only）
 *
 * 仅由 OrderStatusMachine 在事务内写入，业务代码禁止直接删改
 */
class OrderLog extends BaseModel
{
    protected $name = 'order_log';
    protected $pk = 'id';
    // 只写入 create_time，不维护 update_time
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected array $append = ['from_status_text', 'to_status_text', 'operator_type_text'];

    public function getFromStatusTextAttr($value, $data): string
    {
        $from = $data['from_status'] ?? null;
        if ($from === null || $from === '') {
            return '';
        }
        return OrderStatus::textOf((int) $from);
    }

    public function getToStatusTextAttr($value, $data): string
    {
        return OrderStatus::textOf((int) ($data['to_status'] ?? 0));
    }

    public function getOperatorTypeTextAttr($value, $data): string
    {
        return OperatorType::textOf((int) ($data['operator_type'] ?? 0));
    }
}
