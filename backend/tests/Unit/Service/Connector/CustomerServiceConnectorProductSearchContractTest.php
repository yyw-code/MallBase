<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Connector;

use PHPUnit\Framework\TestCase;

final class CustomerServiceConnectorProductSearchContractTest extends TestCase
{
    public function testConnectorExposesProductSearchEndpoint(): void
    {
        $basePath = dirname(__DIR__, 4);
        $route = file_get_contents($basePath . '/route/customer_service.php');
        $controller = file_get_contents($basePath . '/app/controller/connector/CustomerServiceController.php');
        $service = file_get_contents($basePath . '/app/service/connector/CustomerServiceConnectorService.php');

        $this->assertIsString($route);
        $this->assertIsString($controller);
        $this->assertIsString($service);

        $this->assertStringContainsString("Route::post('products/search', 'productSearch')", $route);
        $this->assertStringContainsString('public function productSearch(): Response', $controller);
        $this->assertStringContainsString('->productSearch((array) $this->request->param())', $controller);
        $this->assertStringContainsString('public function productSearch(array $input): array', $service);
        $this->assertStringContainsString("->where('status', 1)", $service);
        $this->assertStringContainsString("->where('is_on_sale', 1)", $service);
        $this->assertStringContainsString("->whereNull('delete_time')", $service);
        $this->assertStringContainsString("'items' => array_map", $service);
    }

    public function testProductSummaryRejectsProductsThatAreNotVisibleToVisitors(): void
    {
        $service = file_get_contents(
            dirname(__DIR__, 4) . '/app/service/connector/CustomerServiceConnectorService.php'
        );

        $this->assertIsString($service);
        $summaryStart = strpos($service, 'public function productSummary(');
        $nextMethod = strpos($service, 'private function searchSkuGoodsIds(', $summaryStart ?: 0);
        $this->assertIsInt($summaryStart);
        $this->assertIsInt($nextMethod);

        $summary = substr($service, $summaryStart, $nextMethod - $summaryStart);
        $this->assertStringContainsString("->where('status', 1)", $summary);
        $this->assertStringContainsString("->where('is_on_sale', 1)", $summary);
        $this->assertStringContainsString("->whereNull('delete_time')", $summary);
        $this->assertStringContainsString('->hydrateGoodsDetail($goods->toArray())', $summary);
        $this->assertStringContainsString("'image' => (string) (\$hydratedGoods['main_image_full_url'] ?? '')", $summary);
    }
}
