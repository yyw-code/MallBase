<?php
declare(strict_types=1);

namespace app\controller\admin\client;

use app\model\client\ClientDecorationScheme;
use app\service\admin\client\ClientDecorationSchemeService;
use app\validate\admin\client\ClientDecorationSchemeValidate;
use mall_base\base\BaseController;

/**
 * 客户端装修方案控制器
 * @extends BaseController<ClientDecorationSchemeService>
 */
class DecorationSchemeController extends BaseController
{
    protected string $serviceClass = ClientDecorationSchemeService::class;

    public function list()
    {
        $where = $this->request->param(['type', 'keyword', 'is_active', 'status']);
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

    public function productSources()
    {
        $where = $this->request->param(['keyword']);

        $result = $this->service()->getProductSourcePicker($where);
        return $this->success($result, '获取成功');
    }

    public function targetPicker()
    {
        $where = $this->request->param(['keyword']);

        $result = $this->service()->getTargetPicker($where);
        return $this->success($result, '获取成功');
    }

    public function create()
    {
        $data = $this->request->param([
            'type', 'name', 'description', 'schema', 'tabbar_mode', 'sort', 'status',
        ]);
        $data['schema'] = $this->normalizeSchemaForValidate(
            (string) ($data['type'] ?? ClientDecorationScheme::TYPE_HOME),
            $data['schema'] ?? null
        );
        $data['tabbar_mode'] = $data['tabbar_mode'] ?? ClientDecorationScheme::TABBAR_MODE_NATIVE;

        $this->validate($data, ClientDecorationSchemeValidate::class . '.create');

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
            'type', 'name', 'description', 'schema', 'tabbar_mode', 'sort', 'status',
        ]);
        $data['schema'] = $this->normalizeSchemaForValidate(
            (string) ($data['type'] ?? ClientDecorationScheme::TYPE_HOME),
            $data['schema'] ?? null
        );
        $data['tabbar_mode'] = $data['tabbar_mode'] ?? ClientDecorationScheme::TABBAR_MODE_NATIVE;

        $this->validate($data, ClientDecorationSchemeValidate::class . '.update');

        $this->service()->update($id, $data);
        return $this->success(null, '更新成功');
    }

    public function copy()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $newId = $this->service()->copy($id);
        return $this->success(['id' => $newId], '复制成功');
    }

    public function activate()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->activate($id);
        return $this->success(null, '启用成功');
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

    protected function normalizeSchemaForValidate(string $type, $schema): array
    {
        if (is_array($schema)) {
            return $schema;
        }

        if (is_string($schema) && $schema !== '') {
            $decoded = json_decode($schema, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return match ($type) {
            ClientDecorationScheme::TYPE_PROFILE => ['modules' => []],
            ClientDecorationScheme::TYPE_TABBAR => ['items' => []],
            default => ['components' => []],
        };
    }
}
