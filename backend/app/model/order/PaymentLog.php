<?php
declare(strict_types=1);

namespace app\model\order;

use app\common\enum\PayMethod;
use app\common\enum\PayScene;
use mall_base\base\BaseModel;

/**
 * 支付流水模型（append + 终态更新）
 *
 * 写入路径：
 *  - PrepayService 创建 PREPAY 记录
 *  - NotifyService 验签 + 解密成功后将匹配的 PREPAY 行改为 PAID 并填回字段
 *
 * 业务代码禁止跨此模型直接改 mb_order 状态，状态流转走 OrderStatusMachine。
 */
class PaymentLog extends BaseModel
{
    protected $name = 'payment_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /** prepay 已下单待支付 */
    public const EVENT_PREPAY = 'PREPAY';

    /** notify 支付成功 */
    public const EVENT_PAID = 'PAID';

    /** prepay 被新请求顶替 */
    public const EVENT_SUPERSEDED = 'SUPERSEDED';

    /** 订单关闭后回写 */
    public const EVENT_CLOSED = 'CLOSED';

    protected array $append = ['pay_method_text', 'scene_text'];

    public function getPayMethodTextAttr($value, $data): string
    {
        return PayMethod::textOf((int) ($data['pay_method'] ?? 0));
    }

    public function getSceneTextAttr($value, $data): string
    {
        return PayScene::textOf((int) ($data['scene'] ?? 0));
    }
}
