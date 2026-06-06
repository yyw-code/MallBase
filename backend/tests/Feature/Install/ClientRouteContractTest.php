<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class ClientRouteContractTest extends TestCase
{
    public function testClientRouteHasSpaFallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/route/client.php');

        $this->assertStringContainsString('| 客户端 H5 SPA 兜底', $source);
        $this->assertStringContainsString("preg_replace('#^client/?#', '', \$path)", $source);
        $this->assertStringContainsString("'client' . DIRECTORY_SEPARATOR . 'index.html'", $source);
        $this->assertStringContainsString("abort(404, '客户端 H5 页面未找到，请先构建 H5 前端');", $source);
    }
}
