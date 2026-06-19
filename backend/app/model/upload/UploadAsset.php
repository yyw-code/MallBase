<?php
declare(strict_types=1);

namespace app\model\upload;

use mall_base\base\BaseModel;

/**
 * 逻辑素材模型
 */
class UploadAsset extends BaseModel
{
    public const STATUS_DELETED = 0;
    public const STATUS_NORMAL = 1;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_FILE = 'file';

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    protected $name = 'upload_asset';
    protected $pk = 'id';
    protected $json = ['meta'];
    protected $jsonAssoc = true;
}
