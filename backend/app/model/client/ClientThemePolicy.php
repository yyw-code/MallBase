<?php
declare(strict_types=1);

namespace app\model\client;

use mall_base\base\BaseModel;

/**
 * 客户端主题策略模型
 */
class ClientThemePolicy extends BaseModel
{
    public const POLICY_ID = 1;

    public const MODE_SYSTEM = 'system';
    public const MODE_LIGHT = 'light';
    public const MODE_DARK = 'dark';
    public const MODE_CUSTOM = 'custom';

    protected $name = 'client_theme_policy';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;

    public static function validModes(): array
    {
        return [self::MODE_SYSTEM, self::MODE_LIGHT, self::MODE_DARK, self::MODE_CUSTOM];
    }
}
