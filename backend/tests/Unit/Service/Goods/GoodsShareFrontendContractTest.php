<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Goods;

use PHPUnit\Framework\TestCase;

final class GoodsShareFrontendContractTest extends TestCase
{
    public function testGoodsShareUsesGoodsFieldsBeforeGlobalDefaults(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/goods/detail.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString("const title = goods.value?.name || config.client_share_title", $source);
        $this->assertStringContainsString('goods.value?.subtitle ||', $source);
        $this->assertStringContainsString('htmlToShareText(currentDescriptionHtml.value)', $source);
        $this->assertStringContainsString('config.client_share_desc ||', $source);
        $this->assertStringContainsString('goods.value?.main_image_full_url ||', $source);
        $this->assertStringContainsString('goods.value?.main_image ||', $source);
        $this->assertStringContainsString('config.client_share_cover ||', $source);
        $this->assertStringContainsString('return { title, text, path, query, imageUrl }', $source);
        $this->assertStringContainsString('navigator.share({ title, text, url })', $source);
    }
}
