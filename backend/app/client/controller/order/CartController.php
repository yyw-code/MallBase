<?php
declare(strict_types=1);

namespace app\client\controller\order;

use app\client\service\order\CartService;
use app\client\validate\order\CartValidate;
use mall_base\base\BaseController;

/**
 * 买家购物车控制器
 *
 * 约束：
 *  - 用户身份来自 JwtAuth 中间件注入的 request->user_id，禁止从 body 读取
 *  - 所有方法最终委托给 CartService，Controller 仅做传输层
 *
 * @extends BaseController<CartService>
 */
class CartController extends BaseController
{
    protected string $serviceClass = CartService::class;

    /**
     * 加入购物车：同 SKU 走 UPSERT 累加
     */
    public function add()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['sku_id', 'quantity']);
        $this->validate($data, CartValidate::class . '.add');

        $id = $this->service()->add($userId, (int) $data['sku_id'], (int) $data['quantity']);
        return $this->success(['id' => $id], '加入购物车成功');
    }

    /**
     * 修改单行数量（绝对值）
     */
    public function update($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['quantity']);
        $this->validate($data, CartValidate::class . '.update');

        $this->service()->updateQuantity($userId, (int) $id, (int) $data['quantity']);
        return $this->success(null, '更新成功');
    }

    /**
     * 批量删除购物车行
     */
    public function remove()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['cart_ids']);
        $this->validate($data, CartValidate::class . '.remove');

        $this->service()->remove($userId, (array) $data['cart_ids']);
        return $this->success(null, '删除成功');
    }

    /**
     * 批量切换勾选
     */
    public function toggleSelected()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['cart_ids', 'selected']);
        $this->validate($data, CartValidate::class . '.toggleSelected');

        $this->service()->toggleSelected($userId, (array) $data['cart_ids'], (int) $data['selected']);
        return $this->success(null, '操作成功');
    }

    /**
     * 购物车列表（聚合商品/SKU 信息）
     */
    public function list()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->list($userId), '获取成功');
    }
}
