<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use PHPUnit\Framework\TestCase;

final class UniappMoneyPrecisionContractTest extends TestCase
{
    public function testFrontendPriceUtilityUsesCentBasedStringFormatting(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/utils/price.js');
        $this->assertIsString($source);

        $this->assertStringContainsString('decimalToCents', $source);
        $this->assertStringContainsString('centsToPrice', $source);
        $this->assertStringContainsString('multiplyPrice', $source);
        $this->assertStringContainsString('sumPrices', $source);
        $this->assertStringContainsString('splitPrice', $source);
        $this->assertStringNotContainsString('parseFloat', $source);
        $this->assertStringNotContainsString('toFixed', $source);
        $this->assertStringNotContainsString('roundDigit', $source);
        $this->assertStringContainsString(".slice(0, 2)", $source);
    }

    public function testCartAndOrderPagesDoNotUseFloatForDisplayedMoneyTotals(): void
    {
        $cartStoreSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/store/cart.js');
        $confirmSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/confirm.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/detail.vue');
        $priceSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/components/mb-price/mb-price.vue');
        $paySheetSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/components/mb-pay-method-sheet/mb-pay-method-sheet.vue');

        $this->assertIsString($cartStoreSource);
        $this->assertIsString($confirmSource);
        $this->assertIsString($detailSource);
        $this->assertIsString($priceSource);
        $this->assertIsString($paySheetSource);

        $this->assertStringContainsString("import { multiplyPrice, sumPrices } from '@/utils/price'", $cartStoreSource);
        $this->assertStringContainsString('multiplyPrice(item.unit_price, item.quantity)', $cartStoreSource);
        $this->assertStringNotContainsString('Number(item.unit_price) * item.quantity', $cartStoreSource);

        $this->assertStringContainsString('normalizePrice(previewResult.value.total_amount)', $confirmSource);
        $this->assertStringContainsString('normalizePrice(previewResult.value.freight_amount)', $confirmSource);
        $this->assertStringContainsString('normalizePrice(previewResult.value.pay_amount)', $confirmSource);
        $this->assertStringContainsString('isPositivePrice(displayFreight.value)', $confirmSource);
        $this->assertStringNotContainsString('Number(previewResult.value.total_amount)', $confirmSource);
        $this->assertStringNotContainsString('Number(previewResult.value.freight_amount)', $confirmSource);
        $this->assertStringNotContainsString('Number(previewResult.value.pay_amount)', $confirmSource);

        $this->assertStringContainsString('sumPrices(orderItems.value.map((item) => multiplyPrice(item.unit_price, item.quantity)))', $detailSource);
        $this->assertStringContainsString("import { splitPrice } from '@/utils/price'", $priceSource);
        $this->assertStringNotContainsString('Number(props.value)', $priceSource);
        $this->assertStringContainsString("import { formatPrice } from '@/utils/price'", $paySheetSource);
        $this->assertStringNotContainsString('Number(props.amount).toFixed(2)', $paySheetSource);
    }

    public function testBackendOrderMoneyUsesBcmathAndFreightUsesCentPrecision(): void
    {
        $orderServiceSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/OrderService.php');
        $freightSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/FreightCalculatorService.php');
        $prepaySource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/payment/PrepayService.php');
        $cartServiceSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/CartService.php');

        $this->assertIsString($orderServiceSource);
        $this->assertIsString($freightSource);
        $this->assertIsString($prepaySource);
        $this->assertIsString($cartServiceSource);

        $this->assertStringContainsString('bcmul($item[\'unit_price\'], (string) $item[\'quantity\'], 2)', $orderServiceSource);
        $this->assertStringContainsString('bcadd($total, $sub, 2)', $orderServiceSource);
        $this->assertStringContainsString('bcsub(bcadd($total, $freight, 2), $discount, 2)', $orderServiceSource);
        $this->assertStringContainsString('decimalToCents(max(0.0, $firstFee))', $freightSource);
        $this->assertStringContainsString('decimalToUnits(max(0.0, $totalCount), 3)', $freightSource);
        $this->assertStringContainsString('intdiv($extraUnits + $continueUnits - 1, $continueUnits)', $freightSource);
        $this->assertStringContainsString('$firstFeeCents + $steps * $continueFeeCents', $freightSource);
        $this->assertStringNotContainsString('ceil($extra / $continueAmount)', $freightSource);
        $this->assertStringNotContainsString('$firstFee + $steps * $continueFee', $freightSource);
        $this->assertStringContainsString("'unit_price'  => \$sku !== null ? (string) \$sku['price'] : '0.00'", $cartServiceSource);
        $this->assertStringNotContainsString("'unit_price'  => \$sku !== null ? (float) \$sku['price'] : 0.0", $cartServiceSource);
        $this->assertStringContainsString("throw new BusinessException('支付金额计算组件不可用')", $prepaySource);
        $this->assertStringContainsString("bcmul(\$amount, '100', 0)", $prepaySource);
        $this->assertStringNotContainsString('round(((float) $yuan) * 100)', $prepaySource);
    }
}
