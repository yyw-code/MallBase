<?php
declare(strict_types=1);

namespace app\model\upload;

use mall_base\base\BaseModel;

/**
 * 素材迁移任务模型
 */
class UploadAssetMigration extends BaseModel
{
    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_DONE = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_CANCELLED = 4;

    protected $name = 'upload_asset_migration';
    protected $pk = 'id';
    protected $json = ['options'];
    protected $jsonAssoc = true;
}
