<?php

declare (strict_types=1);

namespace app\validate\admin\setting;

use app\model\setting\RuleType;
use think\Validate;

/**
 * 设置项值验证器（根据 rules 配置验证 saveConfig 提交的值）
 */
class SettingValueValidate extends Validate
{
    /**
     * 验证分组配置提交值
     * 根据每个设置项的 rules 动态验证
     *
     * @param array $settings 设置项列表（含 rules 字段）
     * @param array $values   提交的值 [code => value, ...]
     * @return array 验证错误列表 [code => message, ...]
     */
    public function validateGroupValues(array $settings, array $values): array
    {
        $errors = [];

        foreach ($settings as $setting) {
            $code  = $setting['code'];
            $name  = $setting['name'];
            $rules = $setting['rules'] ?? [];

            // 没有配置规则则跳过验证
            if (empty($rules) || !is_array($rules)) {
                continue;
            }

            // 获取提交的值（未提交则为 null）
            $value = $values[$code] ?? null;

            foreach ($rules as $rule) {
                if (empty($rule['type'])) {
                    continue;
                }

                $message = $this->validateSingleRule($rule, $value, $name);
                if ($message !== true) {
                    $errors[$code] = $message;
                    break; // 每个设置项只返回第一条错误
                }
            }
        }

        return $errors;
    }

    /**
     * 验证单条规则
     *
     * @param array  $rule  规则定义 [type, value, message, flags]
     * @param mixed  $value 待验证的值
     * @param string $name  设置项名称（用于生成默认提示）
     * @return true|string true=验证通过，string=错误提示
     */
    protected function validateSingleRule(array $rule, mixed $value, string $name): true|string
    {
        $type      = $rule['type'];
        $ruleValue = $rule['value'] ?? null;
        $message   = $rule['message'] ?? '';

        return match ($type) {
            RuleType::TYPE_REQUIRED   => $this->ruleRequired($value, $name, $message),
            RuleType::TYPE_MIN_LENGTH => $this->ruleMinLength($value, $ruleValue, $name, $message),
            RuleType::TYPE_MAX_LENGTH => $this->ruleMaxLength($value, $ruleValue, $name, $message),
            RuleType::TYPE_MIN        => $this->ruleMin($value, $ruleValue, $name, $message),
            RuleType::TYPE_MAX        => $this->ruleMax($value, $ruleValue, $name, $message),
            RuleType::TYPE_PATTERN    => $this->rulePattern($value, $ruleValue, $rule['flags'] ?? '', $name, $message),
            RuleType::TYPE_EMAIL      => $this->ruleEmail($value, $name, $message),
            RuleType::TYPE_URL        => $this->ruleUrl($value, $name, $message),
            RuleType::TYPE_PHONE      => $this->rulePhone($value, $name, $message),
            RuleType::TYPE_ID_CARD    => $this->ruleIdCard($value, $name, $message),
            RuleType::TYPE_INTEGER    => $this->ruleInteger($value, $name, $message),
            RuleType::TYPE_FLOAT      => $this->ruleFloat($value, $name, $message),
            RuleType::TYPE_DIGITS     => $this->ruleDigits($value, $name, $message),
            RuleType::TYPE_CHINESE    => $this->ruleChinese($value, $name, $message),
            RuleType::TYPE_ENGLISH    => $this->ruleEnglish($value, $name, $message),
            RuleType::TYPE_ALPHA_NUM  => $this->ruleAlphaNum($value, $name, $message),
            RuleType::TYPE_IP              => $this->ruleIp($value, $name, $message),
            RuleType::TYPE_JSON            => $this->ruleJson($value, $name, $message),
            RuleType::TYPE_MAX_FILE_SIZE   => $this->ruleMaxFileSize($value, $ruleValue, $name, $message),
            RuleType::TYPE_MAX_FILE_COUNT  => $this->ruleMaxFileCount($value, $ruleValue, $name, $message),
            RuleType::TYPE_ACCEPT_TYPES    => $this->ruleAcceptTypes($value, $ruleValue, $name, $message),
            default                        => true,
        };
    }

    // ==================== 单条规则验证方法 ====================

    protected function ruleRequired(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return $message ?: "{$name}不能为空";
        }
        return true;
    }

    protected function ruleMinLength(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true; // 非必填时，空值跳过
        }
        if (mb_strlen((string)$value) < (int)$ruleValue) {
            return $message ?: "{$name}最少输入{$ruleValue}个字符";
        }
        return true;
    }

    protected function ruleMaxLength(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (mb_strlen((string)$value) > (int)$ruleValue) {
            return $message ?: "{$name}最多输入{$ruleValue}个字符";
        }
        return true;
    }

    protected function ruleMin(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_numeric($value) || (float)$value < (float)$ruleValue) {
            return $message ?: "{$name}不能小于{$ruleValue}";
        }
        return true;
    }

    protected function ruleMax(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_numeric($value) || (float)$value > (float)$ruleValue) {
            return $message ?: "{$name}不能大于{$ruleValue}";
        }
        return true;
    }

    protected function rulePattern(mixed $value, mixed $ruleValue, string $flags, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        $delimiter = '/';
        $pattern   = $delimiter . str_replace($delimiter, '\\' . $delimiter, (string)$ruleValue) . $delimiter;
        if ($flags) {
            $pattern .= $flags;
        }
        if (!preg_match($pattern, (string)$value)) {
            return $message ?: "{$name}格式不正确";
        }
        return true;
    }

    protected function ruleEmail(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $message ?: '请输入正确的邮箱地址';
        }
        return true;
    }

    protected function ruleUrl(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return $message ?: '请输入正确的URL地址';
        }
        return true;
    }

    protected function rulePhone(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match('/^1[3-9]\d{9}$/', (string)$value)) {
            return $message ?: '请输入正确的手机号';
        }
        return true;
    }

    protected function ruleIdCard(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match('/(^\d{15}$)|(^\d{18}$)|(^\d{17}(\d|X|x)$)/', (string)$value)) {
            return $message ?: '请输入正确的身份证号';
        }
        return true;
    }

    protected function ruleInteger(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match('/^-?\d+$/', (string)$value)) {
            return $message ?: "{$name}请输入整数";
        }
        return true;
    }

    protected function ruleFloat(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_numeric($value)) {
            return $message ?: "{$name}请输入数字";
        }
        return true;
    }

    protected function ruleDigits(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!ctype_digit((string)$value)) {
            return $message ?: "{$name}只能包含数字";
        }
        return true;
    }

    protected function ruleChinese(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', (string)$value)) {
            return $message ?: "{$name}只能输入中文";
        }
        return true;
    }

    protected function ruleEnglish(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match('/^[A-Za-z]+$/', (string)$value)) {
            return $message ?: "{$name}只能输入英文";
        }
        return true;
    }

    protected function ruleAlphaNum(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match('/^[A-Za-z0-9]+$/', (string)$value)) {
            return $message ?: "{$name}只能包含字母和数字";
        }
        return true;
    }

    protected function ruleIp(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!filter_var($value, FILTER_VALIDATE_IP)) {
            return $message ?: '请输入正确的IP地址';
        }
        return true;
    }

    protected function ruleJson(mixed $value, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        json_decode((string)$value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $message ?: "{$name}格式不正确，请输入有效的JSON";
        }
        return true;
    }

    protected function ruleMaxFileSize(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        // 文件大小验证在 upload 时已由 UploadService 完成
        // 这里仅做前端展示用，保存时不做实际校验
        return true;
    }

    protected function ruleMaxFileCount(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        // 多文件数量验证
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $count = count($decoded);
        } elseif (str_contains((string)$value, ',')) {
            $count = count(explode(',', (string)$value));
        } else {
            $count = 1;
        }

        if ($count > (int)$ruleValue) {
            return $message ?: "{$name}最多上传{$ruleValue}个文件";
        }
        return true;
    }

    protected function ruleAcceptTypes(mixed $value, mixed $ruleValue, string $name, string $message): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        // 文件类型验证在 upload 时已由 UploadService 完成
        // 这里仅做前端展示用，保存时不做实际校验
        return true;
    }
}
