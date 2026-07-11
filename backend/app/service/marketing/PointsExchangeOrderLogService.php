<?php
declare(strict_types=1);

namespace app\service\marketing;

use app\common\enum\OperatorType;
use app\model\marketing\PointsExchangeOrder;
use app\model\marketing\PointsExchangeOrderLog;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 积分兑换单操作日志服务
 *
 * @extends BaseService<PointsExchangeOrderLog>
 */
class PointsExchangeOrderLogService extends BaseService
{
    protected string $modelClass = PointsExchangeOrderLog::class;

    public function record(
        PointsExchangeOrder $order,
        string $action,
        ?int $fromStatus,
        int $toStatus,
        int $operatorType,
        ?int $operatorId = null,
        string $remark = ''
    ): void {
        if (!OperatorType::isValid($operatorType)) {
            throw new BusinessException('兑换单操作人类型不合法');
        }

        $this->model()->save([
            'exchange_order_id' => (int) $order->id,
            'exchange_sn' => (string) $order->sn,
            'action' => mb_substr(trim($action), 0, 32),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'remark' => mb_substr(trim($remark), 0, 255),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $rows = $this->model()
            ->where('exchange_order_id', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['action_text'] = PointsExchangeOrderLog::actionText((string) ($row['action'] ?? ''));
            $row['from_status_text'] = isset($row['from_status']) && $row['from_status'] !== null
                ? PointsExchangeOrder::statusText((int) $row['from_status'])
                : '';
            $row['to_status_text'] = PointsExchangeOrder::statusText((int) ($row['to_status'] ?? 0));
            $row['operator_type_text'] = OperatorType::textOf((int) ($row['operator_type'] ?? 0));
        }
        unset($row);

        return $rows;
    }
}
