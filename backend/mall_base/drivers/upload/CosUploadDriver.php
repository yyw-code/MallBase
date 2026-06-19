<?php

namespace mall_base\drivers\upload;

/**
 * 腾讯云 COS 上传驱动。
 */
class CosUploadDriver extends BaseUploadDriver
{
    private const REQUIRED_CONFIG = [
        'secret_id' => 'SecretId',
        'secret_key' => 'SecretKey',
        'bucket' => 'Bucket',
        'region' => 'Region',
    ];

    private ?object $cosClient = null;

    private string $bucket = '';

    private string $domain = '';

    protected function init(): void
    {
        $this->assertSdkInstalled();
        $this->assertRequiredConfig();

        $this->bucket = trim((string) $this->getConfig('bucket', ''));
        $this->domain = trim((string) (
            $this->getConfig('url_prefix', '')
            ?: $this->getConfig('domain', '')
            ?: $this->getConfig('cdn_domain', '')
        ));
        $this->cosClient = $this->createClient();
    }

    public function upload(string $filePath, string $objectName): string
    {
        if (!$this->validateFile($filePath)) {
            throw new \Exception($this->getError());
        }

        $body = fopen($filePath, 'rb');
        if ($body === false) {
            throw new \Exception('文件不可读');
        }

        try {
            $this->client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($objectName, '/'),
                'Body' => $body,
            ]);

            return $this->getUrl($objectName);
        } catch (\Throwable $e) {
            $this->setError('上传失败: ' . $e->getMessage());
            throw $e;
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    public function download(string $objectName, string $targetPath): bool
    {
        try {
            $directory = dirname($targetPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($objectName, '/'),
                'SaveAs' => $targetPath,
            ]);

            return is_file($targetPath);
        } catch (\Throwable $e) {
            $this->setError('下载失败: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(string $objectName): bool
    {
        try {
            $this->client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($objectName, '/'),
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->setError('删除失败: ' . $e->getMessage());
            return false;
        }
    }

    public function getUrl(string $objectName): string
    {
        if ($this->domain !== '') {
            return rtrim($this->domain, '/') . '/' . ltrim($objectName, '/');
        }

        return sprintf(
            'https://%s.cos.%s.myqcloud.com/%s',
            $this->bucket,
            trim((string) $this->getConfig('region', '')),
            ltrim($objectName, '/')
        );
    }

    public function exists(string $objectName): bool
    {
        try {
            $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($objectName, '/'),
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->setError('检查失败: ' . $e->getMessage());
            return false;
        }
    }

    public function getFileInfo(string $objectName): ?array
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($objectName, '/'),
            ]);
            $meta = is_object($result) && method_exists($result, 'toArray')
                ? $result->toArray()
                : (array) $result;

            return [
                'name' => basename($objectName),
                'path' => $this->getPath($objectName),
                'url' => $this->getUrl($objectName),
                'full_url' => $this->getFullUrl($objectName),
                'size' => (int) ($meta['ContentLength'] ?? $meta['Content-Length'] ?? $meta['content-length'] ?? 0),
                'mime' => (string) ($meta['ContentType'] ?? $meta['Content-Type'] ?? $meta['content-type'] ?? ''),
                'modified' => $this->normalizeModifiedTime($meta['LastModified'] ?? $meta['Last-Modified'] ?? ''),
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

    private function assertSdkInstalled(): void
    {
        if (!class_exists('\\Qcloud\\Cos\\Client')) {
            throw new \InvalidArgumentException('COS SDK 未安装，请执行 composer require qcloud/cos-sdk-v5');
        }
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
            throw new \InvalidArgumentException('COS 配置缺失: ' . implode('、', $missing));
        }
    }

    protected function createClient(): object
    {
        $clientClass = '\\Qcloud\\Cos\\Client';
        return new $clientClass([
            'region' => trim((string) $this->getConfig('region', '')),
            'scheme' => 'https',
            'credentials' => [
                'secretId' => trim((string) $this->getConfig('secret_id', '')),
                'secretKey' => trim((string) $this->getConfig('secret_key', '')),
            ],
        ]);
    }

    private function client(): object
    {
        if ($this->cosClient === null) {
            $this->cosClient = $this->createClient();
        }

        return $this->cosClient;
    }

    private function normalizeModifiedTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
    }
}
