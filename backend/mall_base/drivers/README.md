# 驱动系统使用说明

## 概述

驱动系统提供了一个统一的接口来管理多种平台的服务，支持短信发送、文件上传等功能。

## 架构

```
mall_base/
├── base/
│   └── BaseDriver.php          # 驱动基类
├── exception/
│   └── DriverException.php     # 驱动异常类
└── drivers/
    ├── DriverManager.php        # 驱动管理器
    ├── sms/                     # 短信驱动
    │   ├── BaseSmsDriver.php    # 短信驱动基类
    │   └── AliyunSmsDriver.php  # 阿里云短信驱动
    └── upload/                  # 文件上传驱动
        ├── BaseUploadDriver.php # 上传驱动基类
        └── OssUploadDriver.php  # OSS 上传驱动
```

## 核心组件

### 1. BaseDriver (驱动基类)

提供驱动的通用功能：
- 配置管理
- 错误处理
- 日志记录

### 2. DriverManager (驱动管理器)

管理所有驱动的注册和获取：
- 注册驱动
- 获取驱动实例
- 驱动实例缓存
- 默认驱动设置

## 使用示例

### 短信驱动使用

#### 1. 注册驱动

```php
use mall_base\drivers\DriverManager;
use mall_base\drivers\sms\AliyunSmsDriver;
use mall_base\drivers\sms\TencentSmsDriver;

// 注册短信驱动
DriverManager::register('sms', [
    'aliyun' => AliyunSmsDriver::class,
    'tencent' => TencentSmsDriver::class,
]);

// 设置默认驱动
DriverManager::setDefault('sms', 'aliyun');
```

#### 2. 使用驱动

```php
// 方式一：使用默认驱动
$sms = DriverManager::driver('sms');
$sms->send('13800138000', '123456');

// 方式二：指定驱动
$config = [
    'access_key_id' => 'your_access_key_id',
    'access_key_secret' => 'your_access_key_secret',
    'sign_name' => 'your_sign_name',
    'template_code' => 'your_template_code',
];

$sms = DriverManager::driver('sms', 'aliyun', $config);
$result = $sms->send('13800138000', '123456');

if (!$result) {
    echo $sms->getError();
}
```

#### 3. 直接实例化

```php
$config = [
    'access_key_id' => 'your_access_key_id',
    'access_key_secret' => 'your_access_key_secret',
    'sign_name' => 'your_sign_name',
    'template_code' => 'your_template_code',
];

$sms = new AliyunSmsDriver($config);
$sms->send('13800138000', '123456');
```

### 文件上传驱动使用

#### 1. 注册驱动

```php
use mall_base\drivers\DriverManager;
use mall_base\drivers\upload\OssUploadDriver;
use mall_base\drivers\upload\CosUploadDriver;

// 注册上传驱动
DriverManager::register('upload', [
    'oss' => OssUploadDriver::class,
    'cos' => CosUploadDriver::class,
]);

// 设置默认驱动
DriverManager::setDefault('upload', 'oss');
```

#### 2. 使用驱动

```php
// 方式一：使用默认驱动
$upload = DriverManager::driver('upload');
$url = $upload->upload($_FILES['file']['tmp_name'], 'images/test.jpg');

// 方式二：指定驱动
$config = [
    'access_key_id' => 'your_access_key_id',
    'access_key_secret' => 'your_access_key_secret',
    'bucket' => 'your_bucket_name',
    'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
    'cdn_domain' => 'https://cdn.example.com',
];

$upload = DriverManager::driver('upload', 'oss', $config);
$url = $upload->upload($_FILES['file']['tmp_name'], 'images/test.jpg');

if ($upload->hasError()) {
    echo $upload->getError();
}
```

#### 3. 直接实例化

```php
$config = [
    'access_key_id' => 'your_access_key_id',
    'access_key_secret' => 'your_access_key_secret',
    'bucket' => 'your_bucket_name',
    'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
    'cdn_domain' => 'https://cdn.example.com',
];

$upload = new OssUploadDriver($config);

// 上传文件
$url = $upload->upload($_FILES['file']['tmp_name'], 'images/test.jpg');

// 删除文件
$upload->delete('images/test.jpg');

// 获取文件 URL
$url = $upload->getUrl('images/test.jpg');

// 检查文件是否存在
$exists = $upload->exists('images/test.jpg');

// 获取文件信息
$info = $upload->getFileInfo('images/test.jpg');
```

### 在 Service 中使用

```php
namespace app\service;

use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;

class UserService extends BaseService
{
    public function __construct()
    {
        parent::__construct();
        
        // 初始化配置
        $this->initDrivers();
    }
    
    protected function initDrivers(): void
    {
        // 配置短信驱动
        DriverManager::register('sms', [
            'aliyun' => \mall_base\drivers\sms\AliyunSmsDriver::class,
        ]);
        DriverManager::setDefault('sms', 'aliyun');
        
        // 配置上传驱动
        DriverManager::register('upload', [
            'oss' => \mall_base\drivers\upload\OssUploadDriver::class,
        ]);
        DriverManager::setDefault('upload', 'oss');
    }
    
    public function sendSms(string $phone, string $code): bool
    {
        $config = [
            'access_key_id' => config('sms.aliyun.access_key_id'),
            'access_key_secret' => config('sms.aliyun.access_key_secret'),
            'sign_name' => config('sms.aliyun.sign_name'),
            'template_code' => config('sms.aliyun.template_code'),
        ];
        
        $sms = DriverManager::driver('sms', 'aliyun', $config);
        return $sms->send($phone, $code);
    }
    
    public function uploadAvatar(string $filePath): string
    {
        $config = [
            'access_key_id' => config('oss.access_key_id'),
            'access_key_secret' => config('oss.access_key_secret'),
            'bucket' => config('oss.bucket'),
            'endpoint' => config('oss.endpoint'),
            'cdn_domain' => config('oss.cdn_domain'),
        ];
        
        $upload = DriverManager::driver('upload', 'oss', $config);
        
        // 生成文件名
        $objectName = 'avatars/' . date('Y/m/d') . '/' . md5(uniqid()) . '.jpg';
        
        return $upload->upload($filePath, $objectName);
    }
}
```

## API 说明

### DriverManager

#### register(string $type, array $drivers)
注册驱动映射

```php
DriverManager::register('sms', [
    'aliyun' => AliyunSmsDriver::class,
]);
```

#### setDefault(string $type, string $name)
设置默认驱动

```php
DriverManager::setDefault('sms', 'aliyun');
```

#### driver(string $type, ?string $name, array $config, bool $cache)
获取驱动实例

```php
$sms = DriverManager::driver('sms', 'aliyun', $config, true);
```

#### create(string $type, ?string $name, array $config)
创建新的驱动实例（不使用缓存）

```php
$sms = DriverManager::create('sms', 'aliyun', $config);
```

#### clearCache(?string $type, ?string $name)
清除驱动缓存

```php
// 清除所有缓存
DriverManager::clearCache();

// 清除指定类型的所有驱动缓存
DriverManager::clearCache('sms');

// 清除指定驱动的缓存
DriverManager::clearCache('sms', 'aliyun');
```

### BaseDriver

#### getConfig(string $key, $default)
获取配置项

```php
$value = $this->getConfig('access_key_id', 'default_value');
```

#### setError(string $message, ?int $code)
设置错误信息

```php
$this->setError('发送失败', 500);
```

#### getError()
获取错误信息

```php
$error = $this->getError();
```

#### hasError()
是否有错误

```php
if ($this->hasError()) {
    echo $this->getError();
}
```

## 扩展新驱动

### 1. 创建驱动基类

```php
namespace mall_base\drivers\payment;

use mall_base\base\BaseDriver;

abstract class BasePaymentDriver extends BaseDriver
{
    abstract public function pay(array $order): string;
    abstract public function query(string $orderNo): array;
    abstract public function refund(string $orderNo, float $amount): bool;
}
```

### 2. 实现具体驱动

```php
namespace mall_base\drivers\payment;

class AlipayDriver extends BasePaymentDriver
{
    protected function init(): void
    {
        // 初始化配置
    }
    
    public function pay(array $order): string
    {
        // 实现支付宝支付逻辑
    }
    
    public function query(string $orderNo): array
    {
        // 实现订单查询逻辑
    }
    
    public function refund(string $orderNo, float $amount): bool
    {
        // 实现退款逻辑
    }
}
```

### 3. 注册并使用

```php
DriverManager::register('payment', [
    'alipay' => AlipayDriver::class,
    'wechat' => WechatPayDriver::class,
]);

$payment = DriverManager::driver('payment', 'alipay', $config);
$payment->pay(['order_no' => '123456', 'amount' => 100]);
```

## 注意事项

1. **配置管理**：建议将驱动配置放在配置文件中，不要硬编码
2. **错误处理**：使用 `hasError()` 和 `getError()` 处理驱动错误
3. **缓存机制**：默认启用缓存，如需每次创建新实例使用 `create()` 方法
4. **线程安全**：在 Swoole 等协程环境下，注意共享状态的正确处理
5. **依赖注入**：确保已安装对应平台的 SDK（如阿里云 OSS SDK）

## 配置文件示例

```php
// config/sms.php
return [
    'default' => 'aliyun',
    'drivers' => [
        'aliyun' => [
            'access_key_id' => env('SMS_ALIYUN_ACCESS_KEY_ID'),
            'access_key_secret' => env('SMS_ALIYUN_ACCESS_KEY_SECRET'),
            'sign_name' => env('SMS_ALIYUN_SIGN_NAME'),
            'template_code' => env('SMS_ALIYUN_TEMPLATE_CODE'),
        ],
        'tencent' => [
            'secret_id' => env('SMS_TENCENT_SECRET_ID'),
            'secret_key' => env('SMS_TENCENT_SECRET_KEY'),
            'sign_name' => env('SMS_TENCENT_SIGN_NAME'),
            'template_id' => env('SMS_TENCENT_TEMPLATE_ID'),
        ],
    ],
];

// config/upload.php
return [
    'default' => 'oss',
    'drivers' => [
        'oss' => [
            'access_key_id' => env('OSS_ACCESS_KEY_ID'),
            'access_key_secret' => env('OSS_ACCESS_KEY_SECRET'),
            'bucket' => env('OSS_BUCKET'),
            'endpoint' => env('OSS_ENDPOINT'),
            'cdn_domain' => env('OSS_CDN_DOMAIN'),
        ],
        'cos' => [
            'secret_id' => env('COS_SECRET_ID'),
            'secret_key' => env('COS_SECRET_KEY'),
            'bucket' => env('COS_BUCKET'),
            'region' => env('COS_REGION'),
            'cdn_domain' => env('COS_CDN_DOMAIN'),
        ],
    ],
];
