<?php

declare (strict_types=1);

namespace app\admin\model\setting;

/**
 * 设置项验证规则类型常量
 * 后端统一管理，前端动态渲染规则选项
 */
class RuleType
{
    // ==================== 规则类型常量 ====================

    /** 必填 */
    const TYPE_REQUIRED = 'required';

    /** 最小长度 */
    const TYPE_MIN_LENGTH = 'minLength';

    /** 最大长度 */
    const TYPE_MAX_LENGTH = 'maxLength';

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
    const TYPE_ID_CARD = 'idCard';

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
    const TYPE_ALPHA_NUM = 'alphaNum';

    /** IP地址 */
    const TYPE_IP = 'ip';

    /** JSON格式 */
    const TYPE_JSON = 'json';

    /**
     * 获取所有规则类型定义
     * @return array
     */
    public static function getAll(): array
    {
        return [
            [
                'type'                     => self::TYPE_REQUIRED,
                'label'                    => '必填 (required)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}不能为空',
                'applicable_types'         => [],
            ],
            [
                'type'                     => self::TYPE_MIN_LENGTH,
                'label'                    => '最小长度 (minLength)',
                'need_value'               => true,
                'value_placeholder'        => '请输入最小字符数',
                'need_flags'               => false,
                'default_message_template' => '{name}最少输入{value}个字符',
                'applicable_types'         => ['input', 'textarea', 'password'],
            ],
            [
                'type'                     => self::TYPE_MAX_LENGTH,
                'label'                    => '最大长度 (maxLength)',
                'need_value'               => true,
                'value_placeholder'        => '请输入最大字符数',
                'need_flags'               => false,
                'default_message_template' => '{name}最多输入{value}个字符',
                'applicable_types'         => ['input', 'textarea', 'password'],
            ],
            [
                'type'                     => self::TYPE_MIN,
                'label'                    => '最小值 (min)',
                'need_value'               => true,
                'value_placeholder'        => '请输入最小值',
                'need_flags'               => false,
                'default_message_template' => '{name}不能小于{value}',
                'applicable_types'         => ['number'],
            ],
            [
                'type'                     => self::TYPE_MAX,
                'label'                    => '最大值 (max)',
                'need_value'               => true,
                'value_placeholder'        => '请输入最大值',
                'need_flags'               => false,
                'default_message_template' => '{name}不能大于{value}',
                'applicable_types'         => ['number'],
            ],
            [
                'type'                     => self::TYPE_PATTERN,
                'label'                    => '正则匹配 (pattern)',
                'need_value'               => true,
                'value_placeholder'        => '请输入正则表达式',
                'need_flags'               => true,
                'default_message_template' => '{name}格式不正确',
                'applicable_types'         => ['input', 'textarea'],
            ],
            [
                'type'                     => self::TYPE_EMAIL,
                'label'                    => '邮箱 (email)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '请输入正确的邮箱地址',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_URL,
                'label'                    => 'URL地址 (url)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '请输入正确的URL地址',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_PHONE,
                'label'                    => '手机号 (phone)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '请输入正确的手机号',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_ID_CARD,
                'label'                    => '身份证号 (idCard)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '请输入正确的身份证号',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_INTEGER,
                'label'                    => '整数 (integer)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}请输入整数',
                'applicable_types'         => ['input', 'number'],
            ],
            [
                'type'                     => self::TYPE_FLOAT,
                'label'                    => '小数 (float)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}请输入数字',
                'applicable_types'         => ['input', 'number'],
            ],
            [
                'type'                     => self::TYPE_DIGITS,
                'label'                    => '纯数字 (digits)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}只能包含数字',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_CHINESE,
                'label'                    => '中文 (chinese)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}只能输入中文',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_ENGLISH,
                'label'                    => '英文 (english)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}只能输入英文',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_ALPHA_NUM,
                'label'                    => '字母+数字 (alphaNum)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}只能包含字母和数字',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_IP,
                'label'                    => 'IP地址 (ip)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '请输入正确的IP地址',
                'applicable_types'         => ['input'],
            ],
            [
                'type'                     => self::TYPE_JSON,
                'label'                    => 'JSON格式 (json)',
                'need_value'               => false,
                'value_placeholder'        => '',
                'need_flags'               => false,
                'default_message_template' => '{name}格式不正确，请输入有效的JSON',
                'applicable_types'         => ['textarea', 'json'],
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
}