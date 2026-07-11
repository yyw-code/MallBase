<?php
declare(strict_types=1);

namespace app\service\logistics;

use app\model\logistics\LogisticsCompany;
use app\model\logistics\LogisticsPlatform;
use app\model\logistics\LogisticsTrack;
use app\model\order\Order;
use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;
use mall_base\drivers\logistics\BaseLogisticsDriver;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * 物流查询服务
 *
 * 业务编排留在 Service：校验订单归属、按订单物流平台快照查询、控制缓存、保存轨迹快照。
 * 第三方平台签名与响应标准化由 logistics 驱动负责。
 *
 * @extends BaseService<LogisticsTrack>
 */
class LogisticsService extends BaseService
{
    protected string $modelClass = LogisticsTrack::class;

    private const DEFAULT_PLATFORM = 'kdniao';
    private const DEFAULT_CACHE_MINUTES = 30;

    /**
     * 后台发货物流公司选择项。
     *
     * @return array<int, array{id:int,platform:string,label:string,value:int,code:string,name:string}>
     */
    public function companyOptions(string $platform = ''): array
    {
        $platform = $platform !== '' ? $platform : $this->defaultPlatformCode();
        if ($platform === '') {
            return [];
        }

        $rows = $this->model(LogisticsCompany::class)
            ->where('platform', $platform)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->field('id, platform, code, name')
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => [
            'id'       => (int) ($row['id'] ?? 0),
            'platform' => (string) ($row['platform'] ?? ''),
            'label'    => (string) ($row['name'] ?? ''),
            'value'    => (int) ($row['id'] ?? 0),
            'code'     => (string) ($row['code'] ?? ''),
            'name'     => (string) ($row['name'] ?? ''),
        ], $rows);
    }

    /**
     * 发货时解析平台物流公司，返回订单快照字段。
     *
     * @return array{platform:string,company_id:int,code:string,name:string}
     */
    public function resolveCompany(
        string $platform,
        int $companyId,
        string $companyCode = '',
        string $companyName = ''
    ): array {
        $platform = trim($platform) !== '' ? trim($platform) : $this->defaultPlatformCode();
        if ($platform === '') {
            throw new BusinessException('请先启用物流平台');
        }

        $query = $this->model(LogisticsCompany::class)
            ->where('platform', $platform)
            ->where('status', 1);
        if ($companyId > 0) {
            $query->where('id', $companyId);
        } elseif (trim($companyCode) !== '') {
            $query->where('code', trim($companyCode));
        } elseif (trim($companyName) !== '') {
            $query->where('name', trim($companyName));
        } else {
            throw new BusinessException('请选择物流公司');
        }

        /** @var LogisticsCompany|null $company */
        $company = $query->find();
        if ($company === null) {
            throw new BusinessException('物流公司不存在或已停用');
        }

        return [
            'platform'   => (string) $company->platform,
            'company_id' => (int) $company->id,
            'code'       => (string) $company->code,
            'name'       => (string) $company->name,
        ];
    }

    /**
     * 发货时创建或更新本地轨迹快照，不触发第三方查询。
     */
    public function syncOrderShipment(Order $order): void
    {
        $trackingNo = trim((string) ($order->logistics_sn ?? ''));
        if ($trackingNo === '') {
            return;
        }

        $shipment = $this->shipmentSnapshot($order);
        if ($shipment['company_code'] === '' && $shipment['company_name'] === '') {
            return;
        }

        $track = $this->findOrderTrack((int) $order->id);
        $shipmentChanged = $this->shipmentChanged($track, $shipment);
        $payload = [
            'business_type' => LogisticsTrack::BUSINESS_ORDER,
            'business_id'   => (int) $order->id,
            'order_id'      => (int) $order->id,
            'provider'      => $shipment['platform'],
            'company_id'    => $shipment['company_id'],
            'company_code'  => mb_substr($shipment['company_code'], 0, 64),
            'company_name'  => mb_substr($shipment['company_name'], 0, 100),
            'tracking_no'   => mb_substr($trackingNo, 0, 64),
            'state'         => $shipmentChanged ? 'pending' : (string) ($track?->state ?? 'pending'),
            'status_text'   => $shipmentChanged ? '待查询' : (string) ($track?->status_text ?? '待查询'),
        ];

        if ($shipmentChanged) {
            $payload = array_merge($payload, $this->resetTrackSnapshot());
        }

        if ($track === null) {
            $this->model()->save($payload);
            return;
        }

        $track->save($payload);
    }

    /**
     * 买家查看订单物流。
     *
     * @return array<string, mixed>
     */
    public function clientOrderDetail(int $userId, int $orderId): array
    {
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }

        /** @var Order|null $order */
        $order = $this->model(Order::class)
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }
        if ((string) ($order->delivery_type ?? Order::DELIVERY_TYPE_PHYSICAL) === Order::DELIVERY_TYPE_VIRTUAL) {
            return $this->virtualOrderResponse($order);
        }

        $shipment = $this->shipmentSnapshot($order);
        if ($shipment['tracking_no'] === '' || ($shipment['company_code'] === '' && $shipment['company_name'] === '')) {
            return $this->emptyOrderResponse($order, $shipment, '订单暂无物流信息');
        }

        $track = $this->ensureOrderTrack($order, $shipment);
        $platform = $this->findPlatform($shipment['platform']);
        $queryError = '';

        if ($platform === null) {
            $queryError = '物流平台未配置';
        } elseif ($this->shouldRefresh($track, $platform)) {
            $queryError = $this->refreshTrack($track, $order, $shipment, $platform);
            /** @var LogisticsTrack $track */
            $track = $this->model()->where('id', (int) $track->id)->find();
        }

        return $this->formatOrderResponse($order, $shipment, $track, $queryError);
    }

    /**
     * @return array{platform:string,company_id:int,company_code:string,company_name:string,tracking_no:string}
     */
    private function shipmentSnapshot(Order $order): array
    {
        $platform = trim((string) ($order->logistics_platform ?? ''));
        $companyId = (int) ($order->logistics_company_id ?? 0);
        $companyCode = trim((string) ($order->logistics_company_code ?? ''));
        $companyName = trim((string) ($order->logistics_company ?? ''));

        /** @var LogisticsCompany|null $company */
        $company = null;
        if ($companyId > 0) {
            $company = $this->model(LogisticsCompany::class)->where('id', $companyId)->find();
        }
        if ($company === null && $platform !== '' && $companyCode !== '') {
            $company = $this->model(LogisticsCompany::class)
                ->where('platform', $platform)
                ->where('code', $companyCode)
                ->find();
        }

        if ($company !== null) {
            $platform = $platform !== '' ? $platform : (string) $company->platform;
            $companyId = $companyId > 0 ? $companyId : (int) $company->id;
            $companyCode = $companyCode !== '' ? $companyCode : (string) $company->code;
            $companyName = $companyName !== '' ? $companyName : (string) $company->name;
        }

        if ($platform === '') {
            $platform = $this->defaultPlatformCode() ?: self::DEFAULT_PLATFORM;
        }

        return [
            'platform'     => mb_substr($platform, 0, 32),
            'company_id'   => $companyId,
            'company_code' => mb_substr($companyCode, 0, 64),
            'company_name' => mb_substr($companyName, 0, 100),
            'tracking_no'  => mb_substr(trim((string) ($order->logistics_sn ?? '')), 0, 64),
        ];
    }

    /**
     * @param array{platform:string,company_id:int,company_code:string,company_name:string,tracking_no:string} $shipment
     * @return array<string, mixed>
     */
    private function emptyOrderResponse(Order $order, array $shipment, string $message): array
    {
        return [
            'available'          => false,
            'delivery_type'      => (string) ($order->delivery_type ?? Order::DELIVERY_TYPE_PHYSICAL),
            'delivery_type_text' => Order::deliveryTypeLabel((string) ($order->delivery_type ?? Order::DELIVERY_TYPE_PHYSICAL)),
            'delivery_note'      => (string) ($order->delivery_note ?? ''),
            'status'             => $message,
            'state'              => 'none',
            'platform'           => $shipment['platform'],
            'company'            => $shipment['company_name'],
            'company_code'       => $shipment['company_code'],
            'tracking_no'        => $shipment['tracking_no'],
            'receiver'           => $this->receiverOf($order),
            'tracks'             => [],
            'latest_desc'        => '',
            'latest_time'        => null,
            'cached'             => false,
            'last_query_at'      => null,
            'next_query_at'      => null,
            'query_error'        => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function virtualOrderResponse(Order $order): array
    {
        $note = trim((string) ($order->delivery_note ?? ''));
        return [
            'available'          => true,
            'delivery_type'      => Order::DELIVERY_TYPE_VIRTUAL,
            'delivery_type_text' => Order::deliveryTypeLabel(Order::DELIVERY_TYPE_VIRTUAL),
            'delivery_note'      => $note,
            'status'             => Order::deliveryTypeLabel(Order::DELIVERY_TYPE_VIRTUAL),
            'state'              => 'virtual',
            'platform'           => '',
            'company'            => '虚拟发货',
            'company_code'       => '',
            'tracking_no'        => '',
            'receiver'           => $this->receiverOf($order),
            'tracks'             => [],
            'latest_desc'        => $note !== '' ? $note : '虚拟商品已发货',
            'latest_time'        => ($this->datetimeToString($order->shipped_at ?? null) ?: null),
            'cached'             => false,
            'last_query_at'      => null,
            'next_query_at'      => null,
            'query_error'        => '',
        ];
    }

    /**
     * @param array{platform:string,company_id:int,company_code:string,company_name:string,tracking_no:string} $shipment
     */
    private function ensureOrderTrack(Order $order, array $shipment): LogisticsTrack
    {
        $track = $this->findOrderTrack((int) $order->id);
        $shipmentChanged = $this->shipmentChanged($track, $shipment);
        $payload = [
            'business_type' => LogisticsTrack::BUSINESS_ORDER,
            'business_id'   => (int) $order->id,
            'order_id'      => (int) $order->id,
            'provider'      => $shipment['platform'],
            'company_id'    => $shipment['company_id'],
            'company_code'  => $shipment['company_code'],
            'company_name'  => $shipment['company_name'],
            'tracking_no'   => $shipment['tracking_no'],
            'state'         => $shipmentChanged ? 'pending' : (string) ($track?->state ?? 'pending'),
            'status_text'   => $shipmentChanged ? '待查询' : (string) ($track?->status_text ?? '待查询'),
        ];

        if ($shipmentChanged) {
            $payload = array_merge($payload, $this->resetTrackSnapshot());
        }

        if ($track === null) {
            /** @var LogisticsTrack $track */
            $track = $this->model()->create($payload);
            return $track;
        }

        $track->save($payload);
        return $track;
    }

    private function findOrderTrack(int $orderId): ?LogisticsTrack
    {
        /** @var LogisticsTrack|null $track */
        $track = $this->model()
            ->where('business_type', LogisticsTrack::BUSINESS_ORDER)
            ->where('business_id', $orderId)
            ->find();

        return $track;
    }

    /**
     * @param array{platform:string,company_id:int,company_code:string,company_name:string,tracking_no:string} $shipment
     */
    private function shipmentChanged(?LogisticsTrack $track, array $shipment): bool
    {
        if ($track === null) {
            return false;
        }

        return (string) ($track->provider ?? '') !== $shipment['platform']
            || (string) ($track->company_code ?? '') !== $shipment['company_code']
            || (string) ($track->tracking_no ?? '') !== $shipment['tracking_no'];
    }

    /**
     * @return array<string, mixed>
     */
    private function resetTrackSnapshot(): array
    {
        return [
            'is_signed'     => 0,
            'latest_desc'   => '',
            'latest_time'   => null,
            'tracks'        => [],
            'raw_snapshot'  => [],
            'last_query_at' => null,
            'next_query_at' => null,
            'last_error'    => null,
        ];
    }

    private function shouldRefresh(LogisticsTrack $track, LogisticsPlatform $platform): bool
    {
        if ($this->shouldRetryAfterPlatformChange($track, $platform)) {
            return true;
        }

        if ((int) ($track->is_signed ?? 0) === 1) {
            return false;
        }

        $lastError = trim((string) ($track->last_error ?? ''));
        if ($lastError !== '' && str_contains($lastError, '未配置')) {
            return true;
        }

        $nextQueryAt = (string) ($track->next_query_at ?? '');
        return $nextQueryAt === '' || strtotime($nextQueryAt) <= time();
    }

    private function shouldRetryAfterPlatformChange(LogisticsTrack $track, LogisticsPlatform $platform): bool
    {
        $lastQueryAt = strtotime($this->datetimeToString($track->last_query_at ?? null));
        $platformUpdatedAt = strtotime($this->datetimeToString($platform->update_time ?? null));
        if ($lastQueryAt === false || $platformUpdatedAt === false) {
            return false;
        }

        return $platformUpdatedAt > $lastQueryAt;
    }

    /**
     * @param array{platform:string,company_id:int,company_code:string,company_name:string,tracking_no:string} $shipment
     */
    private function refreshTrack(
        LogisticsTrack $track,
        Order $order,
        array $shipment,
        LogisticsPlatform $platform
    ): string {
        $now = date('Y-m-d H:i:s');
        $nextQueryAt = date('Y-m-d H:i:s', time() + $this->cacheMinutes($platform) * 60);

        try {
            /** @var BaseLogisticsDriver $driver */
            $driver = DriverManager::create('logistics', (string) $platform->driver, $this->driverConfig($platform));
            $result = $driver->query(
                $shipment['company_code'],
                $shipment['tracking_no'],
                $this->queryOptions($order)
            );
        } catch (Throwable $e) {
            $track->save([
                'last_query_at' => $now,
                'next_query_at' => $nextQueryAt,
                'last_error'    => mb_substr($e->getMessage(), 0, 255),
            ]);
            return $e->getMessage();
        }

        if (!($result['success'] ?? false)) {
            $message = (string) ($result['message'] ?? '物流平台查询失败');
            $track->save([
                'last_query_at' => $now,
                'next_query_at' => $nextQueryAt,
                'last_error'    => mb_substr($message, 0, 255),
                'raw_snapshot'  => $result['raw'] ?? [],
            ]);
            return $message;
        }

        $track->save([
            'provider'      => $shipment['platform'],
            'company_id'    => $shipment['company_id'],
            'company_code'  => $shipment['company_code'],
            'company_name'  => $shipment['company_name'],
            'tracking_no'   => $shipment['tracking_no'],
            'state'         => (string) ($result['state'] ?? ''),
            'status_text'   => (string) ($result['status'] ?? ''),
            'is_signed'     => (int) (($result['is_signed'] ?? false) ? 1 : 0),
            'latest_desc'   => mb_substr((string) ($result['latest_desc'] ?? ''), 0, 255),
            'latest_time'   => ($result['latest_time'] ?? '') !== '' ? (string) $result['latest_time'] : null,
            'tracks'        => is_array($result['tracks'] ?? null) ? $result['tracks'] : [],
            'raw_snapshot'  => is_array($result['raw'] ?? null) ? $result['raw'] : [],
            'last_query_at' => $now,
            'next_query_at' => $nextQueryAt,
            'last_error'    => null,
        ]);

        return '';
    }

    /**
     * @param array{platform:string,company_id:int,company_code:string,company_name:string,tracking_no:string} $shipment
     * @return array<string, mixed>
     */
    private function formatOrderResponse(
        Order $order,
        array $shipment,
        LogisticsTrack $track,
        string $queryError = ''
    ): array {
        $lastQueryAt = $this->datetimeToString($track->last_query_at ?? null);
        $nextQueryAt = $this->datetimeToString($track->next_query_at ?? null);
        $tracks = is_array($track->tracks ?? null) ? $track->tracks : [];
        $lastError = $queryError !== '' ? $queryError : (string) ($track->last_error ?? '');

        return [
            'available'          => true,
            'delivery_type'      => (string) ($order->delivery_type ?? Order::DELIVERY_TYPE_PHYSICAL),
            'delivery_type_text' => Order::deliveryTypeLabel((string) ($order->delivery_type ?? Order::DELIVERY_TYPE_PHYSICAL)),
            'delivery_note'      => (string) ($order->delivery_note ?? ''),
            'status'             => (string) ($track->status_text ?: '待查询'),
            'state'              => (string) ($track->state ?: 'pending'),
            'platform'           => (string) ($track->provider ?: $shipment['platform']),
            'company'            => (string) ($track->company_name ?: $shipment['company_name']),
            'company_code'       => (string) ($track->company_code ?: $shipment['company_code']),
            'tracking_no'        => (string) ($track->tracking_no ?: $shipment['tracking_no']),
            'receiver'           => $this->receiverOf($order),
            'tracks'             => $tracks,
            'latest_desc'        => (string) ($track->latest_desc ?? ''),
            'latest_time'        => $this->datetimeToString($track->latest_time ?? null),
            'cached'             => $lastQueryAt !== '' && ($nextQueryAt === '' || strtotime($nextQueryAt) > time()),
            'last_query_at'      => $lastQueryAt !== '' ? $lastQueryAt : null,
            'next_query_at'      => $nextQueryAt !== '' ? $nextQueryAt : null,
            'query_error'        => $this->formatQueryError((string) ($track->provider ?: $shipment['platform']), $lastError),
        ];
    }

    private function formatQueryError(string $provider, string $message): string
    {
        return $message;
    }

    /**
     * @return array{name:string, phone_masked:string, address:string}
     */
    private function receiverOf(Order $order): array
    {
        $address = implode('', array_filter([
            (string) ($order->receiver_province ?? ''),
            (string) ($order->receiver_city ?? ''),
            (string) ($order->receiver_district ?? ''),
            (string) ($order->receiver_address ?? ''),
        ], static fn(string $part): bool => $part !== ''));

        return [
            'name'         => (string) ($order->receiver_name ?? ''),
            'phone_masked' => $this->maskPhone((string) ($order->receiver_phone ?? '')),
            'address'      => $address,
        ];
    }

    private function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if (mb_strlen($phone) < 7) {
            return $phone;
        }

        return mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOptions(Order $order): array
    {
        return [
            'phone' => (string) ($order->receiver_phone ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driverConfig(LogisticsPlatform $platform): array
    {
        $config = is_array($platform->config ?? null) ? $platform->config : [];

        return array_merge($config, [
            'driver_type' => 'logistics',
            'driver_name' => (string) $platform->driver,
            'timeout'     => (int) ($config['timeout'] ?? 8),
        ]);
    }

    private function findPlatform(string $platform): ?LogisticsPlatform
    {
        /** @var LogisticsPlatform|null $row */
        $row = $this->model(LogisticsPlatform::class)
            ->where('code', $platform)
            ->find();

        return $row;
    }

    private function defaultPlatformCode(): string
    {
        /** @var LogisticsPlatform|null $platform */
        $platform = $this->model(LogisticsPlatform::class)
            ->where('status', 1)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find();

        return $platform === null ? '' : (string) $platform->code;
    }

    private function cacheMinutes(LogisticsPlatform $platform): int
    {
        return max(1, (int) ($platform->cache_minutes ?? self::DEFAULT_CACHE_MINUTES));
    }

    private function datetimeToString($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
