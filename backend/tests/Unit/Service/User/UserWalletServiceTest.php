<?php

declare(strict_types=1);

namespace Tests\Unit\Service\User;

use app\service\admin\user\UserWalletService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UserWalletServiceTest extends TestCase
{
    public function testDecimalToCentsAcceptsMaxAmount(): void
    {
        $this->assertSame(99_999_999, $this->decimalToCents('999999.99'));
    }

    public function testDecimalToCentsRejectsOverflowAmount(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('调整金额不能超过 999999.99 元');

        $this->decimalToCents('1000000.00');
    }

    public function testDecimalToCentsRejectsHugeAmountWithoutFloatOverflow(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('调整金额不能超过 999999.99 元');

        $this->decimalToCents('999999999999999999999999999999.00');
    }

    private function decimalToCents(string $amount): int
    {
        $service = new UserWalletService();
        $method = new ReflectionMethod(UserWalletService::class, 'decimalToCents');
        $method->setAccessible(true);

        return $method->invoke($service, $amount);
    }
}
