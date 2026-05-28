<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\order\RefundOrder;
use app\common\enum\OperatorType;
use app\common\enum\RefundOrderStatus;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 售后订单状态机（状态流转唯一入口）
 *
 * 设计原则：
 *  - 业务代码一律调用 {@see transit}，禁止直接 $refund->status = X
 *  - 白名单由 {@see RefundOrderStatus::canTransit} 维护，非法流转抛 BusinessException
 *  - 事务内原子完成以下三件事：
 *      1) 更新 status
 *      2) 写入对应时间戳（reviewed_at / refunded_at / canceled_at）
 *      3) 同步审核字段（admin_remark / reviewed_by），仅在管理员流转路径落库
 *  - 与 {@see OrderStatusMachine} 的区别：
 *      · 审计落在售后单自身冗余字段（admin_remark/reviewed_by/...），不写 mb_order_log
 *      · 由于 MVP 仅一次审核即终态，冗余列比独立日志表更省维护成本
 *  - 不自己启动事务时，调用方负责在外层包裹事务，
 *    以与 OrderItem.refunded_quantity 更新、退款渠道处理组合成原子单元
 *
 * @extends BaseService<RefundOrder>
 */
class RefundOrderStatusMachine extends BaseService
{
    protected string $modelClass = RefundOrder::class;

    /**
     * 状态 → 时间戳列名映射
     *
     * 新增终态时务必同步此表，避免时间戳缺失导致列表筛选失效。
     *  - REFUNDING：审核通过并发起退款，同时记录 reviewed_at
     *  - COMPLETED：退款完成，同时记录 reviewed_at + refunded_at
     *  - REJECTED ：审核驳回，仅记录 reviewed_at
     *  - CLOSED   ：买家主动取消，记录 canceled_at
     */
    private const STATUS_TIMESTAMP = [
        RefundOrderStatus::REFUNDING => null,
        RefundOrderStatus::COMPLETED => 'refunded_at',
        RefundOrderStatus::REJECTED  => null,
        RefundOrderStatus::CLOSED    => 'canceled_at',
    ];

    /**
     * 管理员操作路径目标状态集合（需要写 reviewed_by / reviewed_at / admin_remark）
     */
    private const ADMIN_REVIEW_STATUSES = [
        RefundOrderStatus::REFUNDING,
        RefundOrderStatus::COMPLETED,
        RefundOrderStatus::REJECTED,
    ];

    /**
     * 执行售后状态流转
     *
     * @param RefundOrder $refund        当前售后单（方法内会直接修改字段并 save）
     * @param int         $toStatus      目标状态（必须命中 RefundOrderStatus::canTransit 白名单）
     * @param int         $operatorType  操作方类型（{@see \app\common\enum\OperatorType}：0 系统 / 1 买家 / 2 管理员）
     * @param int|null    $operatorId    操作方主键（系统触发时可为 null；管理员审核路径必须传）
     * @param string|null $remark        备注（审核意见 / 取消原因），最长 255
     *
     * @throws BusinessException 状态非法 / 不允许流转 / 管理员审核缺 operatorId
     */
    public function transit(
        RefundOrder $refund,
        int $toStatus,
        int $operatorType,
        ?int $operatorId = null,
        ?string $remark = null
    ): void {
        if (!RefundOrderStatus::isValid($toStatus)) {
            throw new BusinessException('售后目标状态不合法');
        }

        $fromStatus = (int) ($refund->status ?? 0);
        if ($fromStatus === $toStatus) {
            // 幂等保护：重复流转到同一状态不报错、不落库
            return;
        }

        if (!RefundOrderStatus::canTransit($fromStatus, $toStatus)) {
            throw new BusinessException(sprintf(
                '售后状态不允许从「%s」流转到「%s」',
                RefundOrderStatus::textOf($fromStatus),
                RefundOrderStatus::textOf($toStatus)
            ));
        }

        $isAdminReview = $operatorType === OperatorType::ADMIN
            && in_array($toStatus, self::ADMIN_REVIEW_STATUSES, true);
        if ($isAdminReview && $operatorId === null) {
            throw new BusinessException('管理员审核流转必须传入操作者ID');
        }

        $this->transaction(function () use ($refund, $toStatus, $isAdminReview, $operatorId, $remark): void {
            $refund->status = $toStatus;

            // 1. 对应终态时间戳（与 datetime 格式对齐主订单表）
            $timestampColumn = self::STATUS_TIMESTAMP[$toStatus] ?? null;
            if ($timestampColumn !== null) {
                $refund->{$timestampColumn} = date('Y-m-d H:i:s');
            }

            // 2. 管理员审核路径同步 reviewed_by / reviewed_at
            if ($isAdminReview) {
                $refund->reviewed_at = date('Y-m-d H:i:s');
                $refund->reviewed_by = $operatorId;
            }

            // 3. 备注（审核意见 / 取消原因）
            if ($remark !== null && $remark !== '') {
                $refund->admin_remark = mb_substr($remark, 0, 255);
            }

            $refund->save();
        });
    }
}
