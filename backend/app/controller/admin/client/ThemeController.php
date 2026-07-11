<?php
declare(strict_types=1);

namespace app\controller\admin\client;

use app\service\admin\client\ClientThemeService;
use app\validate\admin\client\ClientThemeValidate;
use mall_base\base\BaseController;

/**
 * 客户端主题控制器
 * @extends BaseController<ClientThemeService>
 */
class ThemeController extends BaseController
{
    protected string $serviceClass = ClientThemeService::class;

    public function list()
    {
        $where = $this->request->param(['type', 'keyword', 'status']);
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

    public function create()
    {
        $data = $this->request->param(['name', 'type', 'tokens', 'status', 'sort']);
        $data['tokens'] = $this->normalizeTokensForValidate($data['tokens'] ?? null);
        $data['type'] = $data['type'] ?? 'custom';

        $this->validate($data, ClientThemeValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'type', 'tokens', 'status', 'sort']);
        $data['tokens'] = $this->normalizeTokensForValidate($data['tokens'] ?? null);
        $data['type'] = $data['type'] ?? 'custom';

        $this->validate($data, ClientThemeValidate::class . '.update');

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

    public function publish()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->publish($id);
        return $this->success(null, '发布成功');
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

    public function policy()
    {
        $policy = $this->service()->getPolicy();
        return $this->success($policy, '获取成功');
    }

    public function savePolicy()
    {
        $data = $this->request->param(['allow_user_select', 'default_mode', 'default_theme_id']);
        $policy = $this->service()->savePolicy($data);
        return $this->success($policy, '保存成功');
    }

    public function setting()
    {
        $setting = $this->service()->getSetting();
        return $this->success($setting, '获取成功');
    }

    public function saveSetting()
    {
        $data = $this->request->param(['user_select_enabled', 'admin_theme_mode', 'admin_theme_id']);
        $setting = $this->service()->saveSetting($data);
        return $this->success($setting, '保存成功');
    }

    protected function normalizeTokensForValidate($tokens): array
    {
        if (is_array($tokens)) {
            return $tokens;
        }

        if (is_string($tokens) && $tokens !== '') {
            $decoded = json_decode($tokens, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
