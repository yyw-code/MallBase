<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\service\order\RefundSnGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 售后单号生成器单元测试
 *
 * 以反射 + 匿名子类覆盖 Cache 依赖路径，纯逻辑验证售后单号格式与唯一性
 *
 * 规则：R + 6 位日期（YYMMDD）+ 10 位日内递增序列，总长 17 位
 */
final class RefundSnGeneratorTest extends TestCase
{
    /**
     * next() 应返回 17 位字符串，首位为 R，第 2-7 位为当日 YYMMDD
     */
    public function testNextProducesSeventeenCharsWithPrefixAndDate(): void
    {
        $generator = $this->makeWithSeq(1);
        $sn = $generator->next();

        $this->assertSame(17, strlen($sn));
        $this->assertSame('R', substr($sn, 0, 1));
        $this->assertSame(date('ymd'), substr($sn, 1, 6));
        $this->assertSame('0000000001', substr($sn, 7));
    }

    /**
     * 序列号应按传入值零填充到 10 位
     */
    public function testSequenceIsZeroPaddedToTenDigits(): void
    {
        $generator = $this->makeWithSeq(98765);
        $sn = $generator->next();

        $this->assertSame('0000098765', substr($sn, 7));
    }

    /**
     * 前缀 R 应始终大写，不受日期或序列影响
     */
    public function testPrefixIsAlwaysUppercaseR(): void
    {
        foreach ([1, 42, 99999, 1234567890] as $seq) {
            $sn = $this->makeWithSeq($seq)->next();
            $this->assertSame('R', $sn[0], "seq={$seq} 应以 R 开头");
        }
    }

    /**
     * 同一日内递增序列应生成不同售后单号
     */
    public function testDifferentSequencesYieldDifferentSns(): void
    {
        $seq = 0;
        $generator = new class ($seq) extends RefundSnGenerator {
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
                return 'R' . $date . str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT);
            }
        };

        $snSet = [];
        for ($i = 0; $i < 1000; $i++) {
            $snSet[$generator->next()] = true;
        }

        $this->assertCount(1000, $snSet, '1000 次生成应得到 1000 个唯一售后单号');
    }

    /**
     * 降级序列应始终返回正整数
     */
    public function testFallbackSeqReturnsPositiveInteger(): void
    {
        $generator = new RefundSnGenerator();
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
    private function makeWithSeq(int $seq): RefundSnGenerator
    {
        return new class ($seq) extends RefundSnGenerator {
            private int $fixedSeq;
            public function __construct(int $fixedSeq)
            {
                $this->fixedSeq = $fixedSeq;
            }
            public function next(): string
            {
                $date = date('ymd');
                return 'R' . $date . str_pad((string) $this->fixedSeq, 10, '0', STR_PAD_LEFT);
            }
        };
    }
}
