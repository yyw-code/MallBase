<?php
declare(strict_types=1);

namespace app\service\admin\marketing;

use app\common\enum\OperatorType;
use app\common\service\DeadlockRetryTrait;
use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\model\marketing\PointsExchangeOrder;
use app\model\marketing\PointsExchangeOrderLog;
use app\service\logistics\LogisticsService;
use app\service\marketing\PointsExchangeOrderLogService;
use app\service\marketing\PointsExchangeOrderLifecycleService;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台积分兑换单服务
 *
 * @extends BaseService<PointsExchangeOrder>
 */
class PointsExchangeOrderService extends BaseService
{
    use DeadlockRetryTrait;

    protected string $modelClass = PointsExchangeOrder::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['user_id'] ?? null) !== null && $where['user_id'] !== '', function ($q) use ($where): void {
                $q->where('user_id', (int) $where['user_id']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where): void {
                $q->where('status', (int) $where['status']);
            })
            ->when(trim((string) ($where['sn'] ?? '')) !== '', function ($q) use ($where): void {
                $q->where('sn', trim((string) $where['sn']));
            });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->buildListQuery($where);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = $this->formatRows($rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);
        if (!$info) {
            throw new BusinessException('兑换单不存在');
        }

        $row = $this->formatRows([$info->toArray()])[0] ?? [];
        $row['logs'] = app()->make(PointsExchangeOrderLogService::class)->listByOrderId($id);

        return $row;
    }

    /**
     * @return array<int, array{value:int,label:string}>
     */
    public function statusOptions(): array
    {
        return PointsExchangeOrder::statusOptions();
    }

    public function ship(int $id, array $data, int $adminId): bool
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        $deliveryType = $this->normalizeDeliveryType((string) ($data['delivery_type'] ?? ''));
        $deliveryNote = mb_substr(trim((string) ($data['delivery_note'] ?? '')), 0, 255);
        $logisticsNo = mb_substr(trim((string) ($data['logistics_no'] ?? '')), 0, 80);
        $company = null;

        if ($deliveryType === PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL) {
            if ($deliveryNote === '') {
                throw new BusinessException('请填写虚拟发货说明');
            }
        } else {
            if ($logisticsNo === '') {
                throw new BusinessException('请填写物流单号');
            }
            /** @var LogisticsService $logisticsService */
            $logisticsService = app()->make(LogisticsService::class);
            $company = $logisticsService->resolveCompany(
                (string) ($data['logistics_platform'] ?? ''),
                (int) ($data['logistics_company_id'] ?? 0),
                (string) ($data['logistics_company_code'] ?? ''),
                (string) ($data['logistics_company'] ?? '')
            );
        }

        $this->withDeadlockRetry(function () use ($id, $deliveryType, $deliveryNote, $company, $logisticsNo, $data, $adminId): void {
            $this->transaction(function () use ($id, $deliveryType, $deliveryNote, $company, $logisticsNo, $data, $adminId): void {
                /** @var PointsExchangeOrder|null $order */
                $order = $this->model()->where('id', $id)->lock(true)->find();
                if (!$order) {
                    throw new BusinessException('兑换单不存在');
                }
                if ((int) $order->status !== PointsExchangeOrder::STATUS_PENDING_SHIP) {
                    throw new BusinessException('当前兑换单状态不允许发货');
                }
                $fromStatus = (int) $order->status;

                $payload = [
                    'status' => PointsExchangeOrder::STATUS_SHIPPED,
                    'delivery_type' => $deliveryType,
                    'delivery_note' => $deliveryType === PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL ? $deliveryNote : null,
                    'admin_remark' => mb_substr(trim((string) ($data['admin_remark'] ?? '')), 0, 255),
                    'shipped_at' => date('Y-m-d H:i:s'),
                ];
                if ($deliveryType === PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL) {
                    $payload = array_merge($payload, [
                        'logistics_platform' => null,
                        'logistics_company_id' => 0,
                        'logistics_company_code' => null,
                        'logistics_company' => null,
                        'logistics_no' => null,
                    ]);
                    $remark = '虚拟发货：' . $deliveryNote;
                } else {
                    $payload = array_merge($payload, [
                        'logistics_platform' => mb_substr((string) $company['platform'], 0, 32),
                        'logistics_company_id' => (int) $company['company_id'],
                        'logistics_company_code' => mb_substr((string) $company['code'], 0, 64),
                        'logistics_company' => mb_substr((string) $company['name'], 0, 80),
                        'logistics_no' => $logisticsNo,
                    ]);
                    $remark = sprintf('物流公司：%s，物流单号：%s', $company['name'], $logisticsNo);
                }
                $order->save($payload);
                app()->make(PointsExchangeOrderLogService::class)->record(
                    order: $order,
                    action: PointsExchangeOrderLog::ACTION_SHIP,
                    fromStatus: $fromStatus,
                    toStatus: PointsExchangeOrder::STATUS_SHIPPED,
                    operatorType: OperatorType::ADMIN,
                    operatorId: $adminId,
                    remark: $remark
                );
            });
        });

        return true;
    }

    public function complete(int $id, int $adminId): bool
    {
        $this->withDeadlockRetry(function () use ($id, $adminId): void {
            $this->transaction(function () use ($id, $adminId): void {
                /** @var PointsExchangeOrder|null $order */
                $order = $this->model()->where('id', $id)->lock(true)->find();
                if (!$order) {
                    throw new BusinessException('兑换单不存在');
                }
                if ((int) $order->status !== PointsExchangeOrder::STATUS_SHIPPED) {
                    throw new BusinessException('只有已发货兑换单可以完成');
                }
                $fromStatus = (int) $order->status;

                $order->save([
                    'status' => PointsExchangeOrder::STATUS_COMPLETED,
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
                app()->make(PointsExchangeOrderLogService::class)->record(
                    order: $order,
                    action: PointsExchangeOrderLog::ACTION_COMPLETE,
                    fromStatus: $fromStatus,
                    toStatus: PointsExchangeOrder::STATUS_COMPLETED,
                    operatorType: OperatorType::ADMIN,
                    operatorId: $adminId,
                    remark: '后台确认兑换完成'
                );
            });
        });

        return true;
    }

    public function close(int $id, string $remark, int $adminId): bool
    {
        return app()->make(PointsExchangeOrderLifecycleService::class)->closePending(
            id: $id,
            remark: $remark,
            operatorType: OperatorType::ADMIN,
            operatorId: $adminId
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $hydrator = app()->make(AssetHydrator::class);
        $rows = $this->fillMissingGoodsImages($rows, $hydrator);
        foreach ($rows as &$row) {
            $status = (int) ($row['status'] ?? 0);
            $row['status_text'] = PointsExchangeOrder::statusText($status);
            $row['delivery_type'] = $this->normalizeDeliveryType((string) ($row['delivery_type'] ?? ''));
            $row['delivery_type_text'] = PointsExchangeOrder::deliveryTypeLabel((string) $row['delivery_type']);
            $row['receiver_full_address'] = implode(' ', array_filter([
                (string) ($row['receiver_province'] ?? ''),
                (string) ($row['receiver_city'] ?? ''),
                (string) ($row['receiver_district'] ?? ''),
                (string) ($row['receiver_address'] ?? ''),
            ]));
        }
        unset($row);

        return $hydrator->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);
    }

    /**
     * 历史兑换单可能没有商品图快照，后台列表展示时用当前 SKU/商品图兜底。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function fillMissingGoodsImages(array $rows, AssetHydrator $hydrator): array
    {
        $missingRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => trim((string) ($row['goods_image'] ?? '')) === '' || (string) ($row['goods_image'] ?? '') === '0'
        ));
        if ($missingRows === []) {
            return $rows;
        }

        $goodsIds = array_values(array_unique(array_filter(
            array_map(static fn(array $row): int => (int) ($row['goods_id'] ?? 0), $missingRows)
        )));
        $skuIds = array_values(array_unique(array_filter(
            array_map(static fn(array $row): int => (int) ($row['sku_id'] ?? 0), $missingRows)
        )));

        $goodsMap = [];
        if ($goodsIds !== []) {
            $goodsRows = $this->model(Goods::class)
                ->whereIn('id', $goodsIds)
                ->field('id,main_image,images')
                ->select()
                ->toArray();
            foreach ($goodsRows as $goods) {
                $goodsMap[(int) $goods['id']] = $goods;
            }
        }

        $skuMap = $skuIds === []
            ? []
            : $this->model(GoodsSku::class)
                ->whereIn('id', $skuIds)
                ->column('id,goods_id,image', 'id');

        foreach ($rows as &$row) {
            $rawImage = trim((string) ($row['goods_image'] ?? ''));
            if ($rawImage !== '' && $rawImage !== '0') {
                continue;
            }

            $goods = $goodsMap[(int) ($row['goods_id'] ?? 0)] ?? [];
            $sku = $skuMap[(int) ($row['sku_id'] ?? 0)] ?? [];
            $fallbackImage = $hydrator->firstImageValue($goods['images'] ?? []);
            $row['goods_image'] = (string) (($sku['image'] ?? '') ?: ($goods['main_image'] ?? '') ?: $fallbackImage);
        }
        unset($row);

        return $rows;
    }

    private function normalizeDeliveryType(string $deliveryType): string
    {
        return $deliveryType === PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL
            ? PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL
            : PointsExchangeOrder::DELIVERY_TYPE_PHYSICAL;
    }
}
