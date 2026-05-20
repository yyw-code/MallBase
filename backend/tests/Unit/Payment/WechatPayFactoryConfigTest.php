<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\client\payment\WechatPayFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 静态规约测试：WechatPayFactory 必须按 Issue 1 修复后的形态实现
 *
 * 不依赖 TP 框架 bootstrap（tests/phpunit.xml 仅走 vendor/autoload），
 * 因此不直接调 build()。改为对源码做静态断言，覆盖三条关键不变量：
 *   1) 必须读 `pay_wechat_merchant_cert`（V3 签名头要 openssl_x509_parse 商户证书）
 *   2) `certificate` 必须使用 `$merchantCertPath`，禁止误用平台公钥
 *   3) `platform_certs` 必须以 `public_key_id` 为 key 的关联数组，
 *      避免触发 Merchant::normalizePlatformCerts() 在构造期对公钥做 x509 解析
 *
 * 任一项被回退都会触发 "Read the $certificate failed" 线上故障，必须红灯。
 */
final class WechatPayFactoryConfigTest extends TestCase
{
    private static function factorySource(): string
    {
        $file = (new ReflectionClass(WechatPayFactory::class))->getFileName();
        self::assertNotFalse($file, 'WechatPayFactory 类文件应能定位');

        return (string) file_get_contents((string) $file);
    }

    public function testBuildReadsMerchantCertSetting(): void
    {
        $this->assertStringContainsString(
            "pay_wechat_merchant_cert",
            self::factorySource(),
            'WechatPayFactory::build() 必须读 pay_wechat_merchant_cert 配置项'
        );
    }

    public function testCertificateUsesMerchantCertNotPlatformKey(): void
    {
        $source = self::factorySource();

        $this->assertStringContainsString(
            "'certificate'    => \$merchantCertPath",
            $source,
            "EasyWeChat 的 'certificate' 必须传商户 API 证书路径，"
            . '传平台公钥会触发 openssl_x509_parse 失败'
        );
        $this->assertStringNotContainsString(
            "'certificate'      => \$platformKeyPath",
            $source,
            '禁止再把平台公钥当作 certificate 传入（回退到旧 bug）'
        );
    }

    public function testPlatformCertsIsAssociativeKeyedByPublicKeyId(): void
    {
        $this->assertStringContainsString(
            "'platform_certs' => [\$platformId => \$platformKeyPath]",
            self::factorySource(),
            'platform_certs 必须以 public_key_id 为 key 的关联数组形式传入，'
            . '防止 Merchant::normalizePlatformCerts() 在构造期对公钥做 x509 解析'
        );
    }

    public function testDiagnoseChecklistIncludesMerchantCert(): void
    {
        $this->assertStringContainsString(
            "'商户 API 证书'       => 'pay_wechat_merchant_cert'",
            self::factorySource(),
            'diagnose() 自检清单必须覆盖商户 API 证书项'
        );
    }
}
