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
     * POST /upload/single?type=file&module=cert&related_id=42  （证书私有上传）
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
        $categoryId = (int) $this->request->param('category_id', 0);

        $rules  = $this->service()->resolveUploadRules($type, $module, (int)$relatedId);
        // cert 模块需要在 service::upload() 里走专用分支（落私有目录、跳过 MIME 校验）；
        // 其他来源统一以 'admin' 作为存储子目录，保持原有行为。
        $storageModule = $module === UploadService::MODULE_CERT
            ? UploadService::MODULE_CERT
            : 'admin';
        $result = $this->service()->upload(
            $file,
            $rules,
            $storageModule,
            $module !== '' ? $module : 'admin',
            'admin',
            (int)($this->request->admin_id ?? 0),
            $categoryId
        );

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
        $categoryId = (int) $this->request->param('category_id', 0);

        $rules   = $this->service()->resolveUploadRules($type, $module, (int)$relatedId);
        // cert 模块走专用私有上传分支；其他模块走 'admin' 子目录保持原行为
        $storageModule = $module === UploadService::MODULE_CERT
            ? UploadService::MODULE_CERT
            : 'admin';
        $results = $this->service()->batchUpload(
            $files,
            $rules,
            $storageModule,
            $module !== '' ? $module : 'admin',
            'admin',
            (int)($this->request->admin_id ?? 0),
            $categoryId
        );

        return $this->success($results, '上传成功');
    }
}
