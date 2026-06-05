<?php
declare(strict_types=1);

namespace app\model\client;

use mall_base\base\BaseModel;

/**
 * 客户端主题方案模型
 */
class ClientTheme extends BaseModel
{
    public const TYPE_LIGHT = 'light';
    public const TYPE_DARK = 'dark';
    public const TYPE_CUSTOM = 'custom';

    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    protected $name = 'client_theme';

    protected $autoWriteTimestamp = true;

    protected $json = ['tokens'];

    protected $jsonAssoc = true;

    public static function validTypes(): array
    {
        return [self::TYPE_LIGHT, self::TYPE_DARK, self::TYPE_CUSTOM];
    }
}
