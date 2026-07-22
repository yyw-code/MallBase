<?php
declare(strict_types=1);

namespace app\service\connector;

use app\common\enum\OperatorType;
use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\OrderLog;
use app\model\order\RefundOrder;
use app\model\user\User;
use app\service\admin\order\OrderAdminService;
use app\service\admin\order\RefundOrderAdminService;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * MallBase 暴露给客服系统的服务端连接器。
 *
 * 这里不绕过 MallBase 业务规则；涉及状态变化的操作继续委托现有后台订单服务。
 *
 * @extends BaseService<Order>
 */
class CustomerServiceConnectorService extends BaseService
{
    protected string $modelClass = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [
            'service' => 'mallbase-customer-service-connector',
            'enabled' => true,
            'time' => date('c'),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>}
     */
    public function productSearch(array $input): array
    {
        $keyword = trim((string) ($input['keyword'] ?? ''));
        $limit = max(1, min((int) ($input['limit'] ?? 10), 20));
        $skuGoodsIds = $keyword !== '' ? $this->searchSkuGoodsIds($keyword) : [];

        $query = $this->model(Goods::class)
            ->where('status', 1)
            ->where('is_on_sale', 1)
            ->whereNull('delete_time');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword, $skuGoodsIds): void {
                $q->whereLike('name|subtitle', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $q->whereOr('id', (int) $keyword);
                }
                if ($skuGoodsIds !== []) {
                    $q->whereOr('id', 'in', $skuGoodsIds);
                }
            });
        }

        $rows = $query
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
        $rows = app()->make(AssetHydrator::class)->hydrateGoodsList($rows);

        return [
            'items' => array_map([$this, 'mapProductSearchItem'], $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productSummary(int $goodsId): array
    {
        /** @var Goods|null $goods */
        $goods = $this->model(Goods::class)
            ->where('id', $goodsId)
            ->whereNull('delete_time')
            ->find();
        if ($goods === null) {
            throw new BusinessException('商品不存在', 404);
        }

        $skus = $this->model(GoodsSku::class)
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->order('id', 'asc')
            ->limit(20)
            ->select()
            ->toArray();

        return [
            'id' => (int) $goods->id,
            'name' => (string) $goods->name,
            'subtitle' => (string) ($goods->subtitle ?? ''),
            'price' => (string) $goods->price,
            'market_price' => $goods->market_price !== null ? (string) $goods->market_price : null,
            'stock' => (int) $goods->stock,
            'sales' => (int) $goods->sales,
            'unit' => (string) ($goods->unit ?? ''),
            'is_on_sale' => (int) $goods->is_on_sale === 1,
            'status' => (int) $goods->status,
            'main_image' => $goods->main_image !== null ? (int) $goods->main_image : null,
            'skus' => array_map(static function (array $sku): array {
                return [
                    'id' => (int) $sku['id'],
                    'spec_values' => (string) ($sku['spec_values'] ?? ''),
                    'price' => (string) $sku['price'],
                    'market_price' => $sku['market_price'] !== null ? (string) $sku['market_price'] : null,
                    'stock' => (int) $sku['stock'],
                    'sku_code' => (string) ($sku['sku_code'] ?? ''),
                    'status' => (int) ($sku['status'] ?? 0),
                ];
            }, $skus),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function searchSkuGoodsIds(string $keyword): array
    {
        $rows = $this->model(GoodsSku::class)
            ->where('status', 1)
            ->where('sku_code', 'like', '%' . $keyword . '%')
            ->limit(50)
            ->column('goods_id');

        return array_values(array_unique(array_filter(array_map('intval', $rows), static fn(int $id): bool => $id > 0)));
    }

    /**
     * @param array<string, mixed> $goods
     * @return array<string, mixed>
     */
    private function mapProductSearchItem(array $goods): array
    {
        return [
            'id' => (int) ($goods['id'] ?? 0),
            'name' => (string) ($goods['name'] ?? ''),
            'subtitle' => (string) ($goods['subtitle'] ?? ''),
            'price' => isset($goods['price']) ? (string) $goods['price'] : '',
            'stock' => (int) ($goods['stock'] ?? 0),
            'sales' => (int) ($goods['sales'] ?? 0),
            'unit' => (string) ($goods['unit'] ?? ''),
            'status' => '上架',
            'status_text' => '上架',
            'image' => (string) ($goods['main_image_full_url'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderSummary(int $orderId): array
    {
        /** @var Order|null $order */
        $order = $this->model(Order::class)
            ->with([
                'buyer' => static function ($query): void {
                    $query->field('id,nickname,mobile,email,avatar,status')
                        ->whereNull('delete_time');
                },
            ])
            ->where('id', $orderId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在', 404);
        }

        $items = $this->model(OrderItem::class)
            ->where('order_id', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $logs = $this->model(OrderLog::class)
            ->where('order_id', $orderId)
            ->order('id', 'asc')
            ->limit(30)
            ->select()
            ->toArray();
        $refunds = $this->model(RefundOrder::class)
            ->where('order_id', $orderId)
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        $buyer = $order->buyer ? $order->buyer->toArray() : null;

        return [
            'id' => (int) $order->id,
            'sn' => (string) $order->sn,
            'user_id' => (int) $order->user_id,
            'status' => (int) $order->status,
            'status_text' => (string) $order->status_text,
            'total_amount' => (string) $order->total_amount,
            'freight_amount' => (string) $order->freight_amount,
            'discount_amount' => (string) $order->discount_amount,
            'pay_amount' => (string) $order->pay_amount,
            'pay_method' => $order->pay_method !== null ? (int) $order->pay_method : null,
            'pay_method_text' => (string) $order->pay_method_text,
            'buyer_remark' => (string) ($order->buyer_remark ?? ''),
            'admin_remark' => (string) ($order->admin_remark ?? ''),
            'receiver' => [
                'name' => $this->maskName((string) $order->receiver_name),
                'phone' => $this->maskMobile((string) $order->receiver_phone),
                'region' => trim((string) $order->receiver_province . (string) $order->receiver_city . (string) $order->receiver_district),
                'address' => $this->maskAddress((string) $order->receiver_address),
            ],
            'delivery' => [
                'type' => (string) ($order->delivery_type ?? Order::DELIVERY_TYPE_PHYSICAL),
                'type_text' => (string) $order->delivery_type_text,
                'note' => (string) ($order->delivery_note ?? ''),
                'logistics_company' => (string) ($order->logistics_company ?? ''),
                'logistics_sn' => (string) ($order->logistics_sn ?? ''),
                'shipped_at' => $order->shipped_at,
            ],
            'buyer' => $buyer !== null ? [
                'id' => (int) $buyer['id'],
                'nickname' => (string) ($buyer['nickname'] ?? ''),
                'mobile' => $this->maskMobile((string) ($buyer['mobile'] ?? '')),
                'email' => $this->maskEmail((string) ($buyer['email'] ?? '')),
                'avatar' => $buyer['avatar'] !== null ? (int) $buyer['avatar'] : null,
                'status' => (int) ($buyer['status'] ?? 0),
            ] : null,
            'items' => array_map(static function (array $item): array {
                return [
                    'id' => (int) $item['id'],
                    'goods_id' => (int) $item['goods_id'],
                    'sku_id' => (int) $item['sku_id'],
                    'goods_name' => (string) $item['goods_name'],
                    'goods_image' => $item['goods_image'] !== null ? (int) $item['goods_image'] : null,
                    'sku_spec' => (string) ($item['sku_spec'] ?? ''),
                    'unit_price' => (string) $item['unit_price'],
                    'quantity' => (int) $item['quantity'],
                    'pay_amount' => (string) $item['pay_amount'],
                    'refunded_quantity' => (int) $item['refunded_quantity'],
                ];
            }, $items),
            'refunds' => array_map(static function (array $refund): array {
                return [
                    'id' => (int) $refund['id'],
                    'sn' => (string) $refund['sn'],
                    'type' => (int) $refund['type'],
                    'type_text' => (string) ($refund['type_text'] ?? ''),
                    'status' => (int) $refund['status'],
                    'status_text' => (string) ($refund['status_text'] ?? ''),
                    'refund_amount' => (string) $refund['refund_amount'],
                    'reason' => (string) ($refund['reason'] ?? ''),
                    'remark' => (string) ($refund['remark'] ?? ''),
                ];
            }, $refunds),
            'logs' => array_map(static function (array $log): array {
                return [
                    'id' => (int) $log['id'],
                    'from_status' => $log['from_status'] !== null ? (int) $log['from_status'] : null,
                    'to_status' => (int) $log['to_status'],
                    'to_status_text' => (string) ($log['to_status_text'] ?? ''),
                    'operator_type' => (int) $log['operator_type'],
                    'operator_type_text' => (string) ($log['operator_type_text'] ?? ''),
                    'remark' => (string) ($log['remark'] ?? ''),
                    'create_time' => $log['create_time'] ?? null,
                ];
            }, $logs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function userSummary(int $userId): array
    {
        /** @var User|null $user */
        $user = $this->model(User::class)
            ->where('id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($user === null) {
            throw new BusinessException('用户不存在', 404);
        }

        $orderCount = $this->model(Order::class)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->count();
        $paidAmount = $this->model(Order::class)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->whereNotNull('paid_at')
            ->sum('pay_amount');

        return [
            'id' => (int) $user->id,
            'username' => (string) ($user->username ?? ''),
            'nickname' => (string) ($user->nickname ?? ''),
            'mobile' => $this->maskMobile((string) ($user->mobile ?? '')),
            'email' => $this->maskEmail((string) ($user->email ?? '')),
            'avatar' => $user->avatar !== null ? (int) $user->avatar : null,
            'status' => (int) $user->status,
            'register_type' => (string) ($user->register_type ?? ''),
            'last_login_time' => $user->last_login_time,
            'create_time' => $user->create_time,
            'order_count' => (int) $orderCount,
            'paid_amount' => (string) $paidAmount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function addOrderRemark(
        int $orderId,
        string $remark,
        string $actorName,
        string $idempotencyKey
    ): array
    {
        $remark = mb_substr(trim($remark), 0, 200);
        if ($remark === '') {
            throw new BusinessException('备注内容不能为空');
        }
        $actorName = mb_substr($actorName, 0, 30);
        $adminId = $this->operatorAdminId();

        return $this->executeIdempotentWrite(
            'customer-service:order-remark:' . $orderId,
            $idempotencyKey,
            [
                'remark' => $remark,
                'actor_name' => $actorName,
            ],
            function () use ($orderId, $remark, $actorName, $adminId): array {
                /** @var Order|null $order */
                $order = $this->model(Order::class)
                    ->where('id', $orderId)
                    ->whereNull('delete_time')
                    ->find();
                if ($order === null) {
                    throw new BusinessException('订单不存在', 404);
                }

                $prefix = $actorName !== '' ? '客服备注(' . $actorName . ')：' : '客服备注：';
                $message = mb_substr($prefix . $remark, 0, 255);

                $this->transaction(function () use ($order, $adminId, $message): void {
                    $oldRemark = trim((string) ($order->admin_remark ?? ''));
                    $order->admin_remark = mb_substr($oldRemark !== '' ? $oldRemark . "\n" . $message : $message, 0, 255);
                    $order->save();

                    $this->model(OrderLog::class)->save([
                        'order_id' => (int) $order->id,
                        'from_status' => (int) $order->status,
                        'to_status' => (int) $order->status,
                        'operator_type' => OperatorType::ADMIN,
                        'operator_id' => $adminId,
                        'remark' => $message,
                        'ip' => request()->ip(),
                    ]);
                });

                return [
                    'id' => $orderId,
                    'remark' => $message,
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function shipOrder(int $orderId, array $payload, string $idempotencyKey): array
    {
        $deliveryType = (string) ($payload['delivery_type'] ?? '');
        $companyId = max(0, (int) ($payload['logistics_company_id'] ?? 0));
        $companyCode = trim((string) ($payload['logistics_company_code'] ?? ''));
        $companyName = trim((string) ($payload['logistics_company'] ?? ''));
        $input = [
            'logistics_platform' => trim((string) ($payload['logistics_platform'] ?? '')),
            'logistics_company_id' => $companyId,
            'logistics_company_code' => $companyId > 0 ? '' : $companyCode,
            'logistics_company' => $companyId > 0 || $companyCode !== '' ? '' : $companyName,
            'logistics_sn' => trim((string) ($payload['logistics_sn'] ?? '')),
            'delivery_type' => $deliveryType === Order::DELIVERY_TYPE_VIRTUAL
                ? Order::DELIVERY_TYPE_VIRTUAL
                : Order::DELIVERY_TYPE_PHYSICAL,
            'delivery_note' => mb_substr(trim((string) ($payload['delivery_note'] ?? '')), 0, 255),
        ];
        if ($input['delivery_type'] === Order::DELIVERY_TYPE_VIRTUAL) {
            $input['logistics_platform'] = '';
            $input['logistics_company_id'] = 0;
            $input['logistics_company_code'] = '';
            $input['logistics_company'] = '';
            $input['logistics_sn'] = '';
        } else {
            $input['delivery_note'] = '';
        }
        if ($input['delivery_type'] === Order::DELIVERY_TYPE_PHYSICAL
            && (($input['logistics_company_id'] <= 0
                    && $input['logistics_company_code'] === ''
                    && $input['logistics_company'] === '')
                || $input['logistics_sn'] === '')) {
            throw new BusinessException('物流公司和运单号必填');
        }
        if ($input['delivery_type'] === Order::DELIVERY_TYPE_VIRTUAL
            && $input['delivery_note'] === '') {
            throw new BusinessException('请填写虚拟发货说明');
        }
        $adminId = $this->operatorAdminId();

        return $this->executeIdempotentWrite(
            'customer-service:order-ship:' . $orderId,
            $idempotencyKey,
            $input,
            function () use ($orderId, $input, $adminId): array {
                $message = app()->make(OrderAdminService::class)->ship(
                    orderId: $orderId,
                    logisticsPlatform: $input['logistics_platform'],
                    logisticsCompanyId: $input['logistics_company_id'],
                    logisticsCompanyCode: $input['logistics_company_code'],
                    logisticsCompany: $input['logistics_company'],
                    logisticsSn: $input['logistics_sn'],
                    adminId: $adminId,
                    deliveryType: $input['delivery_type'],
                    deliveryNote: $input['delivery_note'],
                );

                return [
                    'id' => $orderId,
                    'message' => $message,
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function approveRefund(int $refundId, array $payload, string $idempotencyKey): array
    {
        $input = [
            'admin_remark' => mb_substr((string) ($payload['admin_remark'] ?? ''), 0, 255),
        ];
        $adminId = $this->operatorAdminId();

        return $this->executeIdempotentWrite(
            'customer-service:refund-approve:' . $refundId,
            $idempotencyKey,
            $input,
            function () use ($refundId, $input, $adminId): array {
                app()->make(RefundOrderAdminService::class)->approve(
                    refundId: $refundId,
                    adminId: $adminId,
                    adminRemark: $input['admin_remark'],
                );

                return ['id' => $refundId, 'message' => '审核通过'];
            }
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function rejectRefund(int $refundId, array $payload, string $idempotencyKey): array
    {
        $input = [
            'admin_remark' => mb_substr(trim((string) ($payload['admin_remark'] ?? '')), 0, 255),
        ];
        if ($input['admin_remark'] === '') {
            throw new BusinessException('驳回原因必填');
        }
        $adminId = $this->operatorAdminId();

        return $this->executeIdempotentWrite(
            'customer-service:refund-reject:' . $refundId,
            $idempotencyKey,
            $input,
            function () use ($refundId, $input, $adminId): array {
                app()->make(RefundOrderAdminService::class)->reject(
                    refundId: $refundId,
                    adminId: $adminId,
                    adminRemark: $input['admin_remark'],
                );

                return ['id' => $refundId, 'message' => '已驳回'];
            }
        );
    }

    /**
     * @param array<string, mixed> $normalizedInput
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function executeIdempotentWrite(
        string $scope,
        string $idempotencyKey,
        array $normalizedInput,
        callable $operation
    ): array {
        return app()->make(CustomerServiceIdempotencyService::class)->execute(
            $scope,
            $idempotencyKey,
            $normalizedInput,
            $operation
        );
    }

    private function operatorAdminId(): int
    {
        $adminId = app()->make(CustomerServiceSettingService::class)->operatorAdminId();
        if ($adminId <= 0) {
            throw new BusinessException('客服连接器操作管理员未配置', 503);
        }

        return $adminId;
    }

    private function maskMobile(string $mobile): string
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            return '';
        }
        if (mb_strlen($mobile) < 7) {
            return mb_substr($mobile, 0, 1) . '***';
        }

        return mb_substr($mobile, 0, 3) . '****' . mb_substr($mobile, -4);
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$name, $domain] = explode('@', $email, 2);
        return mb_substr($name, 0, 1) . '***@' . $domain;
    }

    private function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (mb_strlen($name) <= 1) {
            return '*';
        }

        return mb_substr($name, 0, 1) . str_repeat('*', max(1, mb_strlen($name) - 1));
    }

    private function maskAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (mb_strlen($address) <= 8) {
            return mb_substr($address, 0, 2) . '***';
        }

        return mb_substr($address, 0, 6) . '***' . mb_substr($address, -2);
    }
}
