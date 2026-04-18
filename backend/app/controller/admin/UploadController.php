<?php

declare (strict_types=1);

namespace app\controller\admin;

use app\service\UploadService;
use mall_base\base\BaseController;

/**
 * 上传控制器
 * @extends BaseController<UploadService>
 */
class UploadController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UploadService::class;

    /**
     * 单文件上传（图片/文件通用）
     * POST /upload/single?type=image
     * POST /upload/single?type=image&module=dynamic_form&related_id=13
     */
    public function single()
    {
        $file = $this->request->file('file');

        if (!$file) {
            return $this->error('请选择要上传的文件');
        }

        $type      = $this->request->param('type', 'image');
        $module    = $this->request->param('module', '');
        $relatedId = $this->request->param('related_id', 0);

        $rules  = $this->service()->resolveUploadRules($type, $module, (int)$relatedId);
        $result = $this->service()->upload($file, $rules, 'admin');

        return $this->success($result, '上传成功');
    }

    /**
     * 批量文件上传（图片/文件通用）
     * POST /upload/batch?type=images
     * POST /upload/batch?type=images&module=dynamic_form&related_id=13
     */
    public function batch()
    {
        $files = $this->request->file('files');

        if (!$files) {
            return $this->error('请选择要上传的文件');
        }

        $type      = $this->request->param('type', 'images');
        $module    = $this->request->param('module', '');
        $relatedId = $this->request->param('related_id', 0);

        $rules   = $this->service()->resolveUploadRules($type, $module, (int)$relatedId);
        $results = $this->service()->batchUpload($files, $rules, 'admin');

        return $this->success($results, '上传成功');
    }
}
