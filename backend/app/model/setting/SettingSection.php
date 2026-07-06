<?php

declare (strict_types=1);

namespace app\model\setting;

use mall_base\base\BaseModel;

/**
 * 设置页内分组模型
 */
class SettingSection extends BaseModel
{
    /**
     * 表名
     */
    protected $name = 'setting_section';

    /**
     * 自动写入时间戳
     */
    protected $autoWriteTimestamp = true;

    /**
     * 关联设置分组
     */
    public function settingGroup()
    {
        return $this->belongsTo(SettingGroup::class, 'group_id', 'id');
    }
}
