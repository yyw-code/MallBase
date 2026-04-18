<?php

declare (strict_types=1);

namespace app\model\setting;

use app\service\UploadService;

/**
 * 设置项验证规则类型常量
 * 后端统一管理，前端动态渲染规则选项
 */
class RuleType
{
    /**
     * MIME 展示名称映射（用于前端规则配置展示）
     * value 永远保留 MIME 原值，label 为短名称
     *
     * @var array<string, string>
     */
    private const MIME_LABEL_MAP = [
        'application/pdf' => 'PDF 文档 (.pdf)',
        'application/msword' => 'Word 文档 (.doc)',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word 文档 (.docx)',
        'application/vnd.ms-excel' => 'Excel 表格 (.xls)',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel 表格 (.xlsx)',
        'application/vnd.ms-powerpoint' => 'PPT 演示文稿 (.ppt)',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PPT 演示文稿 (.pptx)',
        'application/zip' => 'ZIP 压缩包 (.zip)',
        'application/x-zip-compressed' => 'ZIP 压缩包 (.zip)',
        'application/vnd.rar' => 'RAR 压缩包 (.rar)',
        'application/x-rar' => 'RAR 压缩包 (.rar)',
        'application/x-rar-compressed' => 'RAR 压缩包 (.rar)',
        'application/x-7z-compressed' => '7Z 压缩包 (.7z)',
        'application/x-tar' => 'TAR 压缩包 (.tar)',
        'application/gzip' => 'GZIP 压缩包 (.gz)',
        'text/plain' => '文本文件 (.txt)',
        'text/csv' => 'CSV 文件 (.csv)',
        'application/csv' => 'CSV 文件 (.csv)',
        'audio/mpeg' => 'MP3 音频 (.mp3)',
        'audio/mp3' => 'MP3 音频 (.mp3)',
        'image/jpeg' => 'JPEG 图片 (.jpeg)',
        'image/jpg' => 'JPG 图片 (.jpg)',
        'image/png' => 'PNG 图片 (.png)',
        'image/gif' => 'GIF 图片 (.gif)',
        'image/webp' => 'WEBP 图片 (.webp)',
        'video/mp4' => 'MP4 视频 (.mp4)',
        'video/quicktime' => 'MOV 视频 (.mov)',
        'video/x-msvideo' => 'AVI 视频 (.avi)',
        'video/x-matroska' => 'MKV 视频 (.mkv)',
        'video/x-flv' => 'FLV 视频 (.flv)',
        'video/x-ms-wmv' => 'WMV 视频 (.wmv)',
        'video/webm' => 'WEBM 视频 (.webm)',
        'video/mp2t' => 'TS 视频 (.ts)',
    ];

    // ==================== 规则类型常量 ====================

    /** 必填 */
    const TYPE_REQUIRED = 'required';

    /** 最小长度 */
    const TYPE_MIN_LENGTH = 'min_length';

    /** 最大长度 */
    const TYPE_MAX_LENGTH = 'max_length';

    /** 最小值 */
    const TYPE_MIN = 'min';

    /** 最大值 */
    const TYPE_MAX = 'max';

    /** 正则匹配 */
    const TYPE_PATTERN = 'pattern';

    /** 邮箱 */
    const TYPE_EMAIL = 'email';

    /** URL地址 */
    const TYPE_URL = 'url';

    /** 手机号 */
    const TYPE_PHONE = 'phone';

    /** 身份证号 */
    const TYPE_ID_CARD = 'id_card';

    /** 整数 */
    const TYPE_INTEGER = 'integer';

    /** 小数 */
    const TYPE_FLOAT = 'float';

    /** 纯数字 */
    const TYPE_DIGITS = 'digits';

    /** 中文 */
    const TYPE_CHINESE = 'chinese';

    /** 英文 */
    const TYPE_ENGLISH = 'english';

    /** 字母+数字 */
    const TYPE_ALPHA_NUM = 'alpha_num';

    /** IP地址 */
    const TYPE_IP = 'ip';

    /** JSON格式 */
    const TYPE_JSON = 'json';

    /** 文件大小限制 (MB)，与 upload config key 一致 */
    const TYPE_MAX_FILE_SIZE = 'max_size';

    /** 文件数量限制，与 upload config key 一致 */
    const TYPE_MAX_FILE_COUNT = 'max_count';

    /** 文件类型限制，与 upload config key 一致 */
    const TYPE_ACCEPT_TYPES = 'accept_types';

    /**
     * 获取所有规则类型定义
     * @return array
     */
    public static function getAll(): array
    {
        /**
         * @param string $type 规则类型标识，如 required、max_length
         * @param string $label 规则类型显示名称，如 "文件大小限制"
         * @param bool $need_value 是否需要用户填写参数值
         * @param string $value_placeholder 【可选】 参数值输入框的占位提示
         * @param bool $need_flags 【可选】  是否需要正则标志（如 i、g）
         * @param string $default_message_template 默认错误提示模板，支持 {name} 和 {value} 占位符
         * @param string[] $applicable_types 【可选】  预定义选项列表，有值时渲染为复选框
         */
        return [
            [
                'type' => self::TYPE_REQUIRED,
                'label' => '必填 (required)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}不能为空',
                'applicable_types' => [],
            ],
            [
                'type' => self::TYPE_MIN_LENGTH,
                'label' => '最小长度 (min_length)',
                'need_value' => true,
                'value_placeholder' => '请输入最小字符数',
                'need_flags' => false,
                'default_message_template' => '{name}最少输入{value}个字符',
                'applicable_types' => ['input', 'textarea', 'password'],
            ],
            [
                'type' => self::TYPE_MAX_LENGTH,
                'label' => '最大长度 (max_length)',
                'need_value' => true,
                'value_placeholder' => '请输入最大字符数',
                'need_flags' => false,
                'default_message_template' => '{name}最多输入{value}个字符',
                'applicable_types' => ['input', 'textarea', 'password'],
            ],
            [
                'type' => self::TYPE_MIN,
                'label' => '最小值 (min)',
                'need_value' => true,
                'value_placeholder' => '请输入最小值',
                'need_flags' => false,
                'default_message_template' => '{name}不能小于{value}',
                'applicable_types' => ['number'],
            ],
            [
                'type' => self::TYPE_MAX,
                'label' => '最大值 (max)',
                'need_value' => true,
                'value_placeholder' => '请输入最大值',
                'need_flags' => false,
                'default_message_template' => '{name}不能大于{value}',
                'applicable_types' => ['number'],
            ],
            [
                'type' => self::TYPE_PATTERN,
                'label' => '正则匹配 (pattern)',
                'need_value' => true,
                'value_placeholder' => '请输入正则表达式',
                'need_flags' => true,
                'default_message_template' => '{name}格式不正确',
                'applicable_types' => ['input', 'textarea'],
            ],
            [
                'type' => self::TYPE_EMAIL,
                'label' => '邮箱 (email)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '请输入正确的邮箱地址',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_URL,
                'label' => 'URL地址 (url)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '请输入正确的URL地址',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_PHONE,
                'label' => '手机号 (phone)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '请输入正确的手机号',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_ID_CARD,
                'label' => '身份证号 (id_card)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '请输入正确的身份证号',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_INTEGER,
                'label' => '整数 (integer)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}请输入整数',
                'applicable_types' => ['input', 'number'],
            ],
            [
                'type' => self::TYPE_FLOAT,
                'label' => '小数 (float)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}请输入数字',
                'applicable_types' => ['input', 'number'],
            ],
            [
                'type' => self::TYPE_DIGITS,
                'label' => '纯数字 (digits)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}只能包含数字',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_CHINESE,
                'label' => '中文 (chinese)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}只能输入中文',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_ENGLISH,
                'label' => '英文 (english)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}只能输入英文',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_ALPHA_NUM,
                'label' => '字母+数字 (alpha_num)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}只能包含字母和数字',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_IP,
                'label' => 'IP地址 (ip)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '请输入正确的IP地址',
                'applicable_types' => ['input'],
            ],
            [
                'type' => self::TYPE_JSON,
                'label' => 'JSON格式 (json)',
                'need_value' => false,
                'value_placeholder' => '',
                'need_flags' => false,
                'default_message_template' => '{name}格式不正确，请输入有效的JSON',
                'applicable_types' => ['textarea', 'json'],
            ],
            [
                'type' => self::TYPE_MAX_FILE_SIZE,
                'label' => '文件大小限制 (max_size)',
                'need_value' => true,
                'value_placeholder' => '请输入最大文件大小（MB）',
                'value_type' => 'number',
                'need_flags' => false,
                'default_message_template' => '文件大小不能超过{value}MB',
                'applicable_types' => ['image', 'images', 'file', 'files', 'video', 'videos'],
            ],
            [
                'type' => self::TYPE_MAX_FILE_COUNT,
                'label' => '文件数量限制 (max_count)',
                'need_value' => true,
                'value_placeholder' => '请输入最大文件数量',
                'value_type' => 'number',
                'need_flags' => false,
                'default_message_template' => '{name}最多上传{value}个文件',
                'applicable_types' => ['images', 'files', 'videos'],
            ],
            [
                'type' => self::TYPE_ACCEPT_TYPES,
                'label' => '文件类型限制 (accept_types)',
                'need_value' => true,
                'value_placeholder' => '',
                'value_type' => 'array',
                'need_flags' => false,
                'default_message_template' => '支持的文件类型:{value}',
                'applicable_types' => ['image', 'images', 'file', 'files', 'video', 'videos'],
                'need_options' => true,
            ],
        ];
    }

    /**
     * 获取所有规则类型标识列表
     * @return string[]
     */
    public static function getTypeList(): array
    {
        return array_column(self::getAll(), 'type');
    }

    /**
     * 获取 acceptTypes 的选项（从 upload config 读取）
     * 返回每种上传类型的 accept_types 供前端选择
     *
     * @return array
     */
    public static function getAcceptTypeOptions(): array
    {
        $rules = UploadService::getRules();

        $options = [];
        foreach ($rules as $type => $rule) {
            $options[] = [
                'label' => $type,
                'value' => self::formatAcceptTypeOptions((array)($rule['accept_types'] ?? [])),
                'upload_type' => $type,
            ];
        }

        return $options;
    }

    /**
     * 将 MIME 列表格式化为前端展示选项
     *
     * @param string[] $acceptTypes
     * @return array<int, array{label: string, value: string}>
     */
    public static function formatAcceptTypeOptions(array $acceptTypes): array
    {
        $result = [];
        $seen = [];

        foreach ($acceptTypes as $mime) {
            if (!is_string($mime) || $mime === '') {
                continue;
            }
            if (isset($seen[$mime])) {
                continue;
            }
            $seen[$mime] = true;
            $result[] = [
                'label' => self::MIME_LABEL_MAP[$mime] ?? $mime,
                'value' => $mime,
            ];
        }

        return $result;
    }
}
