# 上传云存储配置

MallBase 后端上传服务通过统一驱动切换存储位置，当前支持本地存储、阿里云 OSS 和腾讯云 COS。后台、客户端上传接口会读取「默认上传驱动」并把文件写入对应存储。

如果要开发接入新的云存储服务商，请看 [新增云存储上传驱动开发指南](../upload-storage-driver-extension.md)。

## 适用场景

| 场景 | 推荐驱动 | 说明 |
|------|----------|------|
| 本地开发、单机部署 | 本地存储 | 文件写入 `backend/public/uploads`，依赖站点域名访问 |
| 已使用阿里云 | 阿里云 OSS | 使用 OSS Bucket、Endpoint 与访问凭证 |
| 已使用腾讯云 | 腾讯云 COS | 使用 COS Bucket、Region 与访问凭证 |

云存储上传是服务端代传：前端仍调用 MallBase 上传接口，后端再调用云存储 SDK 上传对象，当前不涉及前端直传或临时密钥。

## 配置入口

进入后台「系统设置 -> 上传配置」：

1. 在「基础上传」中把「默认上传驱动」切换为 `阿里云 OSS` 或 `腾讯云 COS`。
2. 进入对应驱动配置页，填写 Bucket、地域、访问凭证和访问域名。
3. 保存后重新上传一张测试图片，确认素材库或业务页面返回的 URL 指向目标云存储域名。

切换驱动后，新上传文件会写入新驱动；历史文件不会自动迁移。需要迁移历史素材时，使用后台素材迁移能力按批次处理。

## 腾讯云 COS

官方参考：[对象上传 - 腾讯云 COS](https://cloud.tencent.com/document/product/436/14104)。

MallBase 后端已引入 `qcloud/cos-sdk-v5`。如果是已部署环境更新代码后缺少 SDK，请在服务器执行：

```bash
composer install --working-dir backend
```

### 后台字段

| 字段 | 示例 | 说明 |
|------|------|------|
| SecretId | `AKID...` | 腾讯云 CAM 访问密钥 ID，建议使用子账号密钥 |
| SecretKey | `******` | 腾讯云 CAM 访问密钥 Key，不要提交到代码仓库 |
| Bucket | `examplebucket-1250000000` | COS SDK 使用完整 Bucket 名称，格式为 `bucketname-appid` |
| Region | `ap-shanghai` | Bucket 所在地域，可在 COS 控制台查看 |
| 访问域名 | `https://examplebucket-1250000000.cos.ap-shanghai.myqcloud.com` | 可填写 COS 默认域名，也可填写已绑定的 CDN/自定义域名 |

### 权限建议

生产环境建议使用 CAM 子账号或角色，授权范围限定在目标 Bucket 的对象上传、读取、删除与对象元信息读取。不要在文档、提交信息、脚本输出或截图中暴露 SecretId / SecretKey。

### 访问域名

如果 Bucket 允许公网读，可以填写 COS 默认域名：

```text
https://<bucket>.cos.<region>.myqcloud.com
```

如果前面接入 CDN 或自定义域名，填写 CDN/自定义域名即可。MallBase 会把对象路径拼接到该域名后面，例如：

```text
https://cdn.example.com/images/admin/2026/06/08/demo.jpg
```

## 阿里云 OSS

OSS 字段与旧版本保持一致：

| 字段 | 示例 | 说明 |
|------|------|------|
| AccessKeyId | `LTAI...` | 阿里云访问密钥 ID |
| AccessKeySecret | `******` | 阿里云访问密钥 Secret |
| Bucket | `mall-base` | OSS Bucket 名称 |
| Endpoint | `oss-cn-hangzhou.aliyuncs.com` | Bucket 所在地域 Endpoint |
| 访问域名 | `https://mall-base.oss-cn-hangzhou.aliyuncs.com` | 可填写 OSS 公网域名或 CDN 域名 |

## 旧环境升级

全新安装会直接带有 COS 可选项和正确占位说明。已部署环境需要同步执行本次升级 SQL，确保后台不再显示「待接入」：

```bash
mysql --default-character-set=utf8mb4 -h<HOST> -u<USER> -p <DB> < backend/install/data/upgrade/2026_06_08_upload_cos_available.sql
```

升级 SQL 是幂等的，可以重复执行。执行前建议先备份数据库，并确认当前环境已经更新到包含上传驱动配置的版本。

## 验证清单

1. `composer install --working-dir backend` 已完成，`backend/vendor/qcloud/cos-sdk-v5` 存在。
2. 后台「默认上传驱动」已切换到 `腾讯云 COS`。
3. `Bucket` 使用 `bucketname-appid` 格式，`Region` 与 COS 控制台一致。
4. `访问域名` 可以在浏览器访问，且与 Bucket 公开读或 CDN 配置一致。
5. 上传一张图片后，返回 URL 使用 COS/CDN 域名。
6. 删除素材时，对应云存储对象能被同步删除。

## 常见问题

| 问题 | 处理方式 |
|------|----------|
| 上传提示 `COS 配置缺失` | 检查 SecretId、SecretKey、Bucket、Region 是否已保存 |
| URL 域名不符合预期 | 检查「访问域名」是否填写为 COS 默认域名或 CDN 域名 |
| 图片上传成功但浏览器打不开 | 检查 Bucket 公网读、CDN 回源、对象 ACL 或自定义域名备案/证书 |
| SDK 类不存在 | 执行 `composer install --working-dir backend` 并重启后端服务 |
| 已上传的旧文件仍在本地 | 切换驱动只影响新文件，历史文件需要通过素材迁移单独处理 |
