<?php
declare(strict_types=1);

namespace app\model\upload;

use mall_base\base\BaseModel;

/**
 * 素材引用模型
 */
class UploadAssetUsage extends BaseModel
{
    protected $name = 'upload_asset_usage';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
}
