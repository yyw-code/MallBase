<?php
declare(strict_types=1);

namespace app\model\client;

use mall_base\base\BaseModel;

/**
 * 客户端装修方案模型
 */
class ClientDecorationScheme extends BaseModel
{
    public const TYPE_HOME = 'home';
    public const TYPE_PROFILE = 'profile';
    public const TYPE_TABBAR = 'tabbar';

    public const TABBAR_MODE_NATIVE = 'native';
    public const TABBAR_MODE_CUSTOM = 'custom';

    protected $name = 'client_decoration_scheme';

    protected $autoWriteTimestamp = true;

    protected $json = ['schema'];

    protected $jsonAssoc = true;

    public static function validTypes(): array
    {
        return [self::TYPE_HOME, self::TYPE_PROFILE, self::TYPE_TABBAR];
    }

    public static function validTabbarModes(): array
    {
        return [self::TABBAR_MODE_NATIVE, self::TABBAR_MODE_CUSTOM];
    }
}
