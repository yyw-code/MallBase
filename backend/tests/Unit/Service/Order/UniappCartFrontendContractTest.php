<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use PHPUnit\Framework\TestCase;

final class UniappCartFrontendContractTest extends TestCase
{
    public function testCartPageShowsLoginStateInsteadOfEmptyCartForGuest(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/cart/index.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString('const loggedIn = ref(isLoggedIn())', $source);
        $this->assertStringContainsString('const isGuest = computed(() => !loggedIn.value && !loading.value)', $source);
        $this->assertStringContainsString('请先登录后查看购物车', $source);
        $this->assertStringContainsString('actionText="去登录"', $source);
        $this->assertStringContainsString('function goLogin()', $source);
        $this->assertStringContainsString("encodeURIComponent('/pages/cart/index')", $source);
        $this->assertStringContainsString('cartStore.list = []', $source);
    }

    public function testCartCheckoutBarOnlyShowsWithRowsAndAvoidsH5TabBar(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/cart/index.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString('const hasCartItems = computed(() => !loading.value && list.value.length > 0)', $source);
        $this->assertStringContainsString('<view v-if="hasCartItems" class="cart-list">', $source);
        $this->assertStringContainsString('<view v-if="hasCartItems" class="bottom-bar">', $source);
        $this->assertStringContainsString('/* #ifdef H5 */', $source);
        $this->assertStringContainsString('bottom: var(--window-bottom, 0);', $source);
    }
}
