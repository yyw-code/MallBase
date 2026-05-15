<?php

declare(strict_types=1);

namespace app\service\client\payment;

use app\common\enum\PayScene;
use EasyWeChat\Pay\Application as PayApplication;
use mall_base\exception\BusinessException;

/**
 * EasyWeChat Pay Application 工厂
 *
 * 设计要点（遵循 .codex/skills/thinkPHP/wechat-pay-stateless）：
 *  - 每次调用返回新实例，禁止 static/容器单例缓存
 *  - 配置每次重新从 mb_setting 读取，后台改完即时生效
 *  - 证书内容请求级加载，不写类静态
 *  - AppID 按 scene 切换（mini 走小程序 AppID，offi 走公众号 AppID，h5 无 AppID）
 *  - 任一凭据缺失直接抛 BusinessException，禁止用空字符串调远端
 *
 * @see \app\service\client\WechatAppFactory  小程序/公众号 Factory 的姊妹范式
 */
class WechatPayFactory
{
    /**
     * 构造支付 Application
     *
     * 不绑定 scene：SDK 构造只需商户号 / 私钥 / 平台公钥，AppID 仅在 prepay 时
     * 由 adapter 通过 {@see appIdOf()} 单独取用。
     *
     * 注意：返回的 Application 仅在当前请求生命周期内复用，禁止保存到类属性。
     */
    public function build(): PayApplication
    {
        $mchId       = trim((string) getSystemSetting('pay_wechat_mchid', ''));
        $apiV3Key    = trim((string) getSystemSetting('pay_wechat_api_v3_key', ''));
        $serialNo    = trim((string) getSystemSetting('pay_wechat_cert_serial_no', ''));
        $privateKey  = trim((string) getSystemSetting('pay_wechat_private_key', ''));
        $platformKey = trim((string) getSystemSetting('pay_wechat_platform_public_key', ''));
        $platformId  = trim((string) getSystemSetting('pay_wechat_platform_public_key_id', ''));

        $this->assertNotEmpty($mchId, '商户号 MCHID');
        $this->assertNotEmpty($apiV3Key, 'APIv3 密钥');
        $this->assertNotEmpty($serialNo, '商户证书序列号');
        $this->assertNotEmpty($privateKey, '商户 API 私钥');
        $this->assertNotEmpty($platformKey, 'V3 平台公钥');
        $this->assertNotEmpty($platformId, '平台公钥 ID');

        $privateKeyPath  = $this->resolveCertPath($privateKey);
        $platformKeyPath = $this->resolveCertPath($platformKey);

        return new PayApplication([
            'mch_id'           => $mchId,
            'secret_key'       => $apiV3Key,
            'certificate_serial_no' => $serialNo,
            'private_key'      => $privateKeyPath,
            'certificate'      => $platformKeyPath,
            'platform_certs'   => [$platformKeyPath],
            'public_key_id'    => $platformId,
            // 显式给空字符串避免 Merchant 构造缺键告警
            'v2_secret_key'    => '',
        ]);
    }

    /**
     * 当前 scene 对应的 AppID（h5 无 AppID 返回空串）
     */
    public function appIdOf(int $scene): string
    {
        return match ($scene) {
            PayScene::MINI => trim((string) getSystemSetting('wechat_mini_appid', '')),
            PayScene::OFFI => trim((string) getSystemSetting('wechat_offi_appid', '')),
            PayScene::H5   => '',
            default        => throw new BusinessException('支付场景不合法'),
        };
    }

    /**
     * 配置整体可用性自检（启动期 / 健康检查使用）
     *
     * @return array{ok:bool, missing:array<int,string>}
     */
    public function diagnose(): array
    {
        $missing = [];
        $checks = [
            '商户号 MCHID'        => 'pay_wechat_mchid',
            'APIv3 密钥'          => 'pay_wechat_api_v3_key',
            '商户证书序列号'      => 'pay_wechat_cert_serial_no',
            '商户 API 私钥'       => 'pay_wechat_private_key',
            'V3 平台公钥'         => 'pay_wechat_platform_public_key',
            '平台公钥 ID'         => 'pay_wechat_platform_public_key_id',
        ];
        foreach ($checks as $label => $key) {
            if (trim((string) getSystemSetting($key, '')) === '') {
                $missing[] = $label;
            }
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }

    private function assertNotEmpty(string $value, string $label): void
    {
        if ($value === '') {
            throw new BusinessException(sprintf(
                '%s 未配置，请在后台「设置 → 支付配置」中补全',
                $label
            ));
        }
    }

    /**
     * 把 mb_setting 中保存的相对路径解析为绝对路径
     *
     * 私有上传规则把证书统一落在 backend/storage/cert/，库里只存文件名或相对路径
     */
    private function resolveCertPath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        // 绝对路径直接返回
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
            $resolved = $path;
        } else {
            $resolved = rtrim((string) app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR
                . ltrim($path, '/\\');
        }
        if (!is_file($resolved) || !is_readable($resolved)) {
            throw new BusinessException(sprintf(
                '支付证书文件不可读：%s',
                $resolved
            ));
        }
        return $resolved;
    }
}
