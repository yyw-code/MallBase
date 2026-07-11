<?php
declare(strict_types=1);

namespace app\controller\admin\client;

use app\model\client\ClientPage;
use app\service\admin\client\ClientPageService;
use app\validate\admin\client\ClientPageValidate;
use mall_base\base\BaseController;

/**
 * 客户端页面库控制器
 * @extends BaseController<ClientPageService>
 */
class PageController extends BaseController
{
    protected string $serviceClass = ClientPageService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'page_type', 'category_id', 'source', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function info()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getInfo($id);
        return $this->success($info, '获取成功');
    }

    public function picker()
    {
        $where = $this->request->param(['keyword']);
        $result = $this->service()->getPickerGroups($where);
        return $this->success($result, '获取成功');
    }

    public function create()
    {
        $data = $this->request->param([
            'name', 'path', 'page_type', 'category_id', 'package_root', 'need_login', 'source', 'remark', 'sort', 'status',
        ]);
        $data['source'] = $data['source'] ?? ClientPage::SOURCE_MANUAL;
        if (($data['category_id'] ?? '') === '') {
            unset($data['category_id']);
        }

        $this->validate($data, ClientPageValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'path', 'page_type', 'category_id', 'package_root', 'need_login', 'source', 'remark', 'sort', 'status',
        ]);
        $data['source'] = $data['source'] ?? ClientPage::SOURCE_MANUAL;
        if (($data['category_id'] ?? '') === '') {
            unset($data['category_id']);
        }

        $this->validate($data, ClientPageValidate::class . '.update');

        $this->service()->update($id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($id);
        return $this->success(null, '删除成功');
    }

    public function import()
    {
        $pagesJson = $this->request->param('pages_json', '');
        $file = $this->request->file('file');
        if ($file) {
            $realPath = $file->getRealPath();
            if (!$realPath || !is_file($realPath)) {
                return $this->error('pages.json 文件读取失败');
            }

            $pagesJson = (string) file_get_contents($realPath);
        }

        $result = $this->service()->importFromUniappPages(is_string($pagesJson) ? $pagesJson : '');
        return $this->success($result, '导入成功');
    }
}
