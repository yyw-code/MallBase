<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Install;

use app\service\install\InstallService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InstallServiceDatabaseErrorTest extends TestCase
{
    public function testNormalizeDatabaseErrorKeepsPasswordFailureSeparate(): void
    {
        $message = "SQLSTATE[HY000] [1045] Access denied for user 'demo1'@'localhost' (using password: YES)";

        $this->assertSame('数据库用户名或密码错误，请重新检查账号密码', $this->normalize($message));
    }

    public function testNormalizeDatabaseErrorReportsPrivilegeFailure(): void
    {
        $message = "SQLSTATE[HY000]: General error: 1044 Access denied for user 'demo1'@'localhost' to database 'mallbase'";

        $this->assertSame('数据库账号权限不足，请授予目标库完整权限后重试', $this->normalize($message));
    }

    private function normalize(string $message): string
    {
        $reflection = new ReflectionClass(InstallService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeDatabaseError');
        $method->setAccessible(true);

        return $method->invoke($service, $message);
    }
}
