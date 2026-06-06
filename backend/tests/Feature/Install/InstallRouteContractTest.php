<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallRouteContractTest extends TestCase
{
    public function testInstallPageSupportsPathWithAndWithoutTrailingSlash(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/route/install.php');

        $this->assertStringContainsString("Route::get('', \$installPage);", $source);
        $this->assertStringContainsString("Route::get('/', \$installPage);", $source);
    }

    public function testStaticMissRouteDoesNotReadDirectoryAsFile(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/route/app.php');

        $this->assertStringContainsString('if (is_dir($filePath))', $source);
        $this->assertStringContainsString("\$indexPath = rtrim(\$filePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.html';", $source);
        $this->assertStringContainsString('if (!is_file($filePath))', $source);
        $this->assertStringNotContainsString('if (!file_exists($filePath))', $source);
    }
}
