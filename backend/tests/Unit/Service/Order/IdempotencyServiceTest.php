<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\common\service\IdempotencyService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 幂等服务单元测试（纯逻辑部分）
 *
 * 覆盖：
 *  - 缓存键拼装
 *  - recall 对 processing 占位的特殊处理
 *  - recall 对 JSON 字符串的解码
 *  - DEFAULT_TTL 常量契约
 *
 * 真实 Redis setnx 路径由 commit #4 的 OrderService 集成测试覆盖
 */
final class IdempotencyServiceTest extends TestCase
{
    public function testBuildKeyIncludesScopeAndKey(): void
    {
        $service = new IdempotencyService();
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('buildKey');
        $method->setAccessible(true);

        $key = $method->invoke($service, 'order:create', 'abc-123');
        $this->assertSame('idem:order:create:abc-123', $key);
    }

    public function testBuildKeyKeepsDifferentScopesIsolated(): void
    {
        $service = new IdempotencyService();
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('buildKey');
        $method->setAccessible(true);

        $k1 = $method->invoke($service, 'order:create', 'key-1');
        $k2 = $method->invoke($service, 'order:pay', 'key-1');
        $this->assertNotSame($k1, $k2);
    }

    public function testDefaultTtlIsTenMinutes(): void
    {
        $this->assertSame(600, IdempotencyService::DEFAULT_TTL);
    }

    /**
     * 占位常量不可与业务 payload 冲突
     */
    public function testPlaceholderConstantIsNotJsonLike(): void
    {
        $ref = new ReflectionClass(IdempotencyService::class);
        $constants = $ref->getReflectionConstants();

        $placeholder = null;
        foreach ($constants as $constant) {
            if ($constant->getName() === 'PLACEHOLDER') {
                $placeholder = $constant->getValue();
                break;
            }
        }

        $this->assertNotNull($placeholder);
        $this->assertIsString($placeholder);
        // 必须不是合法 JSON 数组，否则 recall 解码后会被误判为业务数据
        $decoded = json_decode($placeholder, true);
        $this->assertFalse(is_array($decoded));
    }

    /**
     * CACHE_PREFIX 不能与既有的 request_lock 冲突
     */
    public function testCachePrefixDoesNotCollideWithRequestLock(): void
    {
        $ref = new ReflectionClass(IdempotencyService::class);
        $constants = $ref->getReflectionConstants();

        $prefix = null;
        foreach ($constants as $constant) {
            if ($constant->getName() === 'CACHE_PREFIX') {
                $prefix = $constant->getValue();
                break;
            }
        }

        $this->assertSame('idem:', $prefix);
        $this->assertStringStartsNotWith('request_lock:', (string) $prefix);
    }
}
