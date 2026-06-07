<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use mall_base\drivers\sms\AliyunPnvsDriver;
use mall_base\drivers\sms\AliyunSmsDriver;
use mall_base\drivers\sms\BaseSmsDriver;
use mall_base\drivers\sms\contracts\SmsTemplateManagerInterface;
use mall_base\drivers\sms\MockSmsDriver;
use PHPUnit\Framework\TestCase;

/**
 * 短信驱动契约测试
 *
 * 锁定整个 PNVS 分支判断的根基:
 *  - AliyunPnvsDriver 不实现 SmsTemplateManagerInterface (无远端签名/模板管理 API)
 *  - AliyunSmsDriver 实现 SmsTemplateManagerInterface (支持远端管理)
 *  - PNVS 驱动 supportsCodeVerification() 必须返回 true (平台侧验证码生命周期)
 *
 * SmsDriverFactory::supportsRemoteSignManagement / SmsService::resolveDriverForScene
 * 都基于以上两个契约位区分流程,任何驱动重构都不能破坏这些断言。
 */
final class SmsDriverContractTest extends TestCase
{
    public function testPnvsDriverDoesNotImplementTemplateManager(): void
    {
        $this->assertFalse(
            is_subclass_of(AliyunPnvsDriver::class, SmsTemplateManagerInterface::class),
            'AliyunPnvsDriver 不应实现 SmsTemplateManagerInterface,因为阿里云 PNVS 没有签名/模板管理 API。'
            . '一旦实现会导致 SmsDriverFactory::supportsRemoteSignManagement 判断翻转,LOCAL_ONLY 录入流程崩溃。'
        );
    }

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

    public function testPnvsDriverSupportsCodeVerification(): void
    {
        $driver = new AliyunPnvsDriver([
            'access_key_id' => 'test',
            'access_key_secret' => 'test',
            'region' => 'cn-hangzhou',
        ]);

        $this->assertTrue(
            $driver->supportsCodeVerification(),
            'PNVS 驱动必须返回 supportsCodeVerification()=true,SmsService::resolveDriverForScene 据此走平台侧校验分支。'
        );
        $this->assertInstanceOf(BaseSmsDriver::class, $driver);
    }

    public function testTraditionalDriversDoNotSupportCodeVerification(): void
    {
        $aliyun = new AliyunSmsDriver([
            'access_key_id' => 'test',
            'access_key_secret' => 'test',
            'region' => 'cn-hangzhou',
        ]);
        $mock = new MockSmsDriver();

        $this->assertFalse(
            $aliyun->supportsCodeVerification(),
            '普通阿里云驱动不应支持平台侧校验,验证码必须走本地缓存。'
        );
        $this->assertFalse(
            $mock->supportsCodeVerification(),
            'Mock 驱动不应支持平台侧校验,以便单元测试用本地缓存路径覆盖。'
        );
    }
}
