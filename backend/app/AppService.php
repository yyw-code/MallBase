<?php
declare (strict_types = 1);

namespace app;

use mall_base\drivers\DriverManager;
use mall_base\drivers\sms\AliyunPnvsDriver;
use mall_base\drivers\sms\AliyunSmsDriver;
use mall_base\drivers\sms\MockSmsDriver;
use mall_base\drivers\upload\LocalUploadDriver;
use mall_base\drivers\upload\OssUploadDriver;
use mall_base\drivers\upload\CosUploadDriver;
use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 注册上传驱动
        DriverManager::register('upload', [
            'local' => LocalUploadDriver::class,
            'oss' => OssUploadDriver::class,
            'cos' => CosUploadDriver::class,
        ]);
        // 应用启动阶段不能访问系统设置，避免安装前或 CLI 启动时提前连接 DB/Redis。
        // UploadService 实际执行上传时会重新读取系统设置中的真实驱动。
        DriverManager::setDefault('upload', 'local');

        // 注册短信驱动(默认驱动由 SmsService 按场景绑定动态选择,这里仅注册可用集合)
        DriverManager::register('sms', [
            'mock'        => MockSmsDriver::class,
            'aliyun'      => AliyunSmsDriver::class,
            'aliyun_pnvs' => AliyunPnvsDriver::class,
        ]);
        DriverManager::setDefault('sms', 'mock');
    }

    public function boot()
    {
        // 服务启动
    }
}
