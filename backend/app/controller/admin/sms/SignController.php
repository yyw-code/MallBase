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
        $where = $this->request->param(['keyword', 'provider_id', 'audit_status']);
        [$page, $limit] = $this->getPagination(1, 15);
        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function info($id)
    {
        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    public function create()
    {
        $data = $this->request->param([
            'provider_id', 'sign_name', 'sign_source', 'sign_type',
            'remark', 'qualification_id', 'sign_files',
        ]);
        $this->validate($data, SmsSignValidate::class . '.create');
        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建已提交,等待审核');
    }

    /**
     * 从阿里云导入已审核签名(只调 QuerySmsSign,不调 AddSmsSign)
     */
    public function import()
    {
        $data = $this->request->param(['provider_id', 'sign_name']);
        if (empty($data['provider_id']) || empty($data['sign_name'])) {
            return $this->error('服务商和签名名称必填');
        }
        $id = $this->service()->importFromRemote(
            (int) $data['provider_id'],
            trim((string) $data['sign_name']),
        );
        return $this->success(['id' => $id], '导入成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    public function syncStatus($id)
    {
        $info = $this->service()->syncStatus((int) $id);
        return $this->success($info, '同步成功');
    }

    public function syncAll()
    {
        $providerId = (int) $this->request->param('provider_id', 0);
        if ($providerId <= 0) {
            return $this->error('服务商ID必填');
        }
        $stat = $this->service()->syncAll($providerId);
        return $this->success($stat, "同步完成: 成功 {$stat['success']} 个,失败 {$stat['failed']} 个");
    }
}
