<?php

declare (strict_types=1);

namespace app\model\auth;

use mall_base\base\BaseModel;

/**
 * 管理员操作日志模型
 */
class AdminOperationLog extends BaseModel
{
    // use SoftDelete; // 如果需要软删除，取消注释

    protected $name = 'admin_operation_log';

    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false; // 不需要更新时间

    /**
     * 类型转换
     */
    protected $type = [
        'admin_id' => 'integer',
        'status' => 'integer',
        'duration' => 'float',
    ];

    /**
     * JSON 字段
     */
    protected $json = ['params', 'response'];

    /**
     * 关联管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }
}
