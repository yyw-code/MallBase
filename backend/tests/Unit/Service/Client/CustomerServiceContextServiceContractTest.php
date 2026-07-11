<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Client;

use PHPUnit\Framework\TestCase;

final class CustomerServiceContextServiceContractTest extends TestCase
{
    public function testIssuedVisitorPayloadMarksBackendVerifiedUserAsAuthenticated(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/service/client/CustomerServiceContextService.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("'authenticated' => true", $source);
    }
}
