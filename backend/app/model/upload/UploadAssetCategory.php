<?php
declare(strict_types=1);

namespace app\model\upload;

use mall_base\base\BaseModel;

/**
 * 素材分类模型
 */
class UploadAssetCategory extends BaseModel
{
    public const CODE_GOODS = 'goods';
    public const CODE_RICH_TEXT = 'rich_text';
    public const CODE_REVIEW = 'review';
    public const CODE_AVATAR = 'avatar';
    public const CODE_SETTING = 'setting';
    public const CODE_OTHER = 'other';

    protected $name = 'upload_asset_category';
    protected $pk = 'id';
}
