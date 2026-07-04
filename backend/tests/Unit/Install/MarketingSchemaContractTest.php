<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

final class MarketingSchemaContractTest extends TestCase
{
    public function testClientPageSeedContainsMemberLevelPage(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/install/data/schema/13_mb_client_diy.sql');

        $this->assertStringContainsString("'会员等级', '/pages-sub/member/index'", $schema);
        $this->assertStringContainsString("'pages-sub/member'", $schema);
    }

    public function testPointsExchangeUpgradeContainsOrderLogTable(): void
    {
        $path = dirname(__DIR__, 3) . '/install/data/upgrade/2026_07_01_points_exchange.sql';
        if (!is_file($path)) {
            $this->markTestSkipped('本地升级 SQL 未生成，跳过 git-ignored 运维产物校验。');
        }
        $upgrade = (string) file_get_contents($path);

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `mb_points_exchange_order_log`', $upgrade);
        $this->assertStringContainsString('`exchange_order_id` bigint(20) unsigned NOT NULL', $upgrade);
        $this->assertStringContainsString('KEY `idx_action_time` (`action`, `create_time`)', $upgrade);
    }

    public function testMemberClientPageUpgradeIsIdempotent(): void
    {
        $path = dirname(__DIR__, 3) . '/install/data/upgrade/2026_07_05_member_client_page.sql';
        if (!is_file($path)) {
            $this->markTestSkipped('本地升级 SQL 未生成，跳过 git-ignored 运维产物校验。');
        }
        $upgrade = (string) file_get_contents($path);

        $this->assertStringContainsString("'会员等级', '/pages-sub/member/index'", $upgrade);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $upgrade);
        $this->assertStringContainsString('`delete_time` = NULL', $upgrade);
    }

    public function testMarketingRoleUpgradeGrantsOnlySuperAdminRole(): void
    {
        $path = dirname(__DIR__, 3) . '/install/data/upgrade/2026_07_05_marketing_role_permissions.sql';
        if (!is_file($path)) {
            $this->markTestSkipped('本地升级 SQL 未生成，跳过 git-ignored 运维产物校验。');
        }
        $upgrade = (string) file_get_contents($path);

        $this->assertStringContainsString("`r`.`code` = 'super_admin'", $upgrade);
        $this->assertStringContainsString("'SettingGroup:PointsConfig'", $upgrade);
        $this->assertStringContainsString("'SettingGroup:MemberConfig'", $upgrade);
        $this->assertStringContainsString("`p`.`code` LIKE 'SystemPoints%'", $upgrade);
        $this->assertStringContainsString("`p`.`code` LIKE 'SystemMember%'", $upgrade);
        $this->assertStringNotContainsString("`r`.`code` = 'admin'", $upgrade);
    }
}
