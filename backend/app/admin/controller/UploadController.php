<?php

declare (strict_types=1);

namespace app\admin\controller;

use app\admin\service\UploadService;
use mall_base\base\BaseController;

/**
 * 上传控制器
 */
class UploadController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UploadService::class;

    /**
     * 获取上传配置（前端 Upload 组件使用）
     * GET /upload/config?type=image
     */
    public function config()
    {
        $type = $this->request->param('type', 'image');

        $config = $this->service()->getUploadConfig($type);
        return $this->success($config, '获取成功');
    }

    /**
     * 上传图片
     */
    public function image()
    {
        $file = $this->request->file('file');
        
        if (!$file) {
            return $this->error('请选择要上传的文件');
        }

        $result = $this->service()->uploadImage($file);
        return $this->success($result, '上传成功');
    }

    /**
     * 上传文件
     */
    public function file()
    {
        $file = $this->request->file('file');
        
        if (!$file) {
            return $this->error('请选择要上传的文件');
        }

        $result = $this->service()->uploadFile($file);
        return $this->success($result, '上传成功');
    }

    /**
     * 批量上传图片
     */
    public function batchImage()
    {
        $files = $this->request->file('files');
        
        if (!$files) {
            return $this->error('请选择要上传的文件');
        }

        $results = [];
        $errors = [];

        foreach ($files as $key => $file) {
            try {
                $result = $this->service()->uploadImage($file);
                $results[] = $result;
            } catch (\Exception $e) {
                $errors[] = "文件 {$key}: " . $e->getMessage();
            }
        }

        if (empty($results)) {
            return $this->error(implode('; ', $errors));
        }

        return $this->success(['urls' => $results], '上传成功');
    }
}