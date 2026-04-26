<?php

namespace mall_base\drivers\upload;

/**
 * 本地文件上传驱动
 *
 * 功能说明：
 * - 实现本地文件系统的文件上传
 * - 支持文件按日期分组存储
 * - 自动创建目录结构
 *
 * 使用示例：
 * ```php
 * $upload = new LocalUploadDriver([
 *     'rootPath' => './uploads',
 *     'urlPrefix' => 'https://example.com/uploads'
 * ]);
 * $url = $upload->upload('/tmp/test.jpg', 'images/test.jpg');
 * ```
 */
class LocalUploadDriver extends BaseUploadDriver
{
    /**
     * 根路径
     */
    private string $rootPath;

    /**
     * URL前缀
     */
    private string $urlPrefix;

    /**
     * 完整的基础URL
     */
    private string $baseUrl;

    /**
     * 构造函数
     *
     * @param array $config 配置信息
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $rootPath = $config['root_path'] ?? 'uploads';
        $this->rootPath = str_starts_with($rootPath, '/') ? $rootPath : public_path() . $rootPath;
        $this->urlPrefix = $config['url_prefix'] ?? '/uploads';
        $this->baseUrl = $config['base_url'] ?? '';

        // 确保根路径存在
        if (!is_dir($this->rootPath)) {
            mkdir($this->rootPath, 0755, true);
        }
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

        // 生成完整存储路径
        $targetPath = $this->getFullPath($objectName);

        // 确保目录存在
        $directory = dirname($targetPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // 移动文件
        if (!move_uploaded_file($filePath, $targetPath)) {
            // 如果不是上传文件，尝试直接复制
            if (!copy($filePath, $targetPath)) {
                throw new \Exception('文件移动失败');
            }
        }

        // 设置文件权限
        chmod($targetPath, 0644);

        // 返回访问URL
        return $this->getUrl($objectName);
    }

    /**
     * 删除文件
     *
     * @param string $objectName 存储对象名称
     * @return bool
     */
    public function delete(string $objectName): bool
    {
        $filePath = $this->getFullPath($objectName);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    /**
     * 获取文件 URL
     *
     * @param string $objectName 存储对象名称
     * @return string
     */
    public function getUrl(string $objectName): string
    {
        return rtrim($this->urlPrefix, '/') . '/' . ltrim($objectName, '/');
    }

    /**
     * 获取相对路径
     *
     * @param string $objectName 存储对象名称
     * @return string
     */
    public function getPath(string $objectName): string
    {
        return ltrim($objectName, '/');
    }

    /**
     * 获取完整URL
     *
     * @param string $objectName 存储对象名称
     * @return string
     */
    public function getFullUrl(string $objectName): string
    {
        $full_url = $this->getUrl($objectName);
        if ($this->baseUrl) {
            return rtrim($this->baseUrl, '/') . '/' . ltrim($full_url, '/');
        }
        return $full_url;
    }

    /**
     * 检查文件是否存在
     *
     * @param string $objectName 存储对象名称
     * @return bool
     */
    public function exists(string $objectName): bool
    {
        return file_exists($this->getFullPath($objectName));
    }

    /**
     * 获取文件信息
     *
     * @param string $objectName 存储对象名称
     * @return array|null
     */
    public function getFileInfo(string $objectName): ?array
    {
        $filePath = $this->getFullPath($objectName);

        if (!file_exists($filePath)) {
            return null;
        }

        return [
            'name' => basename($objectName),
            'path' => $this->getPath($objectName),
            'url' => $this->getUrl($objectName),
            'full_url' => $this->getFullUrl($objectName),
            'size' => filesize($filePath),
            'mime' => mime_content_type($filePath),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
        ];
    }

    /**
     * 获取完整文件路径
     *
     * @param string $objectName 存储对象名称
     * @return string
     */
    private function getFullPath(string $objectName): string
    {
        return rtrim($this->rootPath, '/') . '/' . ltrim($objectName, '/');
    }

    /**
     * 验证文件
     *
     * @param string $filePath
     * @param array $allowedExtensions
     * @param int $maxSize
     * @return bool
     */
    protected function validateFile(string $filePath, array $allowedExtensions = [], int $maxSize = 0): bool
    {
        if (!file_exists($filePath)) {
            $this->setError('文件不存在');
            return false;
        }

        if (!is_readable($filePath)) {
            $this->setError('文件不可读');
            return false;
        }

        return true;
    }
}