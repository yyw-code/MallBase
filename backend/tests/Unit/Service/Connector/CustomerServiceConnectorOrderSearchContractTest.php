<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Connector;

use PHPUnit\Framework\TestCase;

final class CustomerServiceConnectorOrderSearchContractTest extends TestCase
{
    public function testVisitorOrderSearchUsesOnlyTheSignedExternalUserIdentity(): void
    {
        $basePath = dirname(__DIR__, 4);
        $route = file_get_contents($basePath . '/route/customer_service.php');
        $controller = file_get_contents($basePath . '/app/controller/connector/CustomerServiceController.php');
        $service = file_get_contents($basePath . '/app/service/connector/CustomerServiceConnectorService.php');

        $this->assertIsString($route);
        $this->assertIsString($controller);
        $this->assertIsString($service);

        $this->assertStringContainsString("Route::post('orders/search', 'orderSearch')", $route);
        $this->assertStringContainsString('public function orderSearch(): Response', $controller);
        $this->assertStringContainsString("header('X-CS-External-User-Id'", $controller);
        $this->assertStringContainsString("'X-CS-External-User-Authenticated'", $controller);
        $this->assertStringContainsString('public function orderSearch(', $service);
        $this->assertStringContainsString('$this->requireExternalUserId($externalUserId, $authenticated)', $service);
        $this->assertStringContainsString("->where('user_id', \$userId)", $service);
        $this->assertStringContainsString("->whereNull('delete_time')", $service);
        $this->assertStringContainsString("'items' => array_map", $service);
        $this->assertStringNotContainsString("\$input['user_id']", $service);
    }

    public function testOrderSummaryIsScopedToTheSignedExternalUser(): void
    {
        $basePath = dirname(__DIR__, 4);
        $controller = file_get_contents($basePath . '/app/controller/connector/CustomerServiceController.php');
        $service = file_get_contents($basePath . '/app/service/connector/CustomerServiceConnectorService.php');

        $this->assertIsString($controller);
        $this->assertIsString($service);
        $this->assertStringContainsString('public function orderSummary($id): Response', $controller);
        $this->assertStringContainsString('public function orderSummary(', $service);

        $orderSummaryStart = strpos($service, 'public function orderSummary(');
        $userSummaryStart = strpos($service, 'public function userSummary(', $orderSummaryStart ?: 0);
        $this->assertIsInt($orderSummaryStart);
        $this->assertIsInt($userSummaryStart);
        $orderSummary = substr($service, $orderSummaryStart, $userSummaryStart - $orderSummaryStart);
        $this->assertStringContainsString('$this->requireExternalUserId($externalUserId, $authenticated)', $orderSummary);
        $this->assertStringContainsString("->where('user_id', \$userId)", $orderSummary);
    }
}
