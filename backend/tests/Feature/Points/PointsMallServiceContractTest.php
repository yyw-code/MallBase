<?php

declare(strict_types=1);

namespace Tests\Feature\Points;

use app\model\marketing\PointsExchangeOrder;
use app\model\marketing\PointsExchangeOrderLog;
use app\model\user\UserPointsLog;
use app\service\admin\marketing\PointsExchangeOrderService;
use app\service\client\points\PointsMallService;
use app\service\SystemSettingService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class PointsMallServiceContractTest extends TestCase
{
    private ?App $app = null;
    private bool $dbReady = false;

    public function testExchangeDeductsPointsAndStockAndIsIdempotent(): void
    {
        $this->requireDbTables([
            'points_goods',
            'points_exchange_order',
            'points_exchange_order_log',
            'user_points',
            'user_points_log',
            'goods',
            'goods_sku',
            'user_address',
            'region',
            'logistics_company',
        ]);

        Db::startTrans();
        try {
            $this->enablePoints();
            $userId = $this->testUserId();
            $goodsId = $this->insertGoods();
            $skuId = $this->insertSku($goodsId, 5);
            $pointsGoodsId = $this->insertPointsGoods($goodsId, $skuId, 10, 3);
            $addressId = $this->insertAddress($userId);
            $this->insertPointsAccount($userId, 100);

            $result = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 2, 'test', 'idem-key-1');

            $this->assertGreaterThan(0, $result['id']);
            $this->assertStringStartsWith('PX', $result['sn']);
            $this->assertSame(80, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(3, (int) Db::name('goods_sku')->where('id', $skuId)->value('stock'));
            $this->assertSame(1, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchange_stock'));
            $this->assertSame(2, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchanged_count'));
            $this->assertSame(20, (int) Db::name('points_exchange_order')->where('id', $result['id'])->value('total_points'));
            $this->assertSame(PointsExchangeOrder::STATUS_PENDING_SHIP, (int) Db::name('points_exchange_order')->where('id', $result['id'])->value('status'));
            $this->assertSame(1, (int) Db::name('user_points_log')
                ->where('biz_type', UserPointsLog::BIZ_POINTS_EXCHANGE)
                ->where('biz_id', $result['sn'])
                ->count());
            $this->assertSame(1, (int) Db::name('points_exchange_order_log')
                ->where('exchange_order_id', $result['id'])
                ->where('action', PointsExchangeOrderLog::ACTION_CREATE)
                ->count());

            $again = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 2, 'test', 'idem-key-1');

            $this->assertSame($result, $again);
            $this->assertSame(1, (int) Db::name('points_exchange_order')->where('user_id', $userId)->count());
            $this->assertSame(80, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(3, (int) Db::name('goods_sku')->where('id', $skuId)->value('stock'));
        } finally {
            Db::rollback();
            $this->flushSettings();
        }
    }

    public function testClosePendingExchangeReturnsPointsAndStock(): void
    {
        $this->requireDbTables([
            'points_goods',
            'points_exchange_order',
            'points_exchange_order_log',
            'user_points',
            'user_points_log',
            'goods',
            'goods_sku',
            'user_address',
            'region',
        ]);

        Db::startTrans();
        try {
            $this->enablePoints();
            $userId = $this->testUserId();
            $goodsId = $this->insertGoods();
            $skuId = $this->insertSku($goodsId, 5);
            $pointsGoodsId = $this->insertPointsGoods($goodsId, $skuId, 10, 3);
            $addressId = $this->insertAddress($userId);
            $this->insertPointsAccount($userId, 100);

            $result = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 1, '', 'idem-key-2');
            $this->exchangeOrderService()->close($result['id'], 'test close', 1);

            $this->assertSame(PointsExchangeOrder::STATUS_CLOSED, (int) Db::name('points_exchange_order')->where('id', $result['id'])->value('status'));
            $this->assertSame(100, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(5, (int) Db::name('goods_sku')->where('id', $skuId)->value('stock'));
            $this->assertSame(3, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchange_stock'));
            $this->assertSame(0, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchanged_count'));
            $this->assertSame(1, (int) Db::name('user_points_log')
                ->where('biz_type', UserPointsLog::BIZ_POINTS_EXCHANGE_RETURN)
                ->where('biz_id', $result['sn'])
                ->count());
            $this->assertSame(1, (int) Db::name('points_exchange_order_log')
                ->where('exchange_order_id', $result['id'])
                ->where('action', PointsExchangeOrderLog::ACTION_ADMIN_CLOSE)
                ->where('operator_type', 2)
                ->where('operator_id', 1)
                ->count());
        } finally {
            Db::rollback();
            $this->flushSettings();
        }
    }

    public function testBuyerCancelPendingExchangeReturnsPointsAndStock(): void
    {
        $this->requireDbTables([
            'points_goods',
            'points_exchange_order',
            'points_exchange_order_log',
            'user_points',
            'user_points_log',
            'goods',
            'goods_sku',
            'user_address',
            'region',
        ]);

        Db::startTrans();
        try {
            $this->enablePoints();
            $userId = $this->testUserId();
            $goodsId = $this->insertGoods();
            $skuId = $this->insertSku($goodsId, 5);
            $pointsGoodsId = $this->insertPointsGoods($goodsId, $skuId, 10, 3);
            $addressId = $this->insertAddress($userId);
            $this->insertPointsAccount($userId, 100);

            $result = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 1, '', 'idem-key-cancel');
            $this->mallService()->cancelOrder($userId, $result['id']);

            $this->assertSame(PointsExchangeOrder::STATUS_CLOSED, (int) Db::name('points_exchange_order')->where('id', $result['id'])->value('status'));
            $this->assertSame('用户取消兑换', (string) Db::name('points_exchange_order')->where('id', $result['id'])->value('admin_remark'));
            $this->assertSame(100, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(5, (int) Db::name('goods_sku')->where('id', $skuId)->value('stock'));
            $this->assertSame(3, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchange_stock'));
            $this->assertSame(0, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchanged_count'));
            $this->assertSame(1, (int) Db::name('user_points_log')
                ->where('biz_type', UserPointsLog::BIZ_POINTS_EXCHANGE_RETURN)
                ->where('biz_id', $result['sn'])
                ->where('operator_type', 1)
                ->where('operator_id', $userId)
                ->count());
            $this->assertSame(1, (int) Db::name('points_exchange_order_log')
                ->where('exchange_order_id', $result['id'])
                ->where('action', PointsExchangeOrderLog::ACTION_BUYER_CANCEL)
                ->where('operator_type', 1)
                ->where('operator_id', $userId)
                ->count());
        } finally {
            Db::rollback();
            $this->flushSettings();
        }
    }

    public function testBuyerCannotCancelShippedExchange(): void
    {
        $this->requireDbTables([
            'points_goods',
            'points_exchange_order',
            'points_exchange_order_log',
            'user_points',
            'user_points_log',
            'goods',
            'goods_sku',
            'user_address',
            'region',
        ]);

        Db::startTrans();
        try {
            $this->enablePoints();
            $userId = $this->testUserId();
            $goodsId = $this->insertGoods();
            $skuId = $this->insertSku($goodsId, 5);
            $pointsGoodsId = $this->insertPointsGoods($goodsId, $skuId, 10, 3);
            $addressId = $this->insertAddress($userId);
            $this->insertPointsAccount($userId, 100);
            $company = $this->insertLogisticsCompany();

            $result = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 1, '', 'idem-key-shipped');
            $this->exchangeOrderService()->ship($result['id'], [
                'delivery_type' => PointsExchangeOrder::DELIVERY_TYPE_PHYSICAL,
                'logistics_platform' => $company['platform'],
                'logistics_company_id' => $company['company_id'],
                'logistics_company_code' => $company['code'],
                'logistics_no' => 'TEST123456',
            ], 1);

            try {
                $this->mallService()->cancelOrder($userId, $result['id']);
                $this->fail('已发货兑换单不应允许用户取消');
            } catch (BusinessException $e) {
                $this->assertSame('只有待发货兑换单可以关闭', $e->getMessage());
            }

            $this->assertSame(PointsExchangeOrder::STATUS_SHIPPED, (int) Db::name('points_exchange_order')->where('id', $result['id'])->value('status'));
            $this->assertSame(90, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(4, (int) Db::name('goods_sku')->where('id', $skuId)->value('stock'));
            $this->assertSame(2, (int) Db::name('points_goods')->where('id', $pointsGoodsId)->value('exchange_stock'));
            $this->assertSame(1, (int) Db::name('points_exchange_order_log')
                ->where('exchange_order_id', $result['id'])
                ->where('action', PointsExchangeOrderLog::ACTION_SHIP)
                ->where('operator_type', 2)
                ->where('operator_id', 1)
                ->count());
        } finally {
            Db::rollback();
            $this->flushSettings();
        }
    }

    public function testCompleteExchangeWritesOperationLog(): void
    {
        $this->requireDbTables([
            'points_goods',
            'points_exchange_order',
            'points_exchange_order_log',
            'user_points',
            'user_points_log',
            'goods',
            'goods_sku',
            'user_address',
            'region',
            'logistics_company',
        ]);

        Db::startTrans();
        try {
            $this->enablePoints();
            $userId = $this->testUserId();
            $goodsId = $this->insertGoods();
            $skuId = $this->insertSku($goodsId, 5);
            $pointsGoodsId = $this->insertPointsGoods($goodsId, $skuId, 10, 3);
            $addressId = $this->insertAddress($userId);
            $this->insertPointsAccount($userId, 100);
            $company = $this->insertLogisticsCompany();

            $result = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 1, '', 'idem-key-complete');
            $this->exchangeOrderService()->ship($result['id'], [
                'delivery_type' => PointsExchangeOrder::DELIVERY_TYPE_PHYSICAL,
                'logistics_platform' => $company['platform'],
                'logistics_company_id' => $company['company_id'],
                'logistics_company_code' => $company['code'],
                'logistics_no' => 'TEST123456',
            ], 1);
            $this->exchangeOrderService()->complete($result['id'], 1);

            $this->assertSame(PointsExchangeOrder::STATUS_COMPLETED, (int) Db::name('points_exchange_order')->where('id', $result['id'])->value('status'));
            $this->assertSame(1, (int) Db::name('points_exchange_order_log')
                ->where('exchange_order_id', $result['id'])
                ->where('action', PointsExchangeOrderLog::ACTION_COMPLETE)
                ->where('operator_type', 2)
                ->where('operator_id', 1)
                ->count());
            $info = $this->exchangeOrderService()->getInfo($result['id']);
            $this->assertGreaterThanOrEqual(3, count($info['logs'] ?? []));
        } finally {
            Db::rollback();
            $this->flushSettings();
        }
    }

    public function testVirtualShipDoesNotRequireLogisticsCompany(): void
    {
        $this->requireDbTables([
            'points_goods',
            'points_exchange_order',
            'points_exchange_order_log',
            'user_points',
            'user_points_log',
            'goods',
            'goods_sku',
            'user_address',
            'region',
        ]);

        Db::startTrans();
        try {
            $this->enablePoints();
            $userId = $this->testUserId();
            $goodsId = $this->insertGoods();
            $skuId = $this->insertSku($goodsId, 5);
            $pointsGoodsId = $this->insertPointsGoods($goodsId, $skuId, 10, 3);
            $addressId = $this->insertAddress($userId);
            $this->insertPointsAccount($userId, 100);

            $result = $this->mallService()->exchange($userId, $pointsGoodsId, $addressId, 1, '', 'idem-key-virtual');
            $this->exchangeOrderService()->ship($result['id'], [
                'delivery_type' => PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL,
                'delivery_note' => '兑换码已发送到站内信',
            ], 1);

            $row = Db::name('points_exchange_order')->where('id', $result['id'])->find();
            $this->assertSame(PointsExchangeOrder::STATUS_SHIPPED, (int) ($row['status'] ?? 0));
            $this->assertSame(PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL, (string) ($row['delivery_type'] ?? ''));
            $this->assertSame('兑换码已发送到站内信', (string) ($row['delivery_note'] ?? ''));
            $this->assertSame('', (string) ($row['logistics_no'] ?? ''));
            $this->assertSame(1, (int) Db::name('points_exchange_order_log')
                ->where('exchange_order_id', $result['id'])
                ->where('action', PointsExchangeOrderLog::ACTION_SHIP)
                ->whereLike('remark', '虚拟发货%')
                ->count());

            $adminInfo = $this->exchangeOrderService()->getInfo($result['id']);
            $this->assertSame(PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL, (string) ($adminInfo['delivery_type'] ?? ''));
            $this->assertSame('虚拟发货', (string) ($adminInfo['delivery_type_text'] ?? ''));
            $this->assertSame('兑换码已发送到站内信', (string) ($adminInfo['delivery_note'] ?? ''));
            $this->assertSame('', (string) ($adminInfo['logistics_company'] ?? ''));
            $this->assertSame('', (string) ($adminInfo['logistics_no'] ?? ''));

            $buyerInfo = $this->mallService()->myOrderDetail($userId, $result['id']);
            $this->assertSame(PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL, (string) ($buyerInfo['delivery_type'] ?? ''));
            $this->assertSame('虚拟发货', (string) ($buyerInfo['delivery_type_text'] ?? ''));
            $this->assertSame('兑换码已发送到站内信', (string) ($buyerInfo['delivery_note'] ?? ''));
            $this->assertSame('', (string) ($buyerInfo['logistics_company'] ?? ''));
            $this->assertSame('', (string) ($buyerInfo['logistics_no'] ?? ''));
        } finally {
            Db::rollback();
            $this->flushSettings();
        }
    }

    private function requireDbTables(array $tables): void
    {
        $this->bootApp();
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}，跳过积分商城契约测试。");
            }
        }
    }

    private function bootApp(): void
    {
        if ($this->dbReady) {
            return;
        }

        try {
            $this->app = new App(dirname(__DIR__, 3));
            $this->app->initialize();
            Db::query('SELECT 1');
            $this->dbReady = true;
        } catch (Throwable $e) {
            $this->markTestSkipped('测试数据库不可用，跳过积分商城契约测试：' . $e->getMessage());
        }
    }

    private function tableExists(string $logicalName): bool
    {
        $table = $this->tableName($logicalName);
        $rows = Db::query(sprintf(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' LIMIT 1",
            str_replace("'", "''", $table)
        ));
        return $rows !== [];
    }

    private function tableName(string $logicalName): string
    {
        return (string) Db::name($logicalName)->getTable();
    }

    private function mallService(): PointsMallService
    {
        return $this->app?->make(PointsMallService::class) ?? app()->make(PointsMallService::class);
    }

    private function exchangeOrderService(): PointsExchangeOrderService
    {
        return $this->app?->make(PointsExchangeOrderService::class) ?? app()->make(PointsExchangeOrderService::class);
    }

    private function enablePoints(): void
    {
        Db::name('setting')->where('code', 'points_enabled')->update(['value' => '1']);
        $this->flushSettings();
    }

    private function flushSettings(): void
    {
        try {
            ($this->app?->make(SystemSettingService::class) ?? app()->make(SystemSettingService::class))->flush();
        } catch (Throwable) {
            // ignore cache cleanup failures in contract tests
        }
    }

    private function testUserId(): int
    {
        return random_int(100000000, 900000000);
    }

    private function insertGoods(): int
    {
        return (int) Db::name('goods')->insertGetId([
            'category_id' => 1,
            'name' => 'CodexTest Points Mall Goods',
            'subtitle' => 'contract test',
            'spec_type' => 1,
            'price' => '20.00',
            'market_price' => '30.00',
            'stock' => 5,
            'is_on_sale' => 1,
            'status' => 1,
            'points_reward_mode' => 'global',
            'member_benefit_mode' => 'global',
        ]);
    }

    private function insertSku(int $goodsId, int $stock): int
    {
        return (int) Db::name('goods_sku')->insertGetId([
            'goods_id' => $goodsId,
            'spec_values' => '默认规格',
            'price' => '20.00',
            'market_price' => '30.00',
            'stock' => $stock,
            'points_reward_mode' => 'inherit',
            'status' => 1,
        ]);
    }

    private function insertPointsGoods(int $goodsId, int $skuId, int $pointsPrice, int $stock): int
    {
        return (int) Db::name('points_goods')->insertGetId([
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'points_price' => $pointsPrice,
            'exchange_stock' => $stock,
            'exchanged_count' => 0,
            'limit_per_user' => 0,
            'sort' => 0,
            'status' => 1,
            'remark' => 'contract test',
        ]);
    }

    private function insertPointsAccount(int $userId, int $points): void
    {
        Db::name('user_points')->insert([
            'user_id' => $userId,
            'balance_points' => $points,
            'frozen_points' => 0,
            'debt_points' => 0,
            'total_income_points' => $points,
            'total_expense_points' => 0,
        ]);
    }

    /**
     * @return array{platform:string,company_id:int,code:string,name:string}
     */
    private function insertLogisticsCompany(): array
    {
        $suffix = (string) random_int(100000, 999999);
        $platform = 'kdniao';
        $code = 'CODEX' . $suffix;
        $name = '测试快递' . $suffix;

        $id = (int) Db::name('logistics_company')->insertGetId([
            'platform' => $platform,
            'code' => $code,
            'name' => $name,
            'status' => 1,
            'sort' => 0,
        ]);

        return [
            'platform' => $platform,
            'company_id' => $id,
            'code' => $code,
            'name' => $name,
        ];
    }

    private function insertAddress(int $userId): int
    {
        $suffix = (string) random_int(100000, 999999);
        $provinceId = $this->insertRegion(0, 'P' . $suffix, '测试省', 1, 'P' . $suffix);
        $cityId = $this->insertRegion($provinceId, 'C' . $suffix, '测试市', 2, 'P' . $suffix . ',C' . $suffix);
        $districtId = $this->insertRegion($cityId, 'D' . $suffix, '测试区', 3, 'P' . $suffix . ',C' . $suffix . ',D' . $suffix);
        $streetId = $this->insertRegion($districtId, 'S' . $suffix, '测试街道', 4, 'P' . $suffix . ',C' . $suffix . ',D' . $suffix . ',S' . $suffix);

        return (int) Db::name('user_address')->insertGetId([
            'user_id' => $userId,
            'receiver_name' => 'Codex Test',
            'receiver_mobile' => '13800000000',
            'province_id' => $provinceId,
            'province_code' => 'P' . $suffix,
            'province_name' => '测试省',
            'city_id' => $cityId,
            'city_code' => 'C' . $suffix,
            'city_name' => '测试市',
            'district_id' => $districtId,
            'district_code' => 'D' . $suffix,
            'district_name' => '测试区',
            'street_id' => $streetId,
            'street_code' => 'S' . $suffix,
            'street_name' => '测试街道',
            'region_path_text' => '测试省 / 测试市 / 测试区 / 测试街道',
            'address_detail' => '测试地址',
            'tag' => 'test',
            'is_default' => 1,
            'region_status' => 1,
            'region_invalid_reason' => null,
        ]);
    }

    private function insertRegion(int $parentId, string $code, string $name, int $level, string $pathCodes): int
    {
        return (int) Db::name('region')->insertGetId([
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'path_codes' => $pathCodes,
            'status' => 1,
            'sort' => 0,
        ]);
    }
}
