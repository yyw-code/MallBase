<?php

declare(strict_types=1);

namespace app\controller\client;

use app\service\UploadService;
use mall_base\base\BaseController;

/**
 * 客户端上传控制器
 *
 * @extends BaseController<UploadService>
 */
class UploadController extends BaseController
{
    protected string $serviceClass = UploadService::class;

    /**
     * 客户端单图上传
     */
    public function single()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请选择要上传的文件');
        }

        $module = (string) $this->request->param('module', $this->request->param('type', 'client'));
        $result = $this->service()->uploadClientImage(
            $file,
            $module !== '' ? $module : 'client',
            (int)($this->request->user_id ?? 0)
        );

        return $this->success($result, '上传成功');
    }

    /**
     * 微信绑定阶段临时头像上传
     */
    public function wechatAvatar()
    {
        $bindToken = (string) $this->request->post('bind_token', '');
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请选择要上传的文件');
        }

        $result = $this->service()->uploadWechatAvatar($file, $bindToken);

        return $this->success($result, '上传成功');
    }
}
