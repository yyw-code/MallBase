<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Connector;

use PHPUnit\Framework\TestCase;

final class CustomerServiceConnectorRefundContractTest extends TestCase
{
    public function testRefundMappingContainsOneTypeTextField(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/service/connector/CustomerServiceConnectorService.php');
        $this->assertIsString($source);

        $mappingStart = strpos($source, "'refunds' => array_map");
        $mappingEnd = strpos($source, "'logs' => array_map", $mappingStart ?: 0);
        $this->assertNotFalse($mappingStart);
        $this->assertNotFalse($mappingEnd);

        $mapping = substr($source, $mappingStart, $mappingEnd - $mappingStart);
        $this->assertSame(1, substr_count($mapping, "'type_text' =>"));
    }
}
