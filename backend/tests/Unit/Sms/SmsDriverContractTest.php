<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use mall_base\drivers\sms\AliyunSmsDriver;
use mall_base\drivers\sms\BaseSmsDriver;
use mall_base\drivers\sms\contracts\SmsTemplateManagerInterface;
use mall_base\drivers\sms\MockSmsDriver;
use PHPUnit\Framework\TestCase;

/**
 * 短信驱动契约测试
 *
 *  - AliyunSmsDriver 实现 SmsTemplateManagerInterface (支持远端管理)
 *
 * SmsDriverFactory::supportsRemoteSignManagement 基于该契约判断是否可管理签名和模板。
 */
final class SmsDriverContractTest extends TestCase
{
    public function testAliyunSmsDriverImplementsTemplateManager(): void
    {
        $this->assertTrue(
            is_subclass_of(AliyunSmsDriver::class, SmsTemplateManagerInterface::class),
            'AliyunSmsDriver 必须实现 SmsTemplateManagerInterface,这是正常短信流程调用 addSign/addTemplate 的前提。'
        );
    }

    public function testAliyunTemplateStatusMappingSupportsNumericGetSmsTemplateStatus(): void
    {
        $driver = new AliyunSmsDriver([
            'access_key_id' => 'test',
            'access_key_secret' => 'test',
            'region' => 'cn-hangzhou',
        ]);
        $method = new \ReflectionMethod(AliyunSmsDriver::class, 'mapTemplateStatus');
        $method->setAccessible(true);

        $this->assertSame('pending', $method->invoke($driver, '0'));
        $this->assertSame('passed', $method->invoke($driver, '1'));
        $this->assertSame('rejected', $method->invoke($driver, '2'));
        $this->assertSame('rejected', $method->invoke($driver, '10'));
        $this->assertSame('passed', $method->invoke($driver, 'AUDIT_STATE_PASS'));
        $this->assertSame('rejected', $method->invoke($driver, 'AUDIT_STATE_NOT_PASS'));
    }

    public function testAvailableDriversExtendBaseSmsDriver(): void
    {
        $aliyun = new AliyunSmsDriver([
            'access_key_id' => 'test',
            'access_key_secret' => 'test',
            'region' => 'cn-hangzhou',
        ]);
        $mock = new MockSmsDriver();

        $this->assertInstanceOf(BaseSmsDriver::class, $aliyun);
        $this->assertInstanceOf(BaseSmsDriver::class, $mock);
    }
}
