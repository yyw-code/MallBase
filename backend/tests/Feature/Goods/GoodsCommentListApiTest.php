<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class GoodsCommentListApiTest extends TestCase
{
    use ApiClientTrait;

    public function testClientGoodsCommentListRouteReturnsPaginationShape(): void
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/review/list',
            [
                'goods_id' => 1,
                'page' => 1,
                'limit' => 3,
            ],
        );

        if ($response === null) {
            $this->markTestSkipped('接口不可达，跳过客户端商品评论列表测试。');
        }

        $this->assertSame(200, $response['code'] ?? null);
        $this->assertIsArray($response['data'] ?? null);
        $this->assertArrayHasKey('total', $response['data']);
        $this->assertArrayHasKey('list', $response['data']);
        $this->assertIsArray($response['data']['list']);
    }
}
