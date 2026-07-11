<?php
declare(strict_types=1);

namespace app\service\marketing;

use app\common\enum\OperatorType;
use app\common\service\DeadlockRetryTrait;
use app\model\marketing\PointsExchangeOrder;
use app\model\marketing\PointsExchangeOrderLog;
use app\model\marketing\PointsGoods;
use app\service\order\StockService;
use app\service\user\UserPointsAccountService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 积分兑换单状态流转服务
 *
 * @extends BaseService<PointsExchangeOrder>
 */
class PointsExchangeOrderLifecycleService extends BaseService
{
    use DeadlockRetryTrait;

    protected string $modelClass = PointsExchangeOrder::class;

    public function closePending(
        int $id,
        string $remark,
        int $operatorType,
        ?int $operatorId = null,
        ?int $expectedUserId = null,
        string $action = ''
    ): bool {
        $remark = mb_substr(trim($remark), 0, 255);
        if ($remark === '') {
            throw new BusinessException('请填写关闭原因');
        }
        if (!OperatorType::isValid($operatorType)) {
            throw new BusinessException('操作者类型不合法');
        }
        $action = $action !== '' ? $action : $this->closeAction($operatorType);

        return $this->withDeadlockRetry(function () use ($id, $remark, $operatorType, $operatorId, $expectedUserId, $action): bool {
            return $this->transaction(function () use ($id, $remark, $operatorType, $operatorId, $expectedUserId, $action): bool {
                /** @var PointsExchangeOrder|null $order */
                $order = $this->model()->where('id', $id)->lock(true)->find();
                if (!$order) {
                    throw new BusinessException('兑换单不存在');
                }
                if ($expectedUserId !== null && (int) $order->user_id !== $expectedUserId) {
                    throw new BusinessException('兑换单不存在');
                }
                if ((int) $order->status !== PointsExchangeOrder::STATUS_PENDING_SHIP) {
                    throw new BusinessException('只有待发货兑换单可以关闭');
                }
                $fromStatus = (int) $order->status;

                /** @var PointsGoods|null $pointsGoods */
                $pointsGoods = $this->model(PointsGoods::class)
                    ->where('id', (int) $order->points_goods_id)
                    ->lock(true)
                    ->find();
                if ($pointsGoods !== null) {
                    $pointsGoods->exchange_stock = (int) $pointsGoods->exchange_stock + (int) $order->quantity;
                    $pointsGoods->exchanged_count = max(0, (int) $pointsGoods->exchanged_count - (int) $order->quantity);
                    $pointsGoods->save();
                }

                app()->make(StockService::class)->restore((int) $order->sku_id, (int) $order->quantity);
                app()->make(UserPointsAccountService::class)->returnExchangeByOperator(
                    (int) $order->user_id,
                    (string) $order->sn,
                    (int) $order->total_points,
                    $operatorType,
                    $operatorId,
                    '积分兑换关闭返还'
                );

                $order->save([
                    'status' => PointsExchangeOrder::STATUS_CLOSED,
                    'admin_remark' => $remark,
                    'closed_at' => date('Y-m-d H:i:s'),
                ]);
                app()->make(PointsExchangeOrderLogService::class)->record(
                    order: $order,
                    action: $action,
                    fromStatus: $fromStatus,
                    toStatus: PointsExchangeOrder::STATUS_CLOSED,
                    operatorType: $operatorType,
                    operatorId: $operatorId,
                    remark: $remark
                );

                return true;
            });
        });
    }

    private function closeAction(int $operatorType): string
    {
        return match ($operatorType) {
            OperatorType::BUYER => PointsExchangeOrderLog::ACTION_BUYER_CANCEL,
            OperatorType::ADMIN => PointsExchangeOrderLog::ACTION_ADMIN_CLOSE,
            default => PointsExchangeOrderLog::ACTION_SYSTEM_CLOSE,
        };
    }
}
