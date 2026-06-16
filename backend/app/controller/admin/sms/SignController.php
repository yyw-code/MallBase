<?php

declare(strict_types=1);

namespace app\controller\admin\sms;

use app\service\admin\sms\SmsSignService;
use app\validate\admin\sms\SmsSignValidate;
use mall_base\base\BaseController;

/**
 * 短信签名控制器
 *
 * @extends BaseController<SmsSignService>
 */
class SignController extends BaseController
{
    protected string $serviceClass = SmsSignService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'provider_id']);
        [$page, $limit] = $this->getPagination(1, 15);
        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function info($id)
    {
        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    public function import()
    {
        $data = $this->request->param(['provider_id', 'sign_name', 'remark']);
        if (empty($data['provider_id']) || empty($data['sign_name'])) {
            return $this->error('服务商和签名名称必填');
        }
        $this->validate($data, SmsSignValidate::class . '.import');
        $id = $this->service()->importLocal($data);
        return $this->success(['id' => $id], '导入成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

}
