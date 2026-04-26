<?php
declare (strict_types = 1);

namespace app;

use app\service\UploadService;
use mall_base\drivers\DriverManager;
use mall_base\drivers\sms\AliyunSmsDriver;
use mall_base\drivers\sms\MockSmsDriver;
use mall_base\drivers\upload\LocalUploadDriver;
use mall_base\drivers\upload\OssUploadDriver;
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
        ]);
        DriverManager::setDefault('upload', UploadService::getDriver());

        // 注册短信驱动
        DriverManager::register('sms', [
            'mock'   => MockSmsDriver::class,
            'aliyun' => AliyunSmsDriver::class,
        ]);
        DriverManager::setDefault('sms', (string) getSystemSetting('sms_driver', 'mock'));
    }

    public function boot()
    {
        // 服务启动
    }
}
