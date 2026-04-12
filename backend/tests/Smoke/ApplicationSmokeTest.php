<?php

declare(strict_types=1);

namespace Tests\Smoke;

use PHPUnit\Framework\TestCase;
use think\App;

final class ApplicationSmokeTest extends TestCase
{
    public function testThinkPhpCoreClassCanBeLoaded(): void
    {
        $this->assertTrue(class_exists(App::class));
    }

    public function testHttpHealthSmokeIsReservedForNextIteration(): void
    {
        $this->markTestIncomplete('TODO: add /health or /ping endpoint smoke assertion.');
    }
}
