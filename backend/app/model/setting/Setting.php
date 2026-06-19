<?php

declare (strict_types=1);

namespace app\model\setting;

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

    /** 视频上传（单个） */
    const TYPE_VIDEO = 'video';

    /** 多视频上传 */
    const TYPE_VIDEOS = 'videos';

    /** 富文本 */
    const TYPE_EDITOR = 'editor';

    /** JSON 编辑器 */
    const TYPE_JSON = 'json';

    /** 选项列表 */
    const TYPE_OPTION_LIST = 'option_list';

    /**
     * 需要拼接上传域名的文件类型
     */
    const FILE_TYPES = [self::TYPE_IMAGE, self::TYPE_IMAGES, self::TYPE_FILE, self::TYPE_FILES, self::TYPE_VIDEO, self::TYPE_VIDEOS];

    /**
     * 需要配置选项的类型（单选、多选、下拉）
     */
    const OPTION_TYPES = [self::TYPE_RADIO, self::TYPE_CHECKBOX, self::TYPE_SELECT];

    /**
     * 获取所有表单类型选项（供前端下拉选择）
     * @return array<array{label: string, value: string}>
     */
    public static function getTypeOptions(): array
    {
        return [
            ['label' => '单行文本 (input)',     'value' => self::TYPE_INPUT],
            ['label' => '多行文本 (textarea)',  'value' => self::TYPE_TEXTAREA],
            ['label' => '数字 (number)',        'value' => self::TYPE_NUMBER],
            ['label' => '密码 (password)',      'value' => self::TYPE_PASSWORD],
            ['label' => '开关 (switch)',        'value' => self::TYPE_SWITCH],
            ['label' => '单选 (radio)',         'value' => self::TYPE_RADIO],
            ['label' => '多选 (checkbox)',      'value' => self::TYPE_CHECKBOX],
            ['label' => '下拉选择 (select)',    'value' => self::TYPE_SELECT],
            ['label' => '图片 (image)',         'value' => self::TYPE_IMAGE],
            ['label' => '多图 (images)',        'value' => self::TYPE_IMAGES],
            ['label' => '文件 (file)',          'value' => self::TYPE_FILE],
            ['label' => '多文件 (files)',       'value' => self::TYPE_FILES],
            ['label' => '视频 (video)',         'value' => self::TYPE_VIDEO],
            ['label' => '多视频 (videos)',      'value' => self::TYPE_VIDEOS],
            ['label' => '富文本 (editor)',      'value' => self::TYPE_EDITOR],
            ['label' => 'JSON',                 'value' => self::TYPE_JSON],
            ['label' => '选项列表 (option_list)', 'value' => self::TYPE_OPTION_LIST],
        ];
    }

    /**
     * 关联设置分组
     */
    public function settingGroup()
    {
        return $this->belongsTo(SettingGroup::class, 'group_id', 'id');
    }
}
