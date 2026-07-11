<?php

declare(strict_types=1);

namespace Tests\Unit\Logistics;

use app\model\order\Order;
use app\service\logistics\LogisticsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 物流服务静态契约测试。
 *
 * 不连接数据库，锁住本次接入的关键边界：Service 使用驱动体系、缓存节流、收货信息脱敏，
 * 后台发货只写本地快照不发起第三方查询，UniApp 不再用生产兜底数据掩盖接口失败。
 */
final class LogisticsServiceContractTest extends TestCase
{
    public function testServiceUsesDriverManagerAndPlatformSnapshot(): void
    {
        $source = $this->sourceOf(LogisticsService::class);

        $this->assertStringContainsString("DriverManager::create('logistics'", $source);
        $this->assertStringContainsString("private const DEFAULT_PLATFORM = 'kdniao'", $source);
        $this->assertStringContainsString('findPlatform($shipment[\'platform\'])', $source);
        $this->assertStringContainsString('shouldRefresh(LogisticsTrack $track, LogisticsPlatform $platform)', $source);
        $this->assertStringContainsString('shouldRetryAfterPlatformChange', $source);
        $this->assertStringContainsString('shipmentChanged(?LogisticsTrack $track, array $shipment)', $source);
        $this->assertStringContainsString('resetTrackSnapshot', $source);
        $this->assertStringContainsString('cacheMinutes(LogisticsPlatform $platform)', $source);
        $this->assertStringContainsString("where('user_id', \$userId)", $source);
        $this->assertStringContainsString("'phone_masked'", $source);
        $this->assertStringNotContainsString("getSystemSetting('logistics_", $source);
    }

    public function testAdminShipmentOnlySyncsLocalSnapshot(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/admin/order/OrderAdminService.php');

        $this->assertStringContainsString('logistics_platform', $source);
        $this->assertStringContainsString('logistics_company_id', $source);
        $this->assertStringContainsString('logistics_company_code', $source);
        $this->assertStringContainsString('syncOrderShipment($order)', $source);
        $this->assertStringNotContainsString("DriverManager::create('logistics'", $source);
        $this->assertStringNotContainsString('->query(', $source);
    }

    public function testClientRouteAndUniappContractMatch(): void
    {
        $root = dirname(__DIR__, 4);
        $route = (string) file_get_contents($root . '/backend/route/api/client/logistics.php');
        $api = (string) file_get_contents($root . '/frontend/uniapp/api/order/logistics.js');
        $page = (string) file_get_contents($root . '/frontend/uniapp/pages-sub/logistics/detail.vue');

        $this->assertStringContainsString("Route::get('detail/:id', 'detail')", $route);
        $this->assertStringContainsString('/client/api/logistics/detail/${orderId}', $api);
        $this->assertStringContainsString('phone_masked', $page);
        $this->assertStringContainsString('query_error', $page);
        $this->assertStringContainsString('isVirtualDelivery', $page);
        $this->assertStringContainsString('delivery_note', $page);
        $this->assertStringContainsString('mb-empty-state', $page);
        $this->assertStringNotContainsString('MOCK_DATA', $page);
        $this->assertStringNotContainsString('mapInfo', $page);
        $this->assertStringNotContainsString('/pages-sub/logistics/map', $page);
    }

    public function testOrderQueryOptionsOnlyCarryReceiverPhone(): void
    {
        $order = new class([
            'receiver_phone' => '17693447942',
        ]) extends Order {
            protected function getOptions(): array
            {
                return [
                    'pk' => 'id',
                    'schema' => [
                        'id' => 'int',
                        'receiver_phone' => 'string',
                    ],
                ];
            }
        };

        $method = new ReflectionMethod(LogisticsService::class, 'queryOptions');
        $method->setAccessible(true);
        $options = $method->invoke(new LogisticsService(), $order);

        $this->assertSame(['phone' => '17693447942'], $options);
    }

    public function testVirtualOrderLogisticsResponseIsRenderable(): void
    {
        $order = new class([
            'delivery_type' => Order::DELIVERY_TYPE_VIRTUAL,
            'delivery_note' => '兑换码已发送到站内信',
            'receiver_name' => 'Codex Test',
            'receiver_phone' => '13800000000',
            'receiver_province' => '测试省',
            'receiver_city' => '测试市',
            'receiver_district' => '测试区',
            'receiver_address' => '测试地址',
            'shipped_at' => '2026-07-05 10:00:00',
        ]) extends Order {
            protected function getOptions(): array
            {
                return [
                    'pk' => 'id',
                    'schema' => [
                        'delivery_type' => 'string',
                        'delivery_note' => 'string',
                        'receiver_name' => 'string',
                        'receiver_phone' => 'string',
                        'receiver_province' => 'string',
                        'receiver_city' => 'string',
                        'receiver_district' => 'string',
                        'receiver_address' => 'string',
                        'shipped_at' => 'string',
                    ],
                ];
            }
        };

        $method = new ReflectionMethod(LogisticsService::class, 'virtualOrderResponse');
        $method->setAccessible(true);
        $response = $method->invoke(new LogisticsService(), $order);

        $this->assertTrue($response['available']);
        $this->assertSame(Order::DELIVERY_TYPE_VIRTUAL, $response['delivery_type']);
        $this->assertSame('虚拟发货', $response['delivery_type_text']);
        $this->assertSame('虚拟发货', $response['status']);
        $this->assertSame('兑换码已发送到站内信', $response['delivery_note']);
        $this->assertSame('兑换码已发送到站内信', $response['latest_desc']);
        $this->assertSame('virtual', $response['state']);
        $this->assertSame([], $response['tracks']);
        $this->assertSame('', $response['tracking_no']);
        $this->assertSame('138****0000', $response['receiver']['phone_masked']);
    }

    public function testInstallSchemaContainsLogisticsModuleTables(): void
    {
        $root = dirname(__DIR__, 4);
        $schema = (string) file_get_contents($root . '/backend/install/data/schema/14_mb_logistics.sql');
        $settings = (string) file_get_contents($root . '/backend/install/data/schema/03_mb_setting.sql');
        $orderSchema = (string) file_get_contents($root . '/backend/install/data/schema/07_mb_order.sql');
        $route = (string) file_get_contents($root . '/backend/route/api/admin/logistics.php');
        $platformPage = (string) file_get_contents($root . '/frontend/admin/apps/web-antd/src/views/logistics/platform/index.vue');
        $companyPage = (string) file_get_contents($root . '/frontend/admin/apps/web-antd/src/views/logistics/company/index.vue');

        $this->assertStringContainsString('mb_logistics_platform', $schema);
        $this->assertStringContainsString('mb_logistics_company', $schema);
        $this->assertStringNotContainsString('mb_logistics_company_sync_log', $schema);
        $this->assertStringContainsString('mb_logistics_track', $schema);
        $this->assertStringNotContainsString('map_snapshot', $schema);
        $this->assertStringNotContainsString('map_fallback', $schema);
        $this->assertStringContainsString('uk_platform_code', $schema);
        $this->assertStringContainsString('uk_business', $schema);
        $this->assertStringContainsString('kdniao', $schema);
        $this->assertStringContainsString('快递鸟', $schema);
        $this->assertStringNotContainsString('kuaidi100', $schema);
        $this->assertStringNotContainsString('快递100', $schema);
        $this->assertStringNotContainsString('LogisticsConfig', $settings);
        $this->assertStringNotContainsString('logistics_driver', $settings);
        $this->assertStringContainsString('logistics_platform', $orderSchema);
        $this->assertStringContainsString('logistics_company_id', $orderSchema);
        $this->assertStringContainsString('logistics_company_code', $orderSchema);
        $this->assertStringContainsString("'_parent'          => 'SystemLogistics'", $route);
        $this->assertStringNotContainsString("'_parent'          => 'SystemManagement'", $route);
        $this->assertStringContainsString("'_component'       => '/logistics/platform/index'", $route);
        $this->assertStringContainsString("'_component'       => '/logistics/company/index'", $route);
        $this->assertStringNotContainsString('sync-log', $route);
        $this->assertStringContainsString("Route::post('save', 'save')", $route);
        $this->assertStringContainsString("Route::post('clear-cache', 'clearCache')", $route);
        $this->assertStringContainsString("Route::delete('delete/:id', 'delete')", $route);
        $this->assertStringNotContainsString("Route::post('import', 'import')", $route);
        $this->assertStringNotContainsString("Route::post('sync', 'sync')", $route);
        $this->assertStringContainsString('平台名称', $platformPage);
        $this->assertStringContainsString('快递鸟', $platformPage);
        $this->assertStringContainsString('SystemLogisticsPlatformClearCache', $platformPage);
        $this->assertStringContainsString('rowSelection', $platformPage);
        $this->assertStringNotContainsString('快递100', $platformPage);
        $this->assertStringContainsString('物流公司', $companyPage);
        $this->assertStringContainsString('备注', $companyPage);
        $this->assertStringContainsString('remark', $companyPage);
        $this->assertStringContainsString('新增', $companyPage);
        $this->assertStringContainsString('编辑', $companyPage);
        $this->assertStringContainsString('删除', $companyPage);
        $this->assertStringContainsString('<a-switch', $companyPage);
        $this->assertStringNotContainsString('导入目录', $companyPage);
        $this->assertStringNotContainsString('内置初始化', $companyPage);
    }

    public function testAdminShipmentFrontendUsesLogisticsModuleApis(): void
    {
        $root = dirname(__DIR__, 4);
        $shipModal = (string) file_get_contents($root . '/frontend/admin/apps/web-antd/src/views/order/ship-modal.vue');
        $orderApi = (string) file_get_contents($root . '/frontend/admin/apps/web-antd/src/api/order/order.ts');
        $logisticsApi = (string) file_get_contents($root . '/frontend/admin/apps/web-antd/src/api/logistics.ts');

        $this->assertStringContainsString('logistics_platform', $shipModal);
        $this->assertStringContainsString('logistics_company_id', $shipModal);
        $this->assertStringContainsString('/logistics/company/options', $logisticsApi);
        $this->assertStringContainsString('/logistics/platform/list', $logisticsApi);
        $this->assertStringContainsString('/logistics/platform/clear-cache', $logisticsApi);
        $this->assertStringContainsString('/logistics/company/save', $logisticsApi);
        $this->assertStringContainsString('/logistics/company/delete/${id}', $logisticsApi);
        $this->assertStringNotContainsString('/logistics/company/import', $logisticsApi);
        $this->assertStringNotContainsString('/logistics/company/sync', $logisticsApi);
        $this->assertStringNotContainsString('/logistics/sync-log', $logisticsApi);
        $this->assertStringNotContainsString('/order/logisticsCompanies', $orderApi);
    }

    public function testRegionManagementIsUnderSystemMaintenance(): void
    {
        $root = dirname(__DIR__, 4);
        $route = (string) file_get_contents($root . '/backend/route/api/admin/region.php');
        $staticRoute = (string) file_get_contents($root . '/frontend/admin/apps/web-antd/src/router/routes/modules/settings.ts');

        $this->assertStringContainsString("'_parent'          => 'System'", $route);
        $this->assertStringContainsString("'_path'            => '/region'", $route);
        $this->assertStringContainsString("'_component'       => '/region/index'", $route);
        $this->assertStringNotContainsString('/settings/region', $staticRoute);
    }

    /**
     * @param class-string $class
     */
    private function sourceOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents((string) $file);
    }
}
