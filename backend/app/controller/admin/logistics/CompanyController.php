<?php
declare(strict_types=1);

namespace app\controller\admin\logistics;

use app\service\admin\logistics\LogisticsCompanyService;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 物流公司目录控制器
 *
 * @extends BaseController<LogisticsCompanyService>
 */
class CompanyController extends BaseController
{
    protected string $serviceClass = LogisticsCompanyService::class;

    public function list()
    {
        $where = $this->request->param(['platform', 'keyword', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function options()
    {
        $platform = trim((string) $this->request->param('platform', ''));

        return $this->success($this->service()->options($platform), '获取成功');
    }

    public function status($id)
    {
        $status = $this->request->param('status', null);
        if ($status === null || $status === '') {
            throw new BusinessException('状态不能为空');
        }

        $this->service()->updateStatus((int) $id, (int) $status);
        return $this->success(null, '更新成功');
    }

    public function save()
    {
        $data = $this->request->param([
            'id', 'platform', 'code', 'name', 'remark', 'status', 'sort',
        ]);
        $id = $this->service()->saveCompany($data);

        return $this->success(['id' => $id], '保存成功');
    }

    public function delete($id)
    {
        $this->service()->deleteCompany((int) $id);

        return $this->success(null, '删除成功');
    }
}
