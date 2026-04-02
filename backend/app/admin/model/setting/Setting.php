<?php

declare (strict_types=1);

namespace app\admin\model\setting;

use mall_base\base\BaseModel;

/**
 * 设置项模型
 */
class Setting extends BaseModel
{
    /**
     * 表名
     */
    protected $name = 'setting';

    /**
     * 自动写入时间戳
     */
    protected $autoWriteTimestamp = true;

    /**
     * JSON 字段
     */
    protected $json = ['options', 'rules'];

    // ========== 表单类型常量 ==========

    /** 文本框 */
    const TYPE_INPUT = 'input';

    /** 多行文本 */
    const TYPE_TEXTAREA = 'textarea';

    /** 数字 */
    const TYPE_NUMBER = 'number';

    /** 密码 */
    const TYPE_PASSWORD = 'password';

    /** 开关 */
    const TYPE_SWITCH = 'switch';

    /** 单选 */
    const TYPE_RADIO = 'radio';

    /** 多选 */
    const TYPE_CHECKBOX = 'checkbox';

    /** 下拉选择 */
    const TYPE_SELECT = 'select';

    /** 图片上传（单张） */
    const TYPE_IMAGE = 'image';

    /** 多图上传 */
    const TYPE_IMAGES = 'images';

    /** 文件上传（单个） */
    const TYPE_FILE = 'file';

    /** 多文件上传 */
    const TYPE_FILES = 'files';

    /** 富文本 */
    const TYPE_EDITOR = 'editor';

    /** JSON 编辑器 */
    const TYPE_JSON = 'json';

    /**
     * 关联分组
     */
    public function group()
    {
        return $this->belongsTo(SettingGroup::class, 'group_id', 'id');
    }
}