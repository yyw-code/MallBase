<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\TestCase;

final class UpgradeNginxContractTest extends TestCase
{
    public function testWholeUpgradePrefixIsProxiedAndNeverMappedToHostDataDirectory(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 4) . '/deploy/nginx/mallbase.conf');
        $start = strpos($configuration, 'location ^~ /upgrade/ {');
        $this->assertNotFalse($start);
        $end = strpos($configuration, "\n}", (int) $start);
        $this->assertNotFalse($end);
        $block = substr($configuration, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString('proxy_pass http://127.0.0.1:18081;', $block);
        $this->assertStringContainsString('access_log off;', $block);
        $this->assertStringContainsString('proxy_buffering off;', $block);
        $this->assertStringContainsString('proxy_read_timeout 1h;', $block);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Host $host;', $block);
        $this->assertStringNotContainsString(' alias ', $block);
        $this->assertStringNotContainsString(' root ', $block);
        $this->assertStringNotContainsString('/upgrade/agent-private', $configuration);
    }
}
