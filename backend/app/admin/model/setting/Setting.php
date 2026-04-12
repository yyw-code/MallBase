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

    /**
     * 追加到数组中的虚拟字段
     */
    protected $append = ['full_url'];

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
        ];
    }

    /**
     * full_url 获取器
     * 图片/文件类型自动拼接上传域名前缀
     * - 单文件类型（image/file）：返回完整 URL 字符串
     * - 多文件类型（images/files）：value 为 JSON 数组时返回 URL 数组，逗号分隔时返回逗号分隔的 URL 字符串
     * - 非文件类型：返回 null
     *
     * @param mixed $value  无实际值（虚拟字段）
     * @param array $data   当前记录数据
     * @return string|array|null
     */
    public function getFullUrlAttr($value, $data)
    {
        $type = $data['type'] ?? '';
        $val  = $data['value'] ?? '';

        // 非文件类型不处理
        if (!in_array($type, self::FILE_TYPES, true)) {
            return null;
        }

        // 值为空
        if ($val === '' || $val === null) {
            return '';
        }

        // 多文件类型（images/files）：可能是 JSON 数组或逗号分隔
        if (in_array($type, [self::TYPE_IMAGES, self::TYPE_FILES, self::TYPE_VIDEOS], true)) {
            $decoded = json_decode($val, true);

            // JSON 数组格式
            if (is_array($decoded)) {
                return buildUploadUrls($decoded);
            }

            // 逗号分隔格式
            if (str_contains($val, ',')) {
                $paths = explode(',', $val);
                return buildUploadUrls(array_map(static fn($path) => trim($path), $paths));
            }

            // 单个值
            return [buildUploadUrl($val)];
        }

        // 单文件类型（image/file）
        return buildUploadUrl($val);
    }

    /**
     * 关联设置分组
     */
    public function settingGroup()
    {
        return $this->belongsTo(SettingGroup::class, 'group_id', 'id');
    }
}
