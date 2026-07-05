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
        $this->assertStringContainsString("'relation_valid_days'", $settings);
        $this->assertStringContainsString("'amount_open_threshold_cents'", $settings);
        $this->assertStringContainsString("'invite_reward_enabled'", $settings);
        $this->assertStringContainsString("'invite_reward_trigger'", $settings);
        $this->assertStringContainsString("'invite_reward_amount_cents'", $settings);
        $this->assertStringContainsString("'attribution_enabled'", $settings);
    }

    private function readBackendFile(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        $this->assertIsString($content);
        return $content;
    }
}
