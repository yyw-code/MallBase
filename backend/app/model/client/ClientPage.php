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

    public const CATEGORY_ID_BASIC = 1;
    public const CATEGORY_ID_GOODS = 2;
    public const CATEGORY_ID_CONTENT = 3;
    public const CATEGORY_ID_ORDER = 4;
    public const CATEGORY_ID_AFTERSALE = 5;
    public const CATEGORY_ID_USER = 6;
    public const CATEGORY_ID_POINTS = 7;
    public const CATEGORY_ID_WALLET = 8;
    public const CATEGORY_ID_OTHER = 9;

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

}
