<?php
declare(strict_types=1);

namespace app\model\client;

use mall_base\base\BaseModel;

/**
 * 客户端页面库模型
 */
class ClientPage extends BaseModel
{
    public const TYPE_TAB = 'tab';
    public const TYPE_PAGE = 'page';
    public const TYPE_SUBPACKAGE = 'subpackage';

    public const CATEGORY_BASIC = 'basic';
    public const CATEGORY_GOODS = 'goods';
    public const CATEGORY_ORDER = 'order';
    public const CATEGORY_AFTERSALE = 'aftersale';
    public const CATEGORY_USER = 'user';
    public const CATEGORY_MARKETING = 'marketing';
    public const CATEGORY_OTHER = 'other';

    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';

    protected $name = 'client_page';

    protected $autoWriteTimestamp = true;

    public static function validTypes(): array
    {
        return [
            self::TYPE_TAB,
            self::TYPE_PAGE,
            self::TYPE_SUBPACKAGE,
        ];
    }

    public static function validCategories(): array
    {
        return [
            self::CATEGORY_BASIC,
            self::CATEGORY_GOODS,
            self::CATEGORY_ORDER,
            self::CATEGORY_AFTERSALE,
            self::CATEGORY_USER,
            self::CATEGORY_MARKETING,
            self::CATEGORY_OTHER,
        ];
    }
}
