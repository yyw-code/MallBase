<?php
declare(strict_types=1);

namespace app\model\marketing;

use mall_base\base\BaseModel;

/**
 * 积分规则
 */
class PointsRule extends BaseModel
{
    protected $name = 'points_rule';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const SCENE_ORDER_COMPLETE = 'order_complete';
    public const SCENE_REGISTER = 'register';
    public const SCENE_REVIEW = 'review';

    public static function sceneText(string $scene): string
    {
        return match ($scene) {
            self::SCENE_ORDER_COMPLETE => '消费返积分',
            self::SCENE_REGISTER => '注册奖励',
            self::SCENE_REVIEW => '评价奖励',
            default => $scene === '' ? '未知场景' : $scene,
        };
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    public static function sceneOptions(): array
    {
        return [
            ['value' => self::SCENE_ORDER_COMPLETE, 'label' => self::sceneText(self::SCENE_ORDER_COMPLETE)],
            ['value' => self::SCENE_REGISTER, 'label' => self::sceneText(self::SCENE_REGISTER)],
            ['value' => self::SCENE_REVIEW, 'label' => self::sceneText(self::SCENE_REVIEW)],
        ];
    }
}
