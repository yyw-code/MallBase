<?php
declare(strict_types=1);

namespace app\model\order;

use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use app\common\enum\RefundOrderStatus;
use app\model\user\User;
use mall_base\base\BaseModel;

/**
 * 订单主表模型
 *
 * 约定：
 *  - status 为 tinyint，业务代码一律通过 {@see OrderStatus} 枚举访问
 *  - append 字段 status_text / pay_method_text 由访问器从枚举映射，不落库
 *  - 状态流转禁止直接 $order->status = X，必须走 OrderStatusMachine
 */
class Order extends BaseModel
{
    protected $name = 'order';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';

    protected array $append = ['status_text', 'pay_method_text'];

    /**
     * 订单项（一对多）
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    /**
     * 订单流转日志（一对多，时间倒序由调用处决定）
     */
    public function logs()
    {
        return $this->hasMany(OrderLog::class, 'order_id', 'id');
    }

    /**
     * 买家（订单归属用户）
     */
    public function buyer()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 进行中的售后单（用于订单列表售后标签）
     */
    public function activeRefunds()
    {
        return $this->hasMany(RefundOrder::class, 'order_id', 'id')
            ->whereIn('status', RefundOrderStatus::activeStatuses())
            ->whereNull('delete_time')
            ->order('id', 'desc');
    }

    /**
     * 状态文案（从枚举映射）
     */
    public function getStatusTextAttr($value, $data): string
    {
        return OrderStatus::textOf((int) ($data['status'] ?? 0));
    }

    /**
     * 支付方式文案（从枚举映射，未支付为空字符串）
     */
    public function getPayMethodTextAttr($value, $data): string
    {
        $method = $data['pay_method'] ?? null;
        if ($method === null || $method === '') {
            return '';
        }
        return PayMethod::textOf((int) $method);
    }
}
