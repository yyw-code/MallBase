<?php

declare (strict_types=1);

namespace app\admin\service;

use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;

/**
 * 上传服务
 */
class UploadService extends BaseService
{
    /**
     * 图片上传配置
     */
    private array $imageConfig = [
        'size' => 10 * 1024 * 1024, // 10MB
        'ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp']
    ];

    /**
     * 文件上传配置
     */
    private array $fileConfig = [
        'size' => 50 * 1024 * 1024, // 50MB
        'ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt']
    ];


    // ==================== 上传配置（前端获取） ====================

    /**
     * 获取上传配置（前端 Upload 组件使用）
     * 根据 type 参数返回对应的验证规则 + 文件图标
     *
     * @param string $type 上传类型：image/images/file/files
     * @return array
     */
    public function getUploadConfig(string $type): array
    {
        $config = config('upload');
        $rules  = $config['rules'] ?? [];
        $fileIcons = $config['file_icons'] ?? [];

        // 合法类型列表
        $validTypes = array_keys($rules);

        // 默认回退到 image
        if (!in_array($type, $validTypes, true)) {
            $type = 'image';
        }

        $rule = $rules[$type] ?? [
            'max_size'     => 2,
            'max_count'    => 1,
            'accept_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ];

        return [
            'max_size'     => $rule['max_size'],
            'max_count'    => $rule['max_count'],
            'accept_types' => $rule['accept_types'],
            'file_icons'   => $fileIcons,
        ];
    }

    // ==================== 上传功能 ====================

    /**
     * 设置图片上传配置
     *
     * @param array $config
     * @return $this
     */
    public function setImageConfig(array $config)
    {
        $this->imageConfig = $config;
        return $this;
    }

    /**
     * 设置文件上传配置
     *
     * @param array $config
     * @return $this
     */
    public function setFileConfig(array $config)
    {
        $this->fileConfig = $config;
        return $this;
    }

    /**
     * 上传图片
     *
     * @param \think\file\UploadedFile $file
     * @return array 返回文件路径信息
     */
    public function uploadImage($file): array
    {
        // 验证文件
        $this->validateUploadFile($file, $this->imageConfig);

        // 获取上传驱动
        $uploadDriver = $this->getUploadDriver();

        // 生成文件名
        $extension = pathinfo($file->getOriginalName(), PATHINFO_EXTENSION);
        $fileName = $this->generateFileName($extension);

        // 生成存储路径
        $objectName = 'images/' . $this->generateDatePath() . '/' . $fileName;

        // 移动到临时目录
        $tempPath = $file->getPathname();

        try {
            // 上传文件
            $uploadDriver->upload($tempPath, $objectName);

            // 返回完整文件信息
            return $uploadDriver->getFileInfo($objectName);
        } catch (\Exception $e) {
            throw new \Exception('文件上传失败: ' . $e->getMessage());
        } finally {
            // 删除临时文件
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * 上传文件
     *
     * @param \think\file\UploadedFile $file
     * @return array 返回文件路径信息
     */
    public function uploadFile($file): array
    {
        // 验证文件
        $this->validateUploadFile($file, $this->fileConfig);

        // 获取上传驱动
        $uploadDriver = $this->getUploadDriver();

        // 生成文件名
        $extension = pathinfo($file->getOriginalName(), PATHINFO_EXTENSION);
        $fileName = $this->generateFileName($extension);

        // 生成存储路径
        $objectName = 'files/' . $this->generateDatePath() . '/' . $fileName;

        // 移动到临时目录
        $tempPath = $file->getPathname();

        try {
            // 上传文件
            $uploadDriver->upload($tempPath, $objectName);

            // 返回完整文件信息
            return $uploadDriver->getFileInfo($objectName);
        } catch (\Exception $e) {
            throw new \Exception('文件上传失败: ' . $e->getMessage());
        } finally {
            // 删除临时文件
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * 验证上传文件
     *
     * @param \think\file\UploadedFile $file
     * @param array $config 配置信息
     */
    private function validateUploadFile($file, array $config): void
    {
        // 检查文件是否存在
        if (!$file) {
            throw new \Exception('文件不存在');
        }

        // 检查文件大小
        if ($file->getSize() > $config['size']) {
            $maxSizeMB = round($config['size'] / 1024 / 1024, 2);
            throw new \Exception("文件大小不能超过 {$maxSizeMB}MB");
        }

        // 检查文件扩展名
        $extension = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($extension, $config['ext'])) {
            throw new \Exception('文件类型不允许，允许的类型: ' . implode(',', $config['ext']));
        }
    }

    /**
     * 获取上传驱动
     * 
     * @return \mall_base\base\BaseDriver|\mall_base\drivers\upload\BaseUploadDriver
     */
    private function getUploadDriver()
    {
        // 从配置获取上传驱动名称（使用默认驱动 'local'）
        $driverName = config('upload.driver', 'local');
        
        // 获取驱动配置
        $driverConfig = config("upload.{$driverName}", []);
        
        // 通过 DriverManager 获取驱动实例
        return DriverManager::driver('upload', $driverName, $driverConfig);
    }

    /**
     * 生成随机文件名
     *
     * @param string $extension 文件扩展名
     * @return string
     */
    private function generateFileName(string $extension = ''): string
    {
        $name = md5(uniqid((string)mt_rand(), true));

        if ($extension) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * 生成按日期分组的文件路径
     *
     * @return string
     */
    private function generateDatePath(): string
    {
        return date('Y/m/d');
    }
}