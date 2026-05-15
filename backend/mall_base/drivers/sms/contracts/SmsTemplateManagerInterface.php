<?php

declare(strict_types=1);

namespace mall_base\drivers\sms\contracts;

use mall_base\exception\SmsException;

/**
 * 短信模板/签名远端管理契约
 *
 * 实现方需要把"创建/修改/删除/查询模板与签名"封装成本地接口,
 * 屏蔽阿里云/腾讯云 SDK 的细节差异。
 *
 * 异常约定:
 *  - 全部抛出 SmsException(包含远端 code/message),不返回 false。
 *  - 调用方在 Service 层统一捕获并落库 audit_reason。
 */
interface SmsTemplateManagerInterface
{
    /**
     * 创建模板
     *
     * @param array{template_name:string, template_content:string, template_type:int, remark?:string} $data
     * @return array{template_code:string}
     * @throws SmsException
     */
    public function addTemplate(array $data): array;

    /**
     * 修改模板
     *
     * @param array{template_name:string, template_content:string, template_type:int, remark?:string} $data
     * @throws SmsException
     */
    public function modifyTemplate(string $templateCode, array $data): void;

    /**
     * 删除模板
     *
     * @throws SmsException
     */
    public function deleteTemplate(string $templateCode): void;

    /**
     * 查询单个模板状态
     *
     * @return array{template_code:string, template_name:string, template_content:string, template_type:int, audit_status:string, audit_reason:?string}
     * @throws SmsException
     */
    public function queryTemplate(string $templateCode): array;

    /**
     * 创建签名
     *
     * 阿里云硬性要求 SignFileList(资质证明文件 base64),否则报 SMS_SIGN_FILE_REQUIRED。
     *
     * @param array{
     *     sign_name:string,
     *     sign_source:int,
     *     sign_type:int,
     *     remark?:string,
     *     sign_files?:array<int, array{file_contents:string, file_suffix:string}>
     * } $data
     * @return array{sign_name:string}
     * @throws SmsException
     */
    public function addSign(array $data): array;

    /**
     * 删除签名
     *
     * @throws SmsException
     */
    public function deleteSign(string $signName): void;

    /**
     * 查询签名状态
     *
     * @return array{sign_name:string, audit_status:string, audit_reason:?string}
     * @throws SmsException
     */
    public function querySign(string $signName): array;
}
