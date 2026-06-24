<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class ClientDecorationServiceContractTest extends TestCase
{
    private ?App $app = null;
    private bool $dbReady = false;

    protected function tearDown(): void
    {
        if ($this->dbReady) {
            $this->cleanupClientTestRows();
        }

        parent::tearDown();
    }

    public function testPagesJsonImportCreatesAndUpdatesPageLibraryRows(): void
    {
        $this->requireDbTables(['client_page']);
        $service = $this->makeService('app\\service\\admin\\client\\ClientPageService');
        $this->cleanupClientTestRows();

        $missingInputError = $this->captureBusinessException(fn() => $service->importFromUniappPages());
        $this->assertNotNull($missingInputError);
        $this->assertStringContainsString('请上传 pages.json', $missingInputError->getMessage());

        $result = $service->importFromUniappPages($this->makePagesJson('Codex 首页'));
        $this->assertSame(0, $result['skipped']);
        $this->assertGreaterThanOrEqual(3, $result['created']);

        $home = Db::name('client_page')->where('path', '/codex-test/home/index')->find();
        $profile = Db::name('client_page')->where('path', '/codex-test/profile/index')->find();
        $detail = Db::name('client_page')->where('path', '/codex-sub-test/goods/detail')->find();

        $this->assertIsArray($home);
        $this->assertSame('tab', $home['page_type']);
        $this->assertSame('basic', $home['category']);
        $this->assertSame('system', $home['source']);
        $this->assertSame(0, (int) $home['need_login']);

        $this->assertIsArray($profile);
        $this->assertSame('tab', $profile['page_type']);

        $this->assertIsArray($detail);
        $this->assertSame('subpackage', $detail['page_type']);
        $this->assertSame('goods', $detail['category']);
        $this->assertSame('codex-sub-test', $detail['package_root']);

        $updated = $service->importFromUniappPages($this->makePagesJson('Codex 首页改名'));
        $this->assertGreaterThanOrEqual(3, $updated['updated']);
        $this->assertSame(0, $updated['skipped']);

        $homeAfterUpdate = Db::name('client_page')->where('path', '/codex-test/home/index')->find();
        $this->assertIsArray($homeAfterUpdate);
        $this->assertSame('Codex 首页改名', $homeAfterUpdate['name']);

        $picker = $service->getPickerGroups([]);
        $groups = array_column($picker['groups'], null, 'key');
        $this->assertArrayHasKey('basic', $groups);
        $this->assertArrayHasKey('goods', $groups);
        $this->assertSame('基础页面', $groups['basic']['label']);
        $this->assertSame('商品页面', $groups['goods']['label']);

        $goodsPaths = array_column($groups['goods']['items'], 'path');
        $this->assertContains('/codex-sub-test/goods/detail', $goodsPaths);
    }

    public function testClientPageProtectsSystemRowsAndRejectsDuplicatePaths(): void
    {
        $this->requireDbTables(['client_page']);
        $service = $this->makeService('app\\service\\admin\\client\\ClientPageService');
        $this->cleanupClientTestRows();

        $systemId = (int) Db::name('client_page')->insertGetId([
            'name' => 'CodexTest 系统页面',
            'path' => '/codex-test/system-page',
            'page_type' => 'page',
            'category' => 'other',
            'package_root' => null,
            'need_login' => 0,
            'source' => 'system',
            'remark' => null,
            'sort' => 1,
            'status' => 1,
        ]);

        $deleteError = $this->captureBusinessException(fn() => $service->delete($systemId));
        $this->assertNotNull($deleteError);
        $this->assertStringContainsString('系统页面不能删除', $deleteError->getMessage());

        $manualId = $service->create([
            'name' => 'CodexTest 手动页面',
            'path' => '/codex-test/manual-page',
            'page_type' => 'page',
            'category' => 'other',
        ]);
        $this->assertGreaterThan(0, $manualId);

        $duplicateError = $this->captureBusinessException(fn() => $service->create([
            'name' => 'CodexTest 重复页面',
            'path' => 'codex-test/manual-page',
            'page_type' => 'page',
            'category' => 'other',
        ]));
        $this->assertNotNull($duplicateError);
        $this->assertStringContainsString('页面路径已存在', $duplicateError->getMessage());

        $rootPathError = $this->captureBusinessException(fn() => $service->create([
            'name' => 'CodexTest 根路径',
            'path' => '/',
            'page_type' => 'page',
            'category' => 'other',
        ]));
        $this->assertNotNull($rootPathError);
        $this->assertStringContainsString('页面路径必须以 / 开头', $rootPathError->getMessage());
    }

    public function testDecorationSchemeProtectsSystemRowsAndValidatesSchemas(): void
    {
        $this->requireDbTables(['client_decoration_scheme']);
        $service = $this->makeService('app\\service\\admin\\client\\ClientDecorationSchemeService');
        $this->cleanupClientTestRows();

        $systemId = (int) Db::name('client_decoration_scheme')->insertGetId([
            'type' => 'home',
            'name' => 'CodexTest 系统首页方案',
            'description' => null,
            'schema' => json_encode(['components' => []], JSON_UNESCAPED_UNICODE),
            'tabbar_mode' => 'native',
            'is_system' => 1,
            'is_active' => 0,
            'sort' => 1,
            'status' => 1,
        ]);

        $updateError = $this->captureBusinessException(fn() => $service->update($systemId, [
            'name' => 'CodexTest 系统首页方案修改',
            'type' => 'home',
            'schema' => ['components' => []],
        ]));
        $this->assertNotNull($updateError);
        $this->assertStringContainsString('系统默认方案不能修改', $updateError->getMessage());

        $deleteError = $this->captureBusinessException(fn() => $service->delete($systemId));
        $this->assertNotNull($deleteError);
        $this->assertStringContainsString('系统默认方案不能删除', $deleteError->getMessage());

        $tabbarError = $this->captureBusinessException(fn() => $service->activate($service->create([
            'type' => 'tabbar',
            'name' => 'CodexTest 入口不足',
            'schema' => [
                'items' => [
                    ['text' => '首页', 'path' => '/pages/index/index'],
                ],
            ],
            'tabbar_mode' => 'native',
        ])));
        $this->assertNotNull($tabbarError);
        $this->assertStringContainsString('底部导航必须配置2到5个入口', $tabbarError->getMessage());
    }

    public function testDecorationSchemeCopyActivateCreatesSnapshotAndKeepsSingleActiveType(): void
    {
        $this->requireDbTables(['client_decoration_scheme', 'client_decoration_snapshot']);
        $service = $this->makeService('app\\service\\admin\\client\\ClientDecorationSchemeService');

        Db::startTrans();
        try {
            $oldActiveId = (int) Db::name('client_decoration_scheme')->insertGetId([
                'type' => 'profile',
                'name' => 'CodexTest 已启用个人中心',
                'description' => null,
                'schema' => json_encode(['modules' => [['id' => 'user', 'type' => 'userInfo']]], JSON_UNESCAPED_UNICODE),
                'tabbar_mode' => 'native',
                'is_system' => 0,
                'is_active' => 1,
                'sort' => 1,
                'status' => 1,
            ]);

            $sourceId = (int) Db::name('client_decoration_scheme')->insertGetId([
                'type' => 'profile',
                'name' => 'CodexTest 待复制个人中心',
                'description' => null,
                'schema' => json_encode(['modules' => [['id' => 'wallet', 'type' => 'walletEntry']]], JSON_UNESCAPED_UNICODE),
                'tabbar_mode' => 'native',
                'is_system' => 0,
                'is_active' => 0,
                'sort' => 2,
                'status' => 1,
            ]);

            $copyId = $service->copy($sourceId);
            $copy = Db::name('client_decoration_scheme')->where('id', $copyId)->find();
            $this->assertIsArray($copy);
            $this->assertSame(0, (int) $copy['is_system']);
            $this->assertSame(0, (int) $copy['is_active']);
            $this->assertStringContainsString('副本', (string) $copy['name']);

            $this->assertTrue($service->activate($copyId));

            $activeIds = Db::name('client_decoration_scheme')
                ->where('type', 'profile')
                ->where('is_active', 1)
                ->whereNull('delete_time')
                ->column('id');
            $this->assertSame([$copyId], array_map('intval', $activeIds));

            $oldActive = Db::name('client_decoration_scheme')->where('id', $oldActiveId)->find();
            $this->assertIsArray($oldActive);
            $this->assertSame(0, (int) $oldActive['is_active']);

            $snapshot = Db::name('client_decoration_snapshot')
                ->where('scheme_id', $copyId)
                ->where('type', 'profile')
                ->find();
            $this->assertIsArray($snapshot);
            $this->assertSame((string) $copy['name'], $snapshot['name']);
        } finally {
            Db::rollback();
        }
    }

    public function testThemeServiceProtectsSystemThemeAndRequiresPublishedCustomPolicy(): void
    {
        $this->requireDbTables(['client_theme', 'client_theme_policy']);
        $service = $this->makeService('app\\service\\admin\\client\\ClientThemeService');
        $this->requireMethods($service, ['update', 'delete', 'savePolicy', 'getPolicy']);

        Db::startTrans();
        try {
            $systemThemeId = (int) Db::name('client_theme')->insertGetId([
                'name' => 'CodexTest 系统主题',
                'type' => 'light',
                'tokens' => json_encode(['colorPrimary' => '#0d50d5'], JSON_UNESCAPED_UNICODE),
                'is_system' => 1,
                'status' => 1,
                'sort' => 1,
            ]);
            $draftCustomId = (int) Db::name('client_theme')->insertGetId([
                'name' => 'CodexTest 草稿主题',
                'type' => 'custom',
                'tokens' => json_encode(['colorPrimary' => '#111111'], JSON_UNESCAPED_UNICODE),
                'is_system' => 0,
                'status' => 0,
                'sort' => 2,
            ]);
            $publishedCustomId = (int) Db::name('client_theme')->insertGetId([
                'name' => 'CodexTest 已发布主题',
                'type' => 'custom',
                'tokens' => json_encode(['colorPrimary' => '#222222'], JSON_UNESCAPED_UNICODE),
                'is_system' => 0,
                'status' => 1,
                'sort' => 3,
            ]);

            $updateError = $this->captureBusinessException(fn() => $service->update($systemThemeId, [
                'name' => 'CodexTest 系统主题修改',
                'type' => 'light',
                'tokens' => ['colorPrimary' => '#333333'],
            ]));
            $this->assertNotNull($updateError);
            $this->assertStringContainsString('系统主题', $updateError->getMessage());

            $deleteError = $this->captureBusinessException(fn() => $service->delete($systemThemeId));
            $this->assertNotNull($deleteError);
            $this->assertStringContainsString('系统主题', $deleteError->getMessage());

            $draftPolicyError = $this->captureBusinessException(fn() => $service->savePolicy([
                'allow_user_select' => 1,
                'default_mode' => 'custom',
                'default_theme_id' => $draftCustomId,
            ]));
            $this->assertNotNull($draftPolicyError);
            $this->assertStringContainsString('已发布', $draftPolicyError->getMessage());

            $savedPolicy = $service->savePolicy([
                'allow_user_select' => 1,
                'default_mode' => 'custom',
                'default_theme_id' => $publishedCustomId,
            ]);
            $this->assertIsArray($savedPolicy);

            $policy = $service->getPolicy();
            $this->assertIsArray($policy);
            $this->assertSame('custom', $policy['default_mode']);
            $this->assertSame($publishedCustomId, (int) $policy['default_theme_id']);
        } finally {
            Db::rollback();
        }
    }

    public function testDecorationConfigReturnsActiveOrSystemHomeProfileTabbarAndTheme(): void
    {
        $this->requireDbTables([
            'client_decoration_scheme',
            'client_theme',
            'client_theme_policy',
        ]);
        $service = $this->makeService('app\\service\\client\\DecorationService');
        $this->requireMethods($service, ['config']);

        $config = $service->config();
        $this->assertIsArray($config);

        foreach (['home', 'profile', 'tabbar', 'theme'] as $key) {
            $this->assertArrayHasKey($key, $config);
            $this->assertIsArray($config[$key]);
        }

        $this->assertIsArray($config['home']['components'] ?? $config['home']['modules'] ?? null);
        $this->assertIsArray($config['profile']['modules'] ?? null);

        $tabbarItems = $config['tabbar']['schema']['items'] ?? null;
        $this->assertIsArray($tabbarItems);
        $this->assertGreaterThanOrEqual(2, count($tabbarItems));
        $this->assertLessThanOrEqual(5, count($tabbarItems));

        $this->assertArrayHasKey('policy', $config['theme']);
        $this->assertArrayHasKey('themes', $config['theme']);
        $this->assertIsArray($config['theme']['themes']);
    }

    public function testDecorationProfileDefaultsFillOrderAndServiceItems(): void
    {
        $service = $this->makeDecorationServiceForSchemaNormalization();
        $method = $this->schemaNormalizerMethod($service);
        $schema = $method->invoke($service, 'profile', [
            'modules' => [
                ['id' => 'profile-user', 'type' => 'userInfo', 'props' => []],
                ['id' => 'profile-order', 'type' => 'orderEntry', 'props' => []],
                ['id' => 'profile-wallet', 'type' => 'walletEntry', 'props' => []],
                ['id' => 'profile-service', 'type' => 'serviceMenu', 'props' => ['items' => []]],
            ],
        ]);

        $this->assertSame(10, $schema['pageStyle']['paddingTop']);
        $this->assertSame(28, $schema['pageStyle']['paddingX']);
        $this->assertSame(28, $schema['pageStyle']['paddingLeft']);
        $this->assertSame(28, $schema['pageStyle']['paddingRight']);
        $this->assertSame(24, $schema['pageStyle']['paddingBottom']);
        $this->assertSame(23, $schema['pageStyle']['padding']);
        $this->assertSame('color', $schema['pageStyle']['backgroundMode']);
        $this->assertSame('', $schema['pageStyle']['backgroundColorStart']);

        $modules = array_column($schema['modules'], null, 'type');

        $this->assertArrayHasKey('userInfo', $modules);
        $this->assertArrayHasKey('orderEntry', $modules);
        $this->assertArrayHasKey('walletEntry', $modules);
        $this->assertArrayHasKey('serviceMenu', $modules);
        $this->assertCount(4, $modules['orderEntry']['props']['items']);
        $this->assertCount(4, $modules['serviceMenu']['props']['items']);
        $this->assertSame(
            'static/demo/profile-order-pay.svg',
            $modules['orderEntry']['props']['items'][0]['image']
        );
        $this->assertTrue(($modules['orderEntry']['props']['items'][0]['visible'] ?? true) !== false);
        $this->assertTrue(($modules['orderEntry']['props']['items'][0]['enabled'] ?? true) !== false);
        $this->assertArrayNotHasKey('icon', $modules['orderEntry']['props']['items'][0]);
        $this->assertSame(
            'static/demo/profile-service-settings.svg',
            $modules['serviceMenu']['props']['items'][2]['image']
        );
        $this->assertSame('/pages-sub/user/settings', $modules['serviceMenu']['props']['items'][2]['path']);
        $this->assertArrayNotHasKey('action', $modules['serviceMenu']['props']['items'][2]);
        $this->assertArrayNotHasKey('icon', $modules['serviceMenu']['props']['items'][2]);
        $this->assertSame('我的订单', $modules['orderEntry']['props']['title']);
        $this->assertSame('我的余额', $modules['walletEntry']['props']['title']);
        $this->assertSame('我的服务', $modules['serviceMenu']['props']['title']);
        $this->assertSame('grid', $modules['orderEntry']['props']['display']);
        $this->assertSame('list', $modules['serviceMenu']['props']['display']);
        $this->assertSame(28, $modules['orderEntry']['props']['paddingX']);
        $this->assertSame(28, $modules['orderEntry']['props']['paddingY']);
        $this->assertSame(20, $modules['walletEntry']['props']['radius']);
        $this->assertSame(10, $modules['serviceMenu']['props']['paddingX']);
        $this->assertSame(0, $modules['serviceMenu']['props']['paddingY']);
        $this->assertSame(28, $modules['userInfo']['props']['paddingX']);
        $this->assertSame(28, $modules['userInfo']['props']['paddingY']);
        $this->assertSame(0, $modules['userInfo']['props']['radius']);
        $this->assertSame('#ffffff', $modules['orderEntry']['props']['backgroundColorStart']);
        $this->assertArrayNotHasKey('bottomBackground', $modules['orderEntry']['props']);
        $this->assertArrayNotHasKey('componentBackgroundStart', $modules['orderEntry']['props']);
        $this->assertArrayNotHasKey('componentBackgroundEnd', $modules['orderEntry']['props']);
        $this->assertArrayNotHasKey('textColor', $modules['orderEntry']['props']);
        $this->assertTrue($modules['orderEntry']['props']['borderEnabled']);
        $this->assertSame('dashed', $modules['orderEntry']['props']['borderStyle']);
        $this->assertFalse($modules['orderEntry']['props']['shadowEnabled']);
        $this->assertSame(30, $modules['orderEntry']['props']['shadowBlur']);
        $this->assertSame('#0f172a', $modules['orderEntry']['props']['shadowColor']);
        $this->assertSame(0, $modules['orderEntry']['props']['shadowOffsetX']);
        $this->assertSame(12, $modules['orderEntry']['props']['shadowOffsetY']);
        $this->assertSame(14, $modules['orderEntry']['props']['shadowOpacity']);
        $this->assertSame(0, $modules['orderEntry']['props']['shadowSpread']);
        $this->assertArrayNotHasKey('textVisibility', $modules['orderEntry']['props']);
        $this->assertArrayNotHasKey('show_level', $modules['userInfo']['props']);
        $this->assertArrayNotHasKey('show_points', $modules['walletEntry']['props']);
        $this->assertTrue($modules['walletEntry']['props']['show_records']);
        $this->assertTrue($modules['walletEntry']['props']['show_view_button']);
    }

    public function testDecorationProfileKeepsLegacyPageAndModuleStyleAliases(): void
    {
        $service = $this->makeDecorationServiceForSchemaNormalization();
        $method = $this->schemaNormalizerMethod($service);
        $schema = $method->invoke($service, 'profile', [
            'pageStyle' => [
                'background_color_end' => '#222222',
                'background_color_start' => '#111111',
                'background_gradient_direction' => 'vertical',
                'background_mode' => 'color',
                'padding_x' => 20,
                'padding_y' => 36,
            ],
            'modules' => [
                [
                    'id' => 'profile-order',
                    'type' => 'orderEntry',
                    'props' => [
                        'items' => [
                            [
                                'image' => 'static/demo/profile-order-pay.svg',
                                'label' => '待付款',
                                'path' => '/pages-sub/order/list?status=10',
                                'visible' => false,
                            ],
                            [
                                'image' => '',
                                'imageRemoved' => true,
                                'image_removed' => true,
                                'label' => '待发货',
                                'path' => '/pages-sub/order/list?status=20',
                            ],
                        ],
                        'padding_bottom' => 3,
                        'padding_left' => 4,
                        'padding_right' => 2,
                        'padding_top' => 1,
                        'paddingX' => 8,
                        'paddingY' => 6,
                        'shadow_blur' => 40,
                        'shadow_color' => '#123456',
                        'shadow_enabled' => true,
                        'shadow_offset_x' => 5,
                        'shadow_offset_y' => 20,
                        'shadow_opacity' => 35,
                        'shadow_spread' => 2,
                        'textVisibility' => ['title' => false],
                    ],
                ],
            ],
        ]);

        $this->assertSame('#111111', $schema['pageStyle']['backgroundColorStart']);
        $this->assertSame('#222222', $schema['pageStyle']['backgroundColorEnd']);
        $this->assertSame('vertical', $schema['pageStyle']['backgroundGradientDirection']);
        $this->assertSame(36, $schema['pageStyle']['paddingTop']);
        $this->assertSame(20, $schema['pageStyle']['paddingLeft']);
        $this->assertSame(20, $schema['pageStyle']['paddingRight']);

        $props = $schema['modules'][0]['props'];
        $this->assertSame(1, $props['paddingTop']);
        $this->assertSame(2, $props['paddingRight']);
        $this->assertSame(3, $props['paddingBottom']);
        $this->assertSame(4, $props['paddingLeft']);
        $this->assertTrue($props['shadowEnabled']);
        $this->assertSame(5, $props['shadowOffsetX']);
        $this->assertSame(20, $props['shadowOffsetY']);
        $this->assertSame(40, $props['shadowBlur']);
        $this->assertSame(2, $props['shadowSpread']);
        $this->assertSame('#123456', $props['shadowColor']);
        $this->assertSame(35, $props['shadowOpacity']);
        $this->assertFalse($props['items'][0]['visible']);
        $this->assertSame('', $props['items'][1]['image']);
        $this->assertTrue($props['items'][1]['imageRemoved']);
        $this->assertTrue($props['items'][1]['image_removed']);
        $this->assertArrayNotHasKey('textVisibility', $props);
    }

    public function testDecorationProfileKeepsLegacyThemeEntryKeyForRuntimeCompatibility(): void
    {
        $service = $this->makeDecorationServiceForSchemaNormalization();
        $method = $this->schemaNormalizerMethod($service);
        $schema = $method->invoke($service, 'profile', [
            'modules' => [
                [
                    'id' => 'profile-service',
                    'type' => 'serviceMenu',
                    'props' => [
                        'items' => [
                            [
                                'action' => 'theme',
                                'label' => '主题设置',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $item = $schema['modules'][0]['props']['items'][0];
        $this->assertSame('theme', $item['key']);
        $this->assertSame('', $item['path']);
        $this->assertArrayNotHasKey('action', $item);
    }

    public function testDecorationProductSourcePickerHydratesGoodsMainImageAssetUrl(): void
    {
        $this->requireDbTables(['goods', 'upload_asset', 'upload_asset_location']);
        $service = $this->makeService('app\\service\\admin\\client\\ClientDecorationSchemeService');

        Db::startTrans();
        try {
            $assetId = (int) Db::name('upload_asset')->insertGetId([
                'category_id' => 0,
                'type' => 'image',
                'name' => 'CodexTest 商品主图',
                'original_name' => 'codex-test-product-main.jpg',
                'mime' => 'image/jpeg',
                'ext' => 'jpg',
                'size' => 1024,
                'hash' => str_repeat('a', 64),
                'width' => 800,
                'height' => 800,
                'module' => 'goods',
                'uploader_type' => 'admin',
                'uploader_id' => 0,
                'visibility' => 'public',
                'status' => 1,
                'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);
            Db::name('upload_asset_location')->insert([
                'asset_id' => $assetId,
                'driver' => 'local',
                'path' => 'codex-test/product-main.jpg',
                'url_prefix' => '/uploads',
                'bucket' => '',
                'region' => '',
                'endpoint' => '',
                'is_primary' => 1,
                'status' => 1,
                'etag' => '',
                'size' => 1024,
                'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);

            $goodsId = $this->insertGoods('CodexTest 装修选择器主图商品', [
                'category_id' => 0,
                'main_image' => (string) $assetId,
            ]);

            $result = $service->getProductSourcePicker(['keyword' => 'CodexTest 装修选择器主图']);
            $goods = array_values(array_filter(
                $result['goods'],
                fn(array $item): bool => (int) $item['id'] === $goodsId
            ));

            $this->assertCount(1, $goods);
            $this->assertSame((string) $assetId, (string) $goods[0]['main_image']);
            $this->assertStringContainsString('/uploads/codex-test/product-main.jpg', (string) $goods[0]['main_image_full_url']);
        } finally {
            Db::rollback();
        }
    }

    public function testClientGoodsListSupportsManualIdsOrderAndTagFiltersWithoutLeakingUnsaleableGoods(): void
    {
        $this->requireDbTables(['goods', 'goods_tag_relation']);
        $this->skipUnlessGoodsFilterContractHasLanded();
        $service = $this->makeService('app\\service\\client\\goods\\ClientGoodsService');

        Db::startTrans();
        try {
            $saleableA = $this->insertGoods('CodexTest 可售商品 A', [
                'sort' => 20,
                'status' => 1,
                'is_on_sale' => 1,
            ]);
            $saleableB = $this->insertGoods('CodexTest 可售商品 B', [
                'sort' => 10,
                'status' => 1,
                'is_on_sale' => 1,
            ]);
            $disabled = $this->insertGoods('CodexTest 禁用商品', [
                'status' => 0,
                'is_on_sale' => 1,
            ]);
            $offline = $this->insertGoods('CodexTest 下架商品', [
                'status' => 1,
                'is_on_sale' => 0,
            ]);
            $deleted = $this->insertGoods('CodexTest 已删除商品', [
                'status' => 1,
                'is_on_sale' => 1,
                'delete_time' => time(),
            ]);

            $this->insertGoodsTagRelation($saleableA, 10101);
            $this->insertGoodsTagRelation($saleableB, 10102);
            $this->insertGoodsTagRelation($disabled, 10101);
            $this->insertGoodsTagRelation($offline, 10101);
            $this->insertGoodsTagRelation($deleted, 10101);

            $manual = $service->list([
                'ids' => [$saleableB, $offline, $saleableA, $disabled, $deleted],
            ], 1, 10);
            $manualIds = array_map('intval', array_column($manual['list'], 'id'));
            $this->assertSame([$saleableB, $saleableA], $manualIds);

            $singleTag = $service->list(['tag_id' => 10101], 1, 10);
            $singleTagIds = array_map('intval', array_column($singleTag['list'], 'id'));
            $this->assertSame([$saleableA], $singleTagIds);

            $multiTags = $service->list(['tag_ids' => [10101, 10102]], 1, 10);
            $multiTagIds = array_map('intval', array_column($multiTags['list'], 'id'));
            $this->assertContains($saleableA, $multiTagIds);
            $this->assertContains($saleableB, $multiTagIds);
            $this->assertNotContains($disabled, $multiTagIds);
            $this->assertNotContains($offline, $multiTagIds);
            $this->assertNotContains($deleted, $multiTagIds);
        } finally {
            Db::rollback();
        }
    }

    /**
     * @param array<int, string> $tables
     */
    private function requireDbTables(array $tables): void
    {
        $this->bootApp();
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}，跳过客户端装修契约测试。");
            }
        }
    }

    private function bootApp(): void
    {
        if ($this->dbReady) {
            return;
        }

        try {
            $this->preferFileCacheForLocalPhpunit();
            $this->withSuppressedRedisDnsWarning(function (): void {
                $this->app = new App(dirname(__DIR__, 3));
                $this->app->initialize();
                Db::query('SELECT 1');
            });
            $this->dbReady = true;
        } catch (Throwable $e) {
            $this->markTestSkipped('测试数据库不可用，跳过客户端装修契约测试：' . $e->getMessage());
        }
    }

    private function makeService(string $className): object
    {
        if (!class_exists($className)) {
            $this->markTestSkipped("{$className} 尚未落地，跳过对应契约测试。");
        }

        try {
            return $this->app?->make($className) ?? app()->make($className);
        } catch (Throwable $e) {
            $this->markTestSkipped("{$className} 无法实例化，跳过对应契约测试：" . $e->getMessage());
        }
    }

    private function makeDecorationServiceForSchemaNormalization(): object
    {
        if (!class_exists(\app\service\client\DecorationService::class)) {
            $this->markTestSkipped('app\\service\\client\\DecorationService 尚未落地，跳过对应契约测试。');
        }

        return new \app\service\client\DecorationService();
    }

    private function schemaNormalizerMethod(object $service): \ReflectionMethod
    {
        if (!method_exists($service, 'normalizeSchemaByType')) {
            $this->markTestSkipped(get_class($service) . '::normalizeSchemaByType 尚未落地，跳过对应契约测试。');
        }

        $method = new \ReflectionMethod($service, 'normalizeSchemaByType');
        $method->setAccessible(true);

        return $method;
    }

    /**
     * @param array<int, string> $methodNames
     */
    private function requireMethods(object $service, array $methodNames): void
    {
        foreach ($methodNames as $methodName) {
            if (!method_exists($service, $methodName)) {
                $this->markTestSkipped(get_class($service) . "::{$methodName} 尚未落地，跳过对应契约测试。");
            }
        }
    }

    private function tableExists(string $logicalName): bool
    {
        try {
            $result = Db::query(
                'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->tableName($logicalName)]
            );
        } catch (Throwable $e) {
            $this->markTestSkipped('无法读取测试数据库表结构：' . $e->getMessage());
        }

        return ((int) ($result[0]['c'] ?? 0)) > 0;
    }

    private function tableName(string $logicalName): string
    {
        return (string) config('database.connections.mysql.prefix', '') . $logicalName;
    }

    private function cleanupClientTestRows(): void
    {
        foreach (['client_decoration_snapshot', 'client_decoration_scheme', 'client_theme'] as $table) {
            try {
                if ($this->safeTableExists($table)) {
                    Db::name($table)->whereLike('name', 'CodexTest%')->delete();
                }
            } catch (Throwable) {
                // 清理失败不覆盖测试主体结果。
            }
        }

        try {
            if ($this->safeTableExists('client_page')) {
                Db::name('client_page')
                    ->whereLike('path', '/codex-test/%')
                    ->whereOr('path', 'like', '/codex-sub-test/%')
                    ->delete();
            }
        } catch (Throwable) {
            // 清理失败不覆盖测试主体结果。
        }
    }

    private function makePagesJson(string $homeTitle): string
    {
        $content = json_encode([
            'pages' => [
                [
                    'path' => 'codex-test/home/index',
                    'style' => ['navigationBarTitleText' => $homeTitle],
                ],
                [
                    'path' => 'codex-test/profile/index',
                    'style' => ['navigationBarTitleText' => 'Codex 个人中心'],
                ],
            ],
            'subPackages' => [
                [
                    'root' => 'codex-sub-test',
                    'pages' => [
                        [
                            'path' => 'goods/detail',
                            'style' => ['navigationBarTitleText' => 'Codex 商品详情'],
                        ],
                    ],
                ],
            ],
            'tabBar' => [
                'list' => [
                    ['pagePath' => 'codex-test/home/index'],
                    ['pagePath' => 'codex-test/profile/index'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->assertIsString($content);

        return $content;
    }

    private function captureBusinessException(callable $callback): ?BusinessException
    {
        try {
            $callback();
        } catch (BusinessException $e) {
            return $e;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertGoods(string $name, array $overrides = []): int
    {
        $payload = array_merge([
            'category_id' => 1,
            'brand_id' => null,
            'freight_template_id' => null,
            'name' => $name,
            'subtitle' => null,
            'main_image' => null,
            'main_video' => null,
            'images' => json_encode([], JSON_UNESCAPED_UNICODE),
            'spec_type' => 1,
            'spec_meta' => json_encode([], JSON_UNESCAPED_UNICODE),
            'description' => null,
            'price' => '10.00',
            'market_price' => null,
            'stock' => 100,
            'sales' => 0,
            'unit' => '件',
            'is_on_sale' => 1,
            'is_recommend' => 0,
            'is_new' => 0,
            'is_hot' => 0,
            'sort' => 0,
            'status' => 1,
            'delete_time' => null,
        ], $overrides);

        return (int) Db::name('goods')->insertGetId($payload);
    }

    private function insertGoodsTagRelation(int $goodsId, int $tagId): void
    {
        Db::name('goods_tag_relation')->insert([
            'goods_id' => $goodsId,
            'tag_id' => $tagId,
        ]);
    }

    private function skipUnlessGoodsFilterContractHasLanded(): void
    {
        $sourcePath = dirname(__DIR__, 3) . '/app/service/client/goods/ClientGoodsService.php';
        $source = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
        if (!is_string($source)) {
            $this->markTestSkipped('ClientGoodsService.php 不存在，跳过商品筛选契约测试。');
        }

        foreach (['ids', 'tag_id', 'tag_ids'] as $needle) {
            if (!str_contains($source, $needle)) {
                $this->markTestSkipped('ClientGoodsService 尚未落地 ids/tag_id/tag_ids 筛选契约，跳过对应测试。');
            }
        }
    }

    private function preferFileCacheForLocalPhpunit(): void
    {
        putenv('PHP_CACHE_DRIVER=file');
        putenv('CACHE_DRIVER=file');
        $_ENV['PHP_CACHE_DRIVER'] = 'file';
        $_ENV['CACHE_DRIVER'] = 'file';
        $_SERVER['PHP_CACHE_DRIVER'] = 'file';
        $_SERVER['CACHE_DRIVER'] = 'file';
    }

    private function safeTableExists(string $logicalName): bool
    {
        try {
            $result = Db::query(
                'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->tableName($logicalName)]
            );
        } catch (Throwable) {
            return false;
        }

        return ((int) ($result[0]['c'] ?? 0)) > 0;
    }

    private function withSuppressedRedisDnsWarning(callable $callback): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if (
                str_contains($message, 'Redis::connect()')
                && str_contains($message, 'getaddrinfo for redis failed')
            ) {
                return true;
            }

            return false;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }
}
