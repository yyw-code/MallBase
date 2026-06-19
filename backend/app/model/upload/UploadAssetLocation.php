<?php
declare(strict_types=1);

namespace app\model\upload;

use mall_base\base\BaseModel;

/**
 * 素材存储位置模型
 */
class UploadAssetLocation extends BaseModel
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    public const DRIVER_LOCAL = 'local';
    public const DRIVER_OSS = 'oss';
    public const DRIVER_COS = 'cos';
    public const DRIVER_STATIC = 'static';
    public const DRIVER_REMOTE = 'remote';

    protected $name = 'upload_asset_location';
    protected $pk = 'id';
    protected $json = ['meta'];
    protected $jsonAssoc = true;
}
