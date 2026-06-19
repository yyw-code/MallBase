<?php

namespace mall_base\drivers\upload;

use OSS\OssClient;

/**
 * 阿里云 OSS 上传驱动
 * 
 * 功能说明：
 * - 实现阿里云 OSS 文件上传功能
 * - 继承 BaseUploadDriver，实现具体平台的文件上传逻辑
 * 
 * 使用示例：
 * ```php
 * $config = [
 *     'access_key_id' => 'your_access_key_id',
 *     'access_key_secret' => 'your_access_key_secret',
 *     'bucket' => 'your_bucket_name',
 *     'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
 *     'cdn_domain' => 'https://cdn.example.com', // 可选，CDN 域名
 * ];
 * 
 * $upload = new OssUploadDriver($config);
 * $url = $upload->upload($_FILES['file']['tmp_name'], 'images/test.jpg');
 * ```
 */
class OssUploadDriver extends BaseUploadDriver
{
    private const REQUIRED_CONFIG = [
        'access_key_id' => 'AccessKeyId',
        'access_key_secret' => 'AccessKeySecret',
        'bucket' => 'Bucket',
        'endpoint' => 'Endpoint',
    ];

    /**
     * OSS 客户端实例
     */
    protected ?OssClient $ossClient = null;

    /**
     * Bucket 名称
     */
    protected string $bucket;

    /**
     * 外网访问域名
     */
    protected string $domain;

    /**
     * 初始化
     */
    protected function init(): void
    {
        $this->assertRequiredConfig();

        $this->bucket = trim((string) $this->getConfig('bucket', ''));
        $this->domain = trim((string) (
            $this->getConfig('url_prefix', '')
            ?: $this->getConfig('domain', '')
            ?: $this->getConfig('cdn_domain', '')
        ));
        $this->ossClient = $this->createClient();
    }

    /**
     * 上传文件
     * 
     * @param string $filePath 本地文件路径
     * @param string $objectName 存储对象名称
     * @return string 返回文件访问 URL
     */
    public function upload(string $filePath, string $objectName): string
    {
        // 验证文件
        if (!$this->validateFile($filePath)) {
            throw new \Exception($this->getError());
        }

        try {
            $this->client()->uploadFile($this->bucket, $objectName, $filePath);

            // 返回文件 URL
            return $this->getUrl($objectName);
        } catch (\Throwable $e) {
            $this->setError('上传失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 下载 OSS 对象到本地路径。
     *
     * @param string $objectName 存储对象名称
     * @param string $targetPath 本地目标路径
     * @return bool
     */
    public function download(string $objectName, string $targetPath): bool
    {
        try {
            $directory = dirname($targetPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $this->client()->getObject($this->bucket, ltrim($objectName, '/'), [
                OssClient::OSS_FILE_DOWNLOAD => $targetPath,
            ]);

            return is_file($targetPath);
        } catch (\Throwable $e) {
            $this->setError('下载失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 删除文件
     * 
     * @param string $objectName 存储对象名称
     * @return bool
     */
    public function delete(string $objectName): bool
    {
        try {
            $this->client()->deleteObject($this->bucket, $objectName);
            return true;
        } catch (\Throwable $e) {
            $this->setError('删除失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取文件 URL
     * 
     * @param string $objectName 存储对象名称
     * @return string
     */
    public function getUrl(string $objectName): string
    {
        // 使用后台 UploadOss.oss_url_prefix，允许配置为 OSS 公网域名或 CDN 域名
        if ($this->domain) {
            return rtrim($this->domain, '/') . '/' . ltrim($objectName, '/');
        }

        // 默认使用 Bucket + Endpoint
        return 'https://' . $this->bucket . '.' . $this->getConfig('endpoint', 'oss.aliyuncs.com') . '/' . ltrim($objectName, '/');
    }

    /**
     * 检查文件是否存在
     * 
     * @param string $objectName 存储对象名称
     * @return bool
     */
    public function exists(string $objectName): bool
    {
        try {
            return (bool) $this->client()->doesObjectExist($this->bucket, $objectName);
        } catch (\Throwable $e) {
            $this->setError('检查失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取文件信息
     * 
     * @param string $objectName 存储对象名称
     * @return array|null
     */
    public function getFileInfo(string $objectName): ?array
    {
        try {
            $meta = $this->client()->getObjectMeta($this->bucket, $objectName) ?? [];

            return [
                'name' => basename($objectName),
                'path' => $this->getPath($objectName),
                'url' => $this->getUrl($objectName),
                'full_url' => $this->getFullUrl($objectName),
                'size' => (int) ($meta['content-length'] ?? $meta['Content-Length'] ?? 0),
                'mime' => (string) ($meta['content-type'] ?? $meta['Content-Type'] ?? ''),
                'modified' => $this->normalizeModifiedTime(
                    $meta['last-modified'] ?? $meta['Last-Modified'] ?? ''
                ),
            ];
        } catch (\Throwable $e) {
            $this->setError('获取文件信息失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取相对路径
     */
    public function getPath(string $objectName): string
    {
        return ltrim($objectName, '/');
    }

    /**
     * 获取完整 URL
     */
    public function getFullUrl(string $objectName): string
    {
        return $this->getUrl($objectName);
    }

    /**
     * 批量上传文件
     * 
     * @param array $files 文件列表 [['path' => '/path/to/file', 'object' => 'oss/object/name']]
     * @return array 返回上传结果列表
     */
    public function batchUpload(array $files): array
    {
        $results = [];

        foreach ($files as $file) {
            try {
                $url = $this->upload($file['path'], $file['object']);
                $results[] = [
                    'object' => $file['object'],
                    'url' => $url,
                    'success' => true,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'object' => $file['object'],
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return $results;
    }

    private function assertRequiredConfig(): void
    {
        $missing = [];
        foreach (self::REQUIRED_CONFIG as $key => $label) {
            $value = trim((string) $this->getConfig($key, ''));
            if ($value === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('OSS 配置缺失: ' . implode('、', $missing));
        }
    }

    protected function createClient(): OssClient
    {
        return new OssClient(
            trim((string) $this->getConfig('access_key_id', '')),
            trim((string) $this->getConfig('access_key_secret', '')),
            trim((string) $this->getConfig('endpoint', ''))
        );
    }

    private function client(): OssClient
    {
        if ($this->ossClient === null) {
            $this->ossClient = $this->createClient();
        }

        return $this->ossClient;
    }

    private function normalizeModifiedTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
