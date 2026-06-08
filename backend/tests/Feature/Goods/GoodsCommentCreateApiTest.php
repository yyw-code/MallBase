<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class GoodsCommentCreateApiTest extends TestCase
{
    use ApiClientTrait;

    public function testClientGoodsCommentCreateRouteRequiresLogin(): void
    {
        $response = $this->requestJson(
            'POST',
            $this->getBaseUrl() . '/client/api/review/create',
            [
                'order_item_id' => 1,
                'rating' => 5,
                'content' => '商品体验很好',
                'images' => [],
                'is_anonymous' => 0,
            ],
        );

        if ($response === null) {
            $this->markTestSkipped('接口不可达，跳过客户端商品评价创建登录校验测试。');
        }

        $this->assertSame(401, $response['code'] ?? null);
    }
}
