# 新增云存储上传驱动开发指南

本文说明 MallBase 要新增一个云存储上传驱动时，需要改哪些代码、配置、测试和文档。只是在后台启用已有的本地存储、阿里云 OSS、腾讯云 COS 时，请看 [上传云存储配置](./install/cloud-storage-upload.md)。

下文用 `qiniu` 作为示例驱动名，实际接入时替换为目标服务商的英文标识。驱动名建议使用小写字母和下划线，例如 `qiniu`、`huawei_obs`。

## 接入范围

新增云存储不是只加一个 Driver 类，通常要覆盖这些层：

| 层级 | 必改 | 说明 |
|------|------|------|
| Composer 依赖 | 按需 | 如果服务商有 PHP SDK，需要加入 `backend/composer.json` |
| 上传驱动 | 是 | 新增 `backend/mall_base/drivers/upload/<Provider>UploadDriver.php` |
| 驱动注册 | 是 | 在 `backend/app/AppService.php` 注册到 `DriverManager` |
| 上传业务服务 | 是 | 在 `backend/app/service/UploadService.php` 加标签、域名、白名单、配置分组映射 |
| 系统设置 seed | 是 | 在 `backend/install/data/schema/03_mb_setting.sql` 增加驱动选项和配置项 |
| 旧环境升级 SQL | 是 | 在 `backend/install/data/upgrade/` 补幂等 SQL，供已部署环境手动执行 |
| 素材 URL 解析 | 需要素材库时 | 在 `AssetResolver` 中补默认访问域名 |
| 素材删除/迁移 | 需要素材库迁移时 | 在素材管理、迁移服务、迁移 Job 中补驱动映射 |
| 后台页面 | 视情况 | 动态设置页、素材迁移页如有固定驱动列表，需要同步 |
| 测试 | 是 | 至少新增驱动单元测试，并跑后端测试基线 |
| 文档 | 是 | 更新配置文档和本文相关说明 |

## 1. 增加 SDK 依赖

如果目标服务商提供官方 PHP SDK，先加入 `backend/composer.json`：

```json
{
  "require": {
    "vendor/storage-sdk": "^1.0"
  }
}
```

然后更新依赖：

```bash
composer update vendor/storage-sdk --working-dir backend
```

如果不希望立即更新整个 lock 文件，只安装到本地验证，也可以先执行：

```bash
composer require vendor/storage-sdk --working-dir backend
```

## 2. 新增上传驱动类

在 `backend/mall_base/drivers/upload/` 下新增驱动类，例如：

```text
backend/mall_base/drivers/upload/QiniuUploadDriver.php
```

驱动必须继承 `BaseUploadDriver`，并实现统一接口：

```php
<?php

namespace mall_base\drivers\upload;

class QiniuUploadDriver extends BaseUploadDriver
{
    private const REQUIRED_CONFIG = [
        'access_key' => 'AccessKey',
        'secret_key' => 'SecretKey',
        'bucket' => 'Bucket',
        'url_prefix' => '访问域名',
    ];

    private string $bucket = '';
    private string $domain = '';
    private ?object $client = null;

    protected function init(): void
    {
        $this->assertRequiredConfig();

        $this->bucket = trim((string) $this->getConfig('bucket', ''));
        $this->domain = trim((string) (
            $this->getConfig('url_prefix', '')
            ?: $this->getConfig('domain', '')
            ?: $this->getConfig('cdn_domain', '')
        ));
        $this->client = $this->createClient();
    }

    public function upload(string $filePath, string $objectName): string
    {
        if (!$this->validateFile($filePath)) {
            throw new \Exception($this->getError());
        }

        try {
            // 调用服务商 SDK 上传对象。
            // Key 统一使用 ltrim($objectName, '/')，避免对象名前带斜杠。
            $this->client()->putObject($this->bucket, ltrim($objectName, '/'), $filePath);

            return $this->getUrl($objectName);
        } catch (\Throwable $e) {
            $this->setError('上传失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function download(string $objectName, string $targetPath): bool
    {
        try {
            $directory = dirname($targetPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // 调用服务商 SDK 下载对象到 $targetPath。
            return is_file($targetPath);
        } catch (\Throwable $e) {
            $this->setError('下载失败: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(string $objectName): bool
    {
        try {
            // 调用服务商 SDK 删除对象。
            return true;
        } catch (\Throwable $e) {
            $this->setError('删除失败: ' . $e->getMessage());
            return false;
        }
    }

    public function getUrl(string $objectName): string
    {
        return rtrim($this->domain, '/') . '/' . ltrim($objectName, '/');
    }

    public function exists(string $objectName): bool
    {
        try {
            // 调用服务商 SDK 检查对象是否存在。
            return true;
        } catch (\Throwable $e) {
            $this->setError('检查失败: ' . $e->getMessage());
            return false;
        }
    }

    public function getFileInfo(string $objectName): ?array
    {
        try {
            // 从服务商 SDK 的对象元信息中取 size、mime、modified。
            return [
                'name' => basename($objectName),
                'path' => $this->getPath($objectName),
                'url' => $this->getUrl($objectName),
                'full_url' => $this->getFullUrl($objectName),
                'size' => 0,
                'mime' => '',
                'modified' => '',
            ];
        } catch (\Throwable $e) {
            $this->setError('获取文件信息失败: ' . $e->getMessage());
            return null;
        }
    }

    public function getPath(string $objectName): string
    {
        return ltrim($objectName, '/');
    }

    public function getFullUrl(string $objectName): string
    {
        return $this->getUrl($objectName);
    }

    protected function createClient(): object
    {
        // 返回服务商 SDK client；保持 protected，便于单元测试替换 fake client。
        return new \stdClass();
    }

    private function client(): object
    {
        if ($this->client === null) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    private function assertRequiredConfig(): void
    {
        $missing = [];
        foreach (self::REQUIRED_CONFIG as $key => $label) {
            if (trim((string) $this->getConfig($key, '')) === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('七牛云配置缺失: ' . implode('、', $missing));
        }
    }
}
```

驱动层只做底层 SDK/API 调用，不写素材分类、上传规则、业务归属、权限判断等业务逻辑。

## 3. 注册驱动

修改 `backend/app/AppService.php`：

```php
use mall_base\drivers\upload\QiniuUploadDriver;

DriverManager::register('upload', [
    'local' => LocalUploadDriver::class,
    'oss' => OssUploadDriver::class,
    'cos' => CosUploadDriver::class,
    'qiniu' => QiniuUploadDriver::class,
]);
```

`DriverManager::setDefault('upload', 'local')` 保持 `local` 即可。真实默认驱动由后台系统设置 `upload_driver` 决定，应用启动阶段不要读取数据库配置。

## 4. 更新上传服务

修改 `backend/app/service/UploadService.php`。

### 4.1 驱动标签

```php
private static array $uploadDriverLabels = [
    'local' => '本地',
    'oss' => 'OSS',
    'cos' => 'COS',
    'qiniu' => '七牛云',
];
```

### 4.2 上传域名

```php
$domain = match ($driver) {
    'local' => (string) getSystemSetting('local_base_url', ''),
    'oss' => (string) getSystemSetting('oss_url_prefix', ''),
    'cos' => (string) getSystemSetting('cos_url_prefix', ''),
    'qiniu' => (string) getSystemSetting('qiniu_url_prefix', ''),
    default => '',
};
```

### 4.3 驱动白名单和配置分组

```php
if (!in_array($driverName, ['local', 'oss', 'cos', 'qiniu'], true)) {
    throw new BusinessException('当前上传驱动暂不可用，请切换为已支持的上传驱动');
}

$groupMap = [
    'local' => 'UploadLocal',
    'oss' => 'UploadOss',
    'cos' => 'UploadCos',
    'qiniu' => 'UploadQiniu',
];
```

上传服务获取驱动时应继续使用不缓存实例的方式：

```php
return DriverManager::driver('upload', $driverName, $driverConfig, false);
```

这样后台切换云存储凭证、Bucket 或访问域名后，Swoole 常驻进程不会沿用旧客户端。

## 5. 更新系统设置 seed

修改 `backend/install/data/schema/03_mb_setting.sql`。

### 5.1 增加设置分组

在上传配置分组下增加服务商配置页：

```sql
(1025, 102, 0, '七牛云存储', 'UploadQiniu', NULL, '七牛云 Bucket、区域与访问凭证', 50, 'page', 1)
```

分组 ID 要避开现有 ID。新增 ID 后同步检查安装测试中是否有固定分组断言。

### 5.2 更新默认上传驱动选项

```sql
(1021, '默认上传驱动', 'upload_driver', 'local', 'select', '[{"label":"本地存储","value":"local"},{"label":"阿里云 OSS","value":"oss"},{"label":"腾讯云 COS","value":"cos"},{"label":"七牛云","value":"qiniu"}]', '[{"type":"required","message":"不能为空"}]', NULL, NULL, 10),
```

### 5.3 增加服务商配置项

```sql
-- 设置项：1025 UploadQiniu 七牛云（启用该驱动必须填写）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1025, 'AccessKey', 'qiniu_access_key', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 10),
(1025, 'SecretKey', 'qiniu_secret_key', '', 'password', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 20),
(1025, 'Bucket', 'qiniu_bucket', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 30),
(1025, '访问域名', 'qiniu_url_prefix', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', 'https://cdn.example.com', NULL, 40);
```

配置项 code 建议统一使用 `<driver>_` 前缀。`UploadService` 会把 `qiniu_access_key` 归一化为驱动配置里的 `access_key`。

## 6. 补旧环境升级 SQL

凡是修改 `schema/*.sql` seed，都要在 `backend/install/data/upgrade/` 下补幂等升级 SQL。该目录被 git 忽略，用于部署环境手动执行。

示例：

```sql
-- MallBase 升级：新增七牛云上传驱动配置。
-- 本脚本可重复执行。

INSERT INTO `mb_setting_group` (`id`, `pid`, `is_system`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(1025, 102, 0, '七牛云存储', 'UploadQiniu', NULL, '七牛云 Bucket、区域与访问凭证', 50, 'page', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`);

UPDATE `mb_setting`
SET `options` = '[{"label":"本地存储","value":"local"},{"label":"阿里云 OSS","value":"oss"},{"label":"腾讯云 COS","value":"cos"},{"label":"七牛云","value":"qiniu"}]'
WHERE `code` = 'upload_driver';
```

## 7. 更新素材解析与迁移

如果新驱动只用于新上传，并且素材表会保存 `url_prefix`，基础上传可以先跑通。若要完整支持素材解析、删除和迁移，还需要同步以下位置。

### 7.1 素材存储位置常量

修改 `backend/app/model/upload/UploadAssetLocation.php`：

```php
public const DRIVER_QINIU = 'qiniu';
```

### 7.2 素材 URL 默认前缀

修改 `backend/app/service/upload/AssetResolver.php`：

```php
UploadAssetLocation::DRIVER_QINIU => rtrim((string) getSystemSetting('qiniu_url_prefix', ''), '/'),
```

### 7.3 素材删除

修改 `backend/app/service/admin/upload/UploadAssetAdminService.php` 的 `driverConfig()`：

```php
UploadAssetLocation::DRIVER_QINIU => 'UploadQiniu',
```

### 7.4 素材迁移

需要同步这些位置：

| 文件 | 修改点 |
|------|--------|
| `backend/app/service/admin/upload/UploadAssetMigrationAdminService.php` | `DRIVERS`、`driverLabel()` |
| `backend/app/job/UploadAssetMigrationJob.php` | 注释、`driverConfig()` 的分组映射 |
| `frontend/admin/apps/web-antd/src/views/upload/asset/migration/index.vue` | `driverOptions` |

如果暂不支持迁移到该云存储，文档和后台页面要明确不提供迁移入口，避免用户创建无法执行的迁移任务。

## 8. 更新后台设置页兼容逻辑

后台设置页是后端驱动表单，但 `frontend/admin/apps/web-antd/src/views/settings/dynamic-form/index.vue` 里有上传相关配置识别逻辑。新增驱动配置前缀后，需要补充：

```ts
code.startsWith('qiniu_')
```

上传组件的驱动字典来自后端 `uploadOptions`，通常不需要在组件里写死新驱动。

## 9. 添加测试

至少补一个驱动单元测试：

```text
backend/tests/Unit/Upload/QiniuUploadDriverTest.php
```

覆盖点：

1. 缺少必填配置时抛出明确异常。
2. `upload()` 调用 SDK 上传并返回正确 URL。
3. `download()` 能保存到目标路径。
4. `delete()`、`exists()`、`getFileInfo()` 行为和错误处理符合统一接口。
5. `getUrl()` 支持 `url_prefix`，对象名前后斜杠处理一致。

单元测试不要连接真实云存储。驱动里的 `createClient()` 保持 `protected`，测试类继承驱动并返回 fake client。

如果改了上传配置接口、迁移服务或前端页面，按影响范围补充对应测试：

```bash
composer --working-dir backend test -- --filter 'QiniuUploadDriverTest|UploadConfigOptionsApiTest'
composer --working-dir backend test
pnpm --dir frontend/admin run test:e2e
```

## 10. 更新文档

新增驱动后至少更新：

| 文档 | 修改内容 |
|------|----------|
| `docs/install/cloud-storage-upload.md` | 增加新驱动的后台字段、权限建议、验证清单 |
| `docs/upload-storage-driver-extension.md` | 如接入流程有新约束，同步补充 |
| `README.md` / `docs/index.md` | 若新增文档，必须同步索引 |

## 完成自检

- [ ] 驱动类放在 `backend/mall_base/drivers/upload/`，只做 SDK/API 调用。
- [ ] `AppService` 已注册新驱动。
- [ ] `UploadService` 已补标签、域名、白名单、配置分组。
- [ ] `03_mb_setting.sql` 已补全新安装 seed。
- [ ] `backend/install/data/upgrade/` 已补旧环境幂等升级 SQL。
- [ ] 素材解析、删除、迁移和后台迁移页已按支持范围同步。
- [ ] 后台动态设置页已识别新配置前缀。
- [ ] 已新增 fake client 单元测试。
- [ ] 已执行后端测试基线，并记录命令和结果。
- [ ] 配置文档已同步，公开文案没有泄露密钥或包含不适合开源传播的措辞。
