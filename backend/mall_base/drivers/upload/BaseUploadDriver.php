<?php

namespace mall_base\drivers\upload;

use mall_base\base\BaseDriver;

/**
 * 文件上传驱动基类
 * 
 * 功能说明：
 * - 定义文件上传的统一接口
 * - 子类实现具体的存储平台逻辑
 * 
 * 使用示例：
 * ```php
 * // 注册上传驱动
 * \mall_base\DriverManager::register('upload', [
 *     'oss' => \mall_base\drivers\upload\OssUploadDriver::class,
 *     'cos' => \mall_base\drivers\upload\CosUploadDriver::class,
 * ]);
 * 
 * // 设置默认驱动
 * \mall_base\DriverManager::setDefault('upload', 'oss');
 * 
 * // 使用驱动
 * $upload = \mall_base\DriverManager::driver('upload');
 * $url = $upload->upload($_FILES['file']['tmp_name'], 'images/test.jpg');
 * ```
 */
abstract class BaseUploadDriver extends BaseDriver
{
    /**
     * 上传文件
     * 
     * @param string $filePath 本地文件路径
     * @param string $objectName 存储对象名称
     * @return string 返回文件访问 URL
     */
    abstract public function upload(string $filePath, string $objectName): string;

    /**
     * 删除文件
     * 
     * @param string $objectName 存储对象名称
     * @return bool
     */
    abstract public function delete(string $objectName): bool;

    /**
     * 获取文件 URL
     * 
     * @param string $objectName 存储对象名称
     * @return string
     */
    abstract public function getUrl(string $objectName): string;

    /**
     * 检查文件是否存在
     * 
     * @param string $objectName 存储对象名称
     * @return bool
     */
    abstract public function exists(string $objectName): bool;

    /**
     * 获取文件信息
     * 
     * @param string $objectName 存储对象名称
     * @return array|null
     */
    abstract public function getFileInfo(string $objectName): ?array;

    /**
     * 生成随机文件名
     * 
     * @param string $extension 文件扩展名
     * @return string
     */
    protected function generateFileName(string $extension = ''): string
    {
        $name = md5(uniqid(mt_rand(), true));
        
        if ($extension) {
            $extension = ltrim($extension, '.');
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * 生成按日期分组的文件路径
     * 
     * @param string $path 基础路径
     * @return string
     */
    protected function generateDatePath(string $path = ''): string
    {
        $datePath = date('Y/m/d');
        
        return $path ? rtrim($path, '/') . '/' . $datePath : $datePath;
    }

    /**
     * 验证文件
     * 
     * @param string $filePath 文件路径
     * @param array $allowedExtensions 允许的扩展名
     * @param int $maxSize 最大文件大小（字节）
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

        $fileSize = filesize($filePath);
        if ($maxSize > 0 && $fileSize > $maxSize) {
            $this->setError('文件大小超过限制');
            return false;
        }

        if (!empty($allowedExtensions)) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (!in_array(strtolower($extension), array_map('strtolower', $allowedExtensions))) {
                $this->setError('文件类型不允许');
                return false;
            }
        }

        return true;
    }
}
