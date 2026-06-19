<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use mall_base\base\BaseService;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BaseServiceModelTest extends TestCase
{
    public function testModelReturnsFreshInstanceEachTime(): void
    {
        $service = new TestableBaseService();

        $first = $service->freshModel();
        $first->marker = 'first';
        $second = $service->freshModel();

        $this->assertNotSame($first, $second);
        $this->assertFalse(property_exists($second, 'marker'));
    }
}

/**
 * @internal
 */
final class TestableBaseService extends BaseService
{
    protected string $modelClass = stdClass::class;

    public function freshModel(): object
    {
        return $this->model();
    }
}
