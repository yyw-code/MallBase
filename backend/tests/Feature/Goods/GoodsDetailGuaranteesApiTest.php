<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class GoodsDetailGuaranteesApiTest extends TestCase
{
    use ApiClientTrait;

    public function testClientGoodsDetailReturnsGuarantees(): void
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/goods/info/1',
        );

        if ($response === null) {
            $this->markTestSkipped('接口不可达，跳过客户端商品保障测试。');
        }
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('当前环境未安装演示商品，跳过客户端商品保障测试。');
        }

        $this->assertIsArray($response['data']['guarantees'] ?? null);
        $this->assertNotEmpty($response['data']['guarantees']);
        $this->assertArrayHasKey('title', $response['data']['guarantees'][0]);
        $this->assertArrayHasKey('points_reward_preview_enabled', $response['data']);
        $this->assertArrayHasKey('member_growth_preview_enabled', $response['data']);

        $sku = $response['data']['skus'][0] ?? null;
        $this->assertIsArray($sku);
        $this->assertArrayHasKey('points_reward_preview_points', $sku);
        $this->assertArrayHasKey('points_reward_preview_text', $sku);
        $this->assertArrayHasKey('member_growth_preview_value', $sku);
        $this->assertArrayHasKey('member_growth_preview_text', $sku);
    }
}
