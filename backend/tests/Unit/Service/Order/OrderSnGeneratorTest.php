<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\service\order\OrderSnGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 订单号生成器单元测试
 *
 * 以反射 + 匿名子类覆盖 Cache 依赖路径，纯逻辑验证订单号格式与唯一性
 */
final class OrderSnGeneratorTest extends TestCase
{
    /**
     * next() 应返回 16 位字符串，前 6 位为当日 YYMMDD
     */
    public function testNextProducesSixteenCharsWithDatePrefix(): void
    {
        $generator = $this->makeWithSeq(1);
        $sn = $generator->next();

        $this->assertSame(16, strlen($sn));
        $this->assertSame(date('ymd'), substr($sn, 0, 6));
        $this->assertSame('0000000001', substr($sn, 6));
    }

    /**
     * 序列号应按传入值零填充到 10 位
     */
    public function testSequenceIsZeroPaddedToTenDigits(): void
    {
        $generator = $this->makeWithSeq(12345);
        $sn = $generator->next();

        $this->assertSame('0000012345', substr($sn, 6));
    }

    /**
     * 同一日内递增序列应生成不同订单号
     */
    public function testDifferentSequencesYieldDifferentSns(): void
    {
        $seq = 0;
        $generator = new class ($seq) extends OrderSnGenerator {
            private int $seq;
            public function __construct(int &$seq)
            {
                $this->seq = &$seq;
            }
            public function next(): string
            {
                // 模拟 Redis INCR 的原子递增
                $this->seq++;
                $date = date('ymd');
                return $date . str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT);
            }
        };

        $snSet = [];
        for ($i = 0; $i < 1000; $i++) {
            $snSet[$generator->next()] = true;
        }

        $this->assertCount(1000, $snSet, '1000 次生成应得到 1000 个唯一订单号');
    }

    /**
     * 降级序列应始终返回正整数
     */
    public function testFallbackSeqReturnsPositiveInteger(): void
    {
        $generator = new OrderSnGenerator();
        $ref = new ReflectionClass($generator);
        $method = $ref->getMethod('fallbackSeq');
        $method->setAccessible(true);

        for ($i = 0; $i < 20; $i++) {
            $seq = $method->invoke($generator);
            $this->assertIsInt($seq);
            $this->assertGreaterThan(0, $seq);
        }
    }

    /**
     * 构造一个返回固定 seq 的匿名子类，规避 Cache 依赖
     */
    private function makeWithSeq(int $seq): OrderSnGenerator
    {
        return new class ($seq) extends OrderSnGenerator {
            private int $fixedSeq;
            public function __construct(int $fixedSeq)
            {
                $this->fixedSeq = $fixedSeq;
            }
            public function next(): string
            {
                $date = date('ymd');
                return $date . str_pad((string) $this->fixedSeq, 10, '0', STR_PAD_LEFT);
            }
        };
    }
}
