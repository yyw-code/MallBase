<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

final class MarketingSchemaContractTest extends TestCase
{
    public function testRemovedOneTimeUpgradeTargetsRemainInCurrentSchema(): void
    {
        $root = dirname(__DIR__, 3) . '/install/data/schema';
        $auth = (string) file_get_contents($root . '/01_mb_auth.sql');
        $user = (string) file_get_contents($root . '/02_mb_user.sql');
        $settings = (string) file_get_contents($root . '/03_mb_setting.sql');
        $search = (string) file_get_contents($root . '/08_mb_search.sql');
        $clientDiy = (string) file_get_contents($root . '/13_mb_client_diy.sql');

        $this->assertStringContainsString('`password_changed_at` datetime DEFAULT NULL', $auth);
        $this->assertStringContainsString('CREATE TABLE `mb_search_log`', $search);
        $this->assertStringContainsString("'client_goods_guarantees'", $settings);
        $this->assertStringContainsString('`username` varchar(60) DEFAULT NULL', $user);
        $this->assertStringContainsString('`wx_miniapp_openid` varchar(100) DEFAULT NULL', $user);
        $this->assertStringContainsString('`wx_official_openid` varchar(100) DEFAULT NULL', $user);
        $this->assertStringContainsString("DEFAULT 'mobile' COMMENT '注册来源（mobile手机/wechat_miniapp微信小程序/wechat_official微信公众号/h5网页）'", $user);
        foreach ([
            'wechat_mini_force_mobile',
            'wechat_mini_force_userinfo',
            'wechat_offi_force_mobile_bind',
            'wechat_offi_force_userinfo',
        ] as $settingCode) {
            $this->assertStringContainsString("'{$settingCode}'", $settings);
        }
        $this->assertStringContainsString("'type', 'serviceMenu'", $clientDiy);
        $this->assertStringNotContainsString("'type', 'customMenu'", $clientDiy);
    }

    public function testClientPageSeedContainsMemberLevelPage(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/install/data/schema/13_mb_client_diy.sql');

        $this->assertStringContainsString("'会员等级', '/pages-sub/member/index'", $schema);
        $this->assertStringContainsString("'pages-sub/member'", $schema);
    }

    public function testClientPageCategorySchemaContainsDefaultCategories(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/install/data/schema/13_mb_client_diy.sql');

        $this->assertStringContainsString('CREATE TABLE `mb_client_page_category`', $schema);
        $this->assertStringNotContainsString('`code` varchar(30) NOT NULL COMMENT \'分类编码\'', $schema);
        $this->assertStringNotContainsString('UNIQUE KEY `uk_code` (`code`)', $schema);
        $this->assertStringContainsString('`category_id` int(11) unsigned NOT NULL DEFAULT 9 COMMENT \'页面分类ID\'', $schema);
        $this->assertStringContainsString('KEY `idx_category_id` (`category_id`)', $schema);
        $this->assertStringNotContainsString('`category` varchar(30) NOT NULL DEFAULT \'other\' COMMENT \'页面分类编码\'', $schema);
        $this->assertStringContainsString("(1, '基础页面'", $schema);
        $this->assertStringContainsString("(9, '其他页面'", $schema);
    }

    public function testPointsExchangeSchemaContainsOrderLogTable(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/install/data/schema/18_mb_points_exchange.sql');

        $this->assertStringContainsString('CREATE TABLE `mb_points_exchange_order_log`', $schema);
        $this->assertStringContainsString('`exchange_order_id` bigint(20) unsigned NOT NULL', $schema);
        $this->assertStringContainsString('KEY `idx_action_time` (`action`, `create_time`)', $schema);
    }

    public function testMemberClientPageIsCompleteInSchemaTruthSource(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/install/data/schema/13_mb_client_diy.sql');

        $this->assertStringContainsString('UNIQUE KEY `uk_path` (`path`)', $schema);
        $this->assertStringContainsString('`delete_time` int(11) unsigned DEFAULT NULL', $schema);
        $this->assertStringContainsString(
            "(40, '会员等级', '/pages-sub/member/index', 'subpackage', 6, 'pages-sub/member', 1, 'system'",
            $schema,
        );
    }

    public function testMarketingTruthSourcesGrantOnlySuperAdminRole(): void
    {
        $root = dirname(__DIR__, 3);
        $settingsSchema = (string) file_get_contents($root . '/install/data/schema/03_mb_setting.sql');
        $settingService = (string) file_get_contents($root . '/app/service/admin/setting/SettingService.php');
        $permissions = (string) file_get_contents($root . '/config/permissions.php');
        $pointsRoute = (string) file_get_contents($root . '/route/api/admin/points.php');
        $memberRoute = (string) file_get_contents($root . '/route/api/admin/member.php');
        $installService = (string) file_get_contents($root . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("'PointsConfig'", $settingsSchema);
        $this->assertStringContainsString("'MemberConfig'", $settingsSchema);
        $this->assertStringContainsString("const PERMISSION_CODE_PREFIX = 'SettingGroup:'", $settingService);
        $this->assertStringContainsString("'code' => 'SystemPointsManagement'", $permissions);
        $this->assertStringContainsString("'code' => 'SystemMemberManagement'", $permissions);
        $this->assertStringContainsString("name('SystemPointsRuleList')", $pointsRoute);
        $this->assertStringContainsString("name('SystemMemberLevelList')", $memberRoute);

        $grantStart = strpos($installService, 'private function seedDefaultRolePermissions');
        $grantEnd = strpos($installService, 'private function writeEnvFile', (int) $grantStart);
        $this->assertIsInt($grantStart);
        $this->assertIsInt($grantEnd);
        $grant = substr($installService, $grantStart, $grantEnd - $grantStart);
        $this->assertStringContainsString("where('code', 'super_admin')", $grant);
        $this->assertStringContainsString('INSERT IGNORE INTO `{$rolePermissionTable}`', $grant);
        $this->assertStringContainsString("`p`.`type` IN (1, 2)", $grant);
        $this->assertStringContainsString("`p`.`status` = 1", $grant);
        $this->assertStringNotContainsString("where('code', 'admin')", $grant);
    }
}
