<?php
declare(strict_types=1);

namespace app\model\upload;

use mall_base\base\BaseModel;

/**
 * 素材迁移明细日志模型
 */
class UploadAssetMigrationLog extends BaseModel
{
    public const STATUS_PROCESSING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = 2;

    protected $name = 'upload_asset_migration_log';
    protected $pk = 'id';
}
