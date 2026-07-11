<?php

declare (strict_types=1);

namespace app\validate\admin\setting;

use app\model\setting\RuleType;
use think\Validate;

/**
 * 设置项验证器
 */
class SettingItem extends Validate
{
    private const UI_COMPONENTS = ['money_yuan', 'remote_select'];

    private const UI_OPTION_SOURCES = ['distribution_level'];

    /**
     * 验证规则
     */
    protected $rule = [
        'group_id|分组ID' => 'require|number',
        'name|设置项名称' => 'require|max:100',
        'code|设置项编码' => 'require|alphaDash|max:50',
        'value|设置值' => 'max:65535',
        'type|表单类型' => 'in:input,textarea,number,password,switch,radio,checkbox,select,image,images,file,files,video,videos,editor,json,option_list',
        'options|选项' => 'checkOptions',
        'rules|验证规则' => 'checkRules',
        'ui|动态表单配置' => 'checkUi',
        'placeholder|输入提示' => 'max:255',
        'remark|备注说明' => 'max:255',
        'sort|排序' => 'number|between:0,9999',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => [
            'group_id',
            'name',
            'code',
            'value',
            'type',
            'options',
            'rules',
            'ui',
            'placeholder',
            'remark',
            'sort',
        ],
        'update' => [
            'name',
            'code',
            'value',
            'type',
            'options',
            'rules',
            'ui',
            'placeholder',
            'remark',
            'sort',
        ],
        'uiUpdate' => [
            'ui',
        ],
    ];

    /**
     * 验证 options 字段（JSON 数组）
     *
     * @param mixed $value
     * @return bool|string
     */
    protected function checkOptions($value)
    {
        if (empty($value)) {
            return true;
        }

        // 如果是字符串，尝试解析
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return '选项必须是有效的JSON格式';
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            return '选项必须是数组格式';
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return '选项的每一项必须是对象，包含 label 和 value';
            }
            if (!isset($item['label']) || !isset($item['value'])) {
                return '选项的每一项必须包含 label 和 value';
            }
        }

        return true;
    }

    /**
     * 验证 rules 字段（验证规则数组）
     *
     * @param mixed $value
     * @return bool|string
     */
    protected function checkRules($value)
    {
        if (empty($value)) {
            return true;
        }

        // 如果是字符串，尝试解析
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return '验证规则必须是有效的JSON格式';
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            return '验证规则必须是数组格式';
        }

        $validTypes = RuleType::getTypeList();

        foreach ($value as $index => $rule) {
            // 每条规则必须是数组/对象
            if (!is_array($rule)) {
                return "第" . ($index + 1) . "条规则格式不正确，必须是对象";
            }

            // 必须包含 type 字段
            if (empty($rule['type'])) {
                return "第" . ($index + 1) . "条规则缺少 type 字段";
            }

            // type 必须是合法的规则类型
            if (!in_array($rule['type'], $validTypes, true)) {
                return "第" . ($index + 1) . "条规则的类型 '{$rule['type']}' 不合法";
            }

            // 根据 RuleType 定义检查是否需要 value
            $ruleTypeDefinition = $this->findRuleTypeDefinition($rule['type']);
            if ($ruleTypeDefinition && $ruleTypeDefinition['need_value']) {
                if (!isset($rule['value']) || $rule['value'] === '') {
                    return "第" . ($index + 1) . "条规则（{$rule['type']}）需要配置 value 参数";
                }
            }

            // 如果是 pattern 类型且有 flags，验证 flags 格式
            if ($rule['type'] === RuleType::TYPE_PATTERN && isset($rule['flags'])) {
                if (!preg_match('/^[gimsuy]*$/', $rule['flags'])) {
                    return "第" . ($index + 1) . "条规则的正则标志不合法";
                }
            }

            // 如果是 pattern 类型，验证正则表达式是否合法
            if ($rule['type'] === RuleType::TYPE_PATTERN && isset($rule['value'])) {
                $pattern = '/' . str_replace('/', '\\/', $rule['value']) . '/';
                if (@preg_match($pattern, '') === false) {
                    return "第" . ($index + 1) . "条规则的正则表达式不合法";
                }
            }

            // 如果是 min_length/max_length，验证 value 是否为正整数
            if (in_array($rule['type'], [RuleType::TYPE_MIN_LENGTH, RuleType::TYPE_MAX_LENGTH], true)) {
                if (isset($rule['value']) && (!is_numeric($rule['value']) || intval($rule['value']) < 1)) {
                    return "第" . ($index + 1) . "条规则的 value 必须是大于0的整数";
                }
            }

            // 如果是 min/max，验证 value 是否为数字
            if (in_array($rule['type'], [RuleType::TYPE_MIN, RuleType::TYPE_MAX], true)) {
                if (isset($rule['value']) && !is_numeric($rule['value'])) {
                    return "第" . ($index + 1) . "条规则的 value 必须是数字";
                }
            }

            // 如果是 max_size，验证 value 是否为正数（MB）
            if ($rule['type'] === RuleType::TYPE_MAX_FILE_SIZE) {
                if (isset($rule['value']) && (!is_numeric($rule['value']) || floatval($rule['value']) <= 0)) {
                    return "第" . ($index + 1) . "条规则的 value 必须是大于0的数字（MB）";
                }
            }

            // 如果是 max_count，验证 value 是否为正整数
            if ($rule['type'] === RuleType::TYPE_MAX_FILE_COUNT) {
                if (isset($rule['value']) && (!is_numeric($rule['value']) || intval($rule['value']) < 1)) {
                    return "第" . ($index + 1) . "条规则的 value 必须是大于0的整数";
                }
            }

            // 如果是 accept_types，验证 value 是否为非空数组
            if ($rule['type'] === RuleType::TYPE_ACCEPT_TYPES) {
                if (isset($rule['value'])) {
                    if (!is_array($rule['value']) || empty($rule['value'])) {
                        return "第" . ($index + 1) . "条规则的 value 必须是非空数组";
                    }
                    foreach ($rule['value'] as $mime) {
                        if (!is_string($mime) || empty($mime)) {
                            return "第" . ($index + 1) . "条规则的 value 每项必须是非空字符串（MIME 类型）";
                        }
                    }
                }
            }

            // message 字段如果存在，不能超过 255 字符
            if (isset($rule['message']) && mb_strlen($rule['message']) > 255) {
                return "第" . ($index + 1) . "条规则的提示信息不能超过255个字符";
            }
        }

        return true;
    }

    /**
     * 验证 ui 字段（后台动态表单元数据）
     *
     * @param mixed $value
     * @return bool|string
     */
    protected function checkUi($value)
    {
        if (empty($value)) {
            return true;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return '动态表单配置必须是有效的JSON格式';
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            return '动态表单配置必须是对象格式';
        }

        $component = (string)($value['component'] ?? '');
        if (
            $component !== ''
            && !in_array($component, self::UI_COMPONENTS, true)
        ) {
            return '输入组件不合法';
        }

        $optionSource = (string)($value['option_source'] ?? '');
        if (
            $optionSource !== ''
            && !in_array($optionSource, self::UI_OPTION_SOURCES, true)
        ) {
            return '远程选项来源不合法';
        }

        $sectionCode = (string)($value['section_code'] ?? '');
        if (
            $sectionCode !== ''
            && !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $sectionCode)
        ) {
            return '页内分组编码不合法';
        }

        $conditions = $value['visible_when'] ?? [];
        if ($conditions === null || $conditions === '') {
            return true;
        }

        if (!is_array($conditions)) {
            return '显示条件必须是数组格式';
        }

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                return "第" . ($index + 1) . "条显示条件格式不正确";
            }

            $field = (string)($condition['field'] ?? '');
            if (
                $field === ''
                || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field)
            ) {
                return "第" . ($index + 1) . "条显示条件的依赖字段不合法";
            }

            $operator = (string)($condition['operator'] ?? 'equals');
            if (
                !in_array(
                    $operator,
                    ['equals', 'falsy', 'in', 'not_equals', 'truthy'],
                    true
                )
            ) {
                return "第" . ($index + 1) . "条显示条件的判断方式不合法";
            }

            if (
                in_array($operator, ['equals', 'not_equals'], true)
                && !array_key_exists('value', $condition)
            ) {
                return "第" . ($index + 1) . "条显示条件缺少匹配值";
            }

            if (
                $operator === 'in'
                && (
                    !isset($condition['value'])
                    || !is_array($condition['value'])
                    || empty($condition['value'])
                )
            ) {
                return "第" . ($index + 1) . "条显示条件的匹配值必须是非空数组";
            }
        }

        return true;
    }

    /**
     * 查找规则类型定义
     *
     * @param string $type
     * @return array|null
     */
    protected function findRuleTypeDefinition(string $type): ?array
    {
        foreach (RuleType::getAll() as $definition) {
            if ($definition['type'] === $type) {
                return $definition;
            }
        }
        return null;
    }
}
