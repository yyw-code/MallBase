<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use PHPUnit\Framework\TestCase;

final class DistributionPhaseOneSchemaContractTest extends TestCase
{
    public function testDistributionSchemaContainsPhaseOneTablesAndColumns(): void
    {
        $schema = $this->readBackendFile('install/data/schema/19_mb_distribution.sql');

        $this->assertStringContainsString('CREATE TABLE `mb_distribution_apply`', $schema);
        $this->assertStringContainsString('`open_source` varchar(32) NOT NULL DEFAULT \'admin\'', $schema);
        $this->assertStringContainsString('`expire_time` datetime DEFAULT NULL COMMENT \'关系有效期，NULL为永久有效\'', $schema);
        $this->assertStringContainsString('`attribution_scene` varchar(32) NOT NULL DEFAULT \'\' COMMENT \'归因场景', $schema);
        $this->assertStringContainsString('`invite_reward_status` tinyint(1) unsigned NOT NULL DEFAULT 0', $schema);
        $this->assertStringContainsString('`commission_type` varchar(16) NOT NULL DEFAULT \'rate\'', $schema);
        $this->assertStringContainsString('`first_fixed_cents` int(10) unsigned NOT NULL DEFAULT 0', $schema);
        $this->assertStringContainsString('`relation_id` bigint(20) unsigned NOT NULL DEFAULT 0', $schema);
        $this->assertStringContainsString('`attribution_target_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT \'归因对象ID快照\'', $schema);
    }

    public function testDistributionSettingsContainPhaseOneSwitches(): void
    {
        $settings = $this->readBackendFile('install/data/schema/03_mb_setting.sql');

        $this->assertStringContainsString("'distributor_open_mode'", $settings);
        $this->assertStringContainsString("'auto_open_level_id'", $settings);
        $this->assertStringContainsString("'amount_open_threshold_cents'", $settings);
        $this->assertStringContainsString("'relation_valid_days'", $settings);
        $this->assertStringContainsString("'invite_reward_enabled'", $settings);
        $this->assertStringContainsString("'invite_reward_trigger'", $settings);
        $this->assertStringContainsString("'invite_reward_amount_cents'", $settings);
        $this->assertStringContainsString("'attribution_enabled'", $settings);
        $this->assertStringNotContainsString("'invite_reward_bind_daily_limit'", $settings);
        $this->assertStringNotContainsString("'invite_reward_bind_total_limit'", $settings);
    }

    public function testClientDistributionStopsNewActionsWhenDisabled(): void
    {
        $service = $this->readBackendFile('app/service/client/distribution/DistributionCenterService.php');

        $this->assertStringContainsString('private function assertDistributionEnabled', $service);
        $this->assertStringContainsString('$settings = $this->assertDistributionEnabled', $service);
        $this->assertStringContainsString('$this->assertDistributionEnabled();', $service);
        $this->assertStringContainsString("throw new BusinessException('分销功能未开启');", $service);
    }

    public function testDefaultProfileContainsDistributionCenterEntry(): void
    {
        $schema = $this->readBackendFile('install/data/schema/13_mb_client_diy.sql');

        $this->assertStringContainsString("'分销中心'", $schema);
        $this->assertStringContainsString("'/pages-sub/distribution/index'", $schema);
    }

    public function testUniappDistributionPagesAndApiContractAreRegistered(): void
    {
        $pages = $this->readProjectFile('frontend/uniapp/pages.json');
        $this->assertStringContainsString('"root": "pages-sub/distribution"', $pages);
        foreach (['"path": "index"', '"path": "records"', '"path": "team"', '"path": "withdraw"'] as $path) {
            $this->assertStringContainsString($path, $pages);
        }

        $api = $this->readProjectFile('frontend/uniapp/api/distribution/distribution.js');
        foreach ([
            '/client/api/distribution/summary',
            '/client/api/distribution/commissions',
            '/client/api/distribution/logs',
            '/client/api/distribution/team',
            '/client/api/distribution/withdraws',
            '/client/api/distribution/withdraw',
            '/client/api/distribution/bindInvite',
            '/client/api/distribution/apply',
            '/client/api/distribution/shareInfo',
        ] as $path) {
            $this->assertStringContainsString($path, $api);
        }

        $center = $this->readProjectFile('frontend/uniapp/pages-sub/distribution/index.vue');
        $this->assertStringContainsString('分销功能未开启', $center);
        $this->assertStringContainsString('申请分销员', $center);
        $this->assertStringContainsString('绑定邀请码', $center);
        $this->assertStringContainsString('/pages-sub/distribution/withdraw', $center);
        $this->assertStringContainsString('/pages-sub/distribution/records', $center);
        $this->assertStringContainsString('/pages-sub/distribution/team', $center);

        $withdraw = $this->readProjectFile('frontend/uniapp/pages-sub/distribution/withdraw.vue');
        $this->assertStringContainsString('提现申请', $withdraw);
        $this->assertStringContainsString('最低提现', $withdraw);
        $this->assertStringContainsString('暂无提现记录', $withdraw);

        $records = $this->readProjectFile('frontend/uniapp/pages-sub/distribution/records.vue');
        $this->assertStringContainsString('佣金订单', $records);
        $this->assertStringContainsString('佣金流水', $records);
        $this->assertStringContainsString('暂无记录', $records);

        $team = $this->readProjectFile('frontend/uniapp/pages-sub/distribution/team.vue');
        $this->assertStringContainsString('一级团队', $team);
        $this->assertStringContainsString('二级团队', $team);
        $this->assertStringContainsString('暂无团队成员', $team);
    }

    private function readBackendFile(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        $this->assertIsString($content);
        return $content;
    }

    private function readProjectFile(string $path): string
    {
        $root = dirname(__DIR__, 4);
        $file = $root . '/' . $path;
        if (!is_file($file) && is_dir('/workspace')) {
            $file = '/workspace/' . $path;
        }
        $content = file_get_contents($file);
        $this->assertIsString($content);
        return $content;
    }
}
