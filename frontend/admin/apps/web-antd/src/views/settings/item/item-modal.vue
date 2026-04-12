<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { SettingApi } from '#/api/setting';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { createSettingItemApi, updateSettingItemApi } from '#/api/setting';

const props = defineProps<{
  editData?: null | SettingApi.SettingItem;
  /** 分组选项（扁平数组，从父组件传入） */
  groupOptions: Array<{ label: string; value: number }>;
  /** 验证规则类型映射（从父组件传入，页面级缓存） */
  ruleTypesMap: SettingApi.RuleTypesMap;
  /** 表单类型下拉选项（从后端获取） */
  typeOptions: SettingApi.TypeOption[];
  /** 表单级告警（系统上限等） */
  formWarnings?: string[];
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}>();

/** 分组下拉选项（如果没有数据则添加"无"选项） */
const groupSelectOptions = computed(() => {
  if (props.groupOptions.length === 0) {
    return [{ label: '无', value: 0 }];
  }
  return props.groupOptions;
});

const isEdit = computed(() => !!props.editData);
const modalTitle = computed(() => (isEdit.value ? '编辑设置项' : '新增设置项'));
const saving = ref(false);

// 表单 ref
const formRef = ref<FormInstance>();

// 表单验证规则
const formRules: Record<string, Rule[]> = {
  group_id: [
    {
      required: true,
      message: '请选择所属分组',
      validator: (_rule: any, value: number) => {
        // 只有当有分组选项时，才验证不能选 0
        if (
          props.groupOptions.length > 0 &&
          (value === undefined || value === null || value === 0)
        ) {
          return Promise.reject('请选择所属分组');
        }
        return Promise.resolve();
      },
    },
  ],
  name: [
    { required: true, message: '请输入设置项名称', whitespace: true },
    { max: 50, message: '名称不能超过50个字符' },
  ],
  code: [
    { required: true, message: '请输入设置项编码', whitespace: true },
    {
      pattern: /^[a-z]\w*$/i,
      message: '编码只能包含英文字母、数字和下划线，且以字母开头',
    },
    { max: 50, message: '编码不能超过50个字符' },
  ],
  type: [{ required: true, message: '请选择表单类型' }],
};

/** 需要配置选项的类型 */
const needOptions = computed(() =>
  ['checkbox', 'radio', 'select'].includes(formData.value.type),
);

/** 选项编辑列表 */
const optionItems = ref<Array<{ label: string; value: string }>>([]);

/** 添加选项行 */
const addOptionItem = () => {
  optionItems.value.push({ label: '', value: '' });
};

/** 删除选项行 */
const removeOptionItem = (index: number) => {
  optionItems.value.splice(index, 1);
};

/** 从选项数组同步到 formData.options（JSON 字符串） */
const syncOptionsToForm = () => {
  const valid = optionItems.value.filter((item) => item.label || item.value);
  formData.value.options = valid.length > 0 ? JSON.stringify(valid) : '';
};

/** 从后端数据解析选项到 optionItems */
const parseOptionsToArray = (options: any) => {
  if (!options) {
    optionItems.value = [];
    return;
  }
  if (typeof options === 'string') {
    try {
      const parsed = JSON.parse(options);
      if (Array.isArray(parsed)) {
        optionItems.value = parsed.map((item: any) => ({
          label: String(item.label ?? ''),
          value: String(item.value ?? ''),
        }));
        return;
      }
    } catch {
      // JSON 解析失败，忽略
    }
    optionItems.value = [];
    return;
  }
  if (Array.isArray(options)) {
    optionItems.value = options.map((item: any) => ({
      label: String(item.label ?? ''),
      value: String(item.value ?? ''),
    }));
    return;
  }
  optionItems.value = [];
};

// ==================== 验证规则配置（从 props.ruleTypesMap 索引） ====================

/** 当前表单类型对应的验证规则列表（根据 type 从 ruleTypesMap 中索引） */
const ruleTypes = computed<SettingApi.RuleTypeItem[]>(
  () => props.ruleTypesMap[formData.value.type] || [],
);

/** 将规则类型转为下拉选项 */
const ruleTypeSelectOptions = computed(() =>
  ruleTypes.value.map((rt) => ({
    label: rt.label,
    value: rt.type,
  })),
);

/** 根据 type 查找规则类型定义 */
const getRuleTypeDef = (type: string): SettingApi.RuleTypeItem | undefined =>
  ruleTypes.value.find((rt) => rt.type === type);

const getRuleValueMax = (ruleType: string): number | undefined => {
  const max = getRuleTypeDef(ruleType)?.value_max;
  return typeof max === 'number' && Number.isFinite(max) ? max : undefined;
};

const getRuleHint = (ruleType: string): string => getRuleTypeDef(ruleType)?.hint || '';

const isRuleNumericInput = (ruleType: string): boolean =>
  [ 'max_size', 'max_count' ].includes(ruleType);

/** 创建空规则 */
const createEmptyRule = (): SettingApi.ValidationRule => ({
  type: '',
  message: '',
  value: undefined,
  flags: undefined,
});

/** 检查规则类型是否已存在（排除指定索引） */
const isRuleTypeDuplicate = (type: string, excludeIndex: number): boolean => {
  if (!type) return false;
  return formData.value.rules.some(
    (r, i) => i !== excludeIndex && r.type === type,
  );
};

/** 添加一条规则（自动选中第一个未使用的规则类型） */
const addRule = () => {
  const usedTypes = new Set(formData.value.rules.map((r) => r.type));
  const firstAvailable = ruleTypes.value.find((rt) => !usedTypes.has(rt.type));
  const rule = createEmptyRule();
  if (firstAvailable) {
    rule.type = firstAvailable.type;
    rule.message = firstAvailable.default_message_template
      ? firstAvailable.default_message_template
          .replace('{name}', formData.value.name || '此项')
          .replace('{value}', '')
      : '';
  }
  formData.value.rules.push(rule);
};

/** 删除一条规则 */
const removeRule = (index: number) => {
  formData.value.rules.splice(index, 1);
};

/** 根据规则类型模板生成 message */
const generateRuleMessage = (index: number): string => {
  const rule = formData.value.rules[index];
  if (!rule) return '';
  const def = getRuleTypeDef(rule.type);
  return def?.default_message_template
    ? def.default_message_template
        .replace('{name}', formData.value.name || '此项')
        .replace('{value}', String(rule.value ?? ''))
    : '';
};

/** 规则类型变更 */
const handleRuleTypeChange = (index: number) => {
  const rule = formData.value.rules[index];
  if (!rule) return;

  // 检查是否重复
  if (isRuleTypeDuplicate(rule.type, index)) {
    message.warning(
      `规则类型"${getRuleTypeDef(rule.type)?.label || rule.type}"已存在，不可重复添加`,
    );
    rule.type = '';
    rule.message = '';
    return;
  }

  const def = getRuleTypeDef(rule.type);

  if (!def?.need_value) {
    rule.value = undefined;
  }
  if (!def?.need_flags) {
    rule.flags = undefined;
  }

  // 切换规则类型时，始终根据新的类型模板更新 message
  rule.message = generateRuleMessage(index);
};

/** 规则 value 变更时同步更新 message 中的 {value} 占位符 */
const handleRuleValueChange = (index: number) => {
  const rule = formData.value.rules[index];
  if (!rule) return;
  const def = getRuleTypeDef(rule.type);
  // 仅当 message 仍为默认模板（用户未自定义）时才自动更新
  if (def?.default_message_template && rule.message) {
    rule.message = generateRuleMessage(index);
  }
};

/** 获取规则 options（统一归一化为 {label,value} 结构） */
const getRuleOptions = (
  ruleType: string,
): Array<{ label: string; value: string }> => {
  const def = getRuleTypeDef(ruleType);
  const rawOptions = def?.options || [];

  return rawOptions
    .map((opt) => {
      if (typeof opt === 'string') {
        return { label: opt, value: opt };
      }
      if (
        opt &&
        typeof opt === 'object' &&
        typeof (opt as any).label === 'string' &&
        typeof (opt as any).value === 'string'
      ) {
        return { label: (opt as any).label, value: (opt as any).value };
      }
      return null;
    })
    .filter((opt): opt is { label: string; value: string } => Boolean(opt));
};

/** 处理 options 复选框变更 */
const handleRuleOptionsChange = (index: number, checkedValues: string[]) => {
  const rule = formData.value.rules[index];
  if (!rule) return;
  rule.value = checkedValues as any;
  // 同步更新 message 中的 {value} 占位符
  const def = getRuleTypeDef(rule.type);
  if (def?.default_message_template) {
    rule.message = generateRuleMessage(index);
  }
};

// ==================== 表单数据 ====================

interface FormData {
  group_id: number;
  code: string;
  is_required: number;
  name: string;
  options: string;
  placeholder: string;
  remark: string;
  rules: SettingApi.ValidationRule[];
  sort: number;
  type: string;
  value: string;
}

const formData = ref<FormData>({
  group_id: 0,
  name: '',
  code: '',
  value: '',
  type: 'input',
  options: '',
  placeholder: '',
  remark: '',
  sort: 0,
  is_required: 0,
  rules: [],
});

/** 打开弹窗时初始化 */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      formRef.value?.clearValidate();

      if (props.editData) {
        const item = props.editData;
        formData.value = {
          group_id: item.group_id,
          name: item.name,
          code: item.code,
          value: item.value || '',
          type: item.type,
          options: '',
          placeholder: item.placeholder || '',
          remark: item.remark || '',
          sort: item.sort,
          is_required: item.is_required,
          rules: item.rules ? [...item.rules] : [],
        };
        parseOptionsToArray(item.options);
      } else {
        // 新增时，默认选中第一个分组，如果没有分组则默认为 0
        const defaultGroupId =
          props.groupOptions.length > 0 ? props.groupOptions[0].value : 0;
        formData.value = {
          group_id: defaultGroupId,
          name: '',
          code: '',
          value: '',
          type: 'input',
          options: '',
          placeholder: '',
          remark: '',
          sort: 0,
          is_required: 0,
          rules: [],
        };
        optionItems.value = [];
      }
    }
  },
);

/** 关闭弹窗 */
const handleCancel = () => {
  emit('update:visible', false);
};

/** 提交 */
const handleOk = async () => {
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  // 提交前同步选项数据
  if (needOptions.value) {
    syncOptionsToForm();
    const validItems = optionItems.value.filter(
      (item) => item.label && item.value,
    );
    if (validItems.length === 0) {
      message.warning('请至少添加一个有效选项（标签和值都不能为空）');
      return;
    }
  }

  for (let i = 0; i < formData.value.rules.length; i++) {
    const rule = formData.value.rules[i];
    if (!rule) continue;
    if (!rule.type) {
      message.warning(`第 ${i + 1} 条验证规则未选择类型`);
      return;
    }
    if (isRuleTypeDuplicate(rule.type, i)) {
      message.warning(
        `验证规则"${getRuleTypeDef(rule.type)?.label || rule.type}"重复，每条规则类型只能添加一次`,
      );
      return;
    }
    const def = getRuleTypeDef(rule.type);
    if (def?.need_value && !rule.value && rule.value !== 0) {
      message.warning(`第 ${i + 1} 条验证规则请填写规则参数`);
      return;
    }
  }

  saving.value = true;
  try {
    const serializedRules = formData.value.rules.map((rule, index) => {
      const def = getRuleTypeDef(rule.type);
      // 提交时重新生成 message，确保 {value} 已替换为最新值
      const finalMessage = rule.message || generateRuleMessage(index) || '';
      const cleaned: SettingApi.ValidationRule = {
        type: rule.type,
        message: finalMessage,
      };
      if (def?.need_value) {
        cleaned.value = rule.value;
      }
      if (def?.need_flags && rule.flags) {
        cleaned.flags = rule.flags;
      }
      return cleaned;
    });

    const submitData: any = {
      ...formData.value,
      options: formData.value.options || null,
      rules: serializedRules.length > 0 ? serializedRules : null,
    };

    if (isEdit.value && props.editData) {
      const updateResult = await updateSettingItemApi(props.editData.id, submitData);
      if (Array.isArray(updateResult?.warnings) && updateResult.warnings.length > 0) {
        message.warning(updateResult.warnings.join('；'));
      }
      message.success('更新成功');
    } else {
      const createResult = await createSettingItemApi(submitData);
      if (Array.isArray(createResult?.warnings) && createResult.warnings.length > 0) {
        message.warning(createResult.warnings.join('；'));
      }
      message.success('创建成功');
    }
    emit('update:visible', false);
    emit('success');
  } catch (error) {
    console.error('保存失败:', error);
    message.error('保存失败');
  } finally {
    saving.value = false;
  }
};
</script>

<template>
  <a-modal
    :open="visible"
    :title="modalTitle"
    :confirm-loading="saving"
    width="680px"
    @ok="handleOk"
    @cancel="handleCancel"
  >
    <div class="form-scroll-wrapper">
      <a-form
        ref="formRef"
        :model="formData"
        :rules="formRules"
        :label-col="{ span: 5 }"
        :wrapper-col="{ span: 18 }"
        class="mt-4"
      >
        <a-form-item label="所属分组" name="group_id">
          <a-select
            v-model:value="formData.group_id"
            placeholder="请选择所属分组"
            allow-clear
            show-search
            :options="groupSelectOptions"
          />
        </a-form-item>

        <a-form-item label="名称" name="name">
          <a-input v-model:value="formData.name" placeholder="如：AppID" />
        </a-form-item>

        <a-form-item label="编码" name="code">
          <a-input
            v-model:value="formData.code"
            placeholder="如：wechat_appid"
          />
        </a-form-item>

        <a-form-item label="表单类型" name="type">
          <a-select
            v-model:value="formData.type"
            :options="typeOptions"
            placeholder="请选择表单类型"
          />
        </a-form-item>

        <a-form-item label="默认值" name="value">
          <a-input v-model:value="formData.value" placeholder="默认值" />
        </a-form-item>

        <a-form-item v-if="needOptions" label="选项" name="options">
          <div class="options-config">
            <div
              v-for="(item, index) in optionItems"
              :key="index"
              class="option-row"
            >
              <a-input
                v-model:value="item.label"
                placeholder="标签（如：启用）"
                class="option-label-input"
              />
              <a-input
                v-model:value="item.value"
                placeholder="值（如：1）"
                class="option-value-input"
              />
              <a-button
                type="text"
                danger
                size="small"
                @click="removeOptionItem(index)"
              >
                删除
              </a-button>
            </div>
            <a-button type="dashed" block @click="addOptionItem">
              <template #icon>
                <span class="i-ant-design:plus-outlined mr-1"></span>
              </template>
              添加选项
            </a-button>
          </div>
        </a-form-item>

        <a-form-item label="占位提示" name="placeholder">
          <a-input
            v-model:value="formData.placeholder"
            placeholder="输入框提示文字"
          />
        </a-form-item>

        <a-form-item label="备注" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            placeholder="设置项说明"
            :rows="2"
          />
        </a-form-item>

        <a-form-item label="排序" name="sort">
          <a-input-number
            v-model:value="formData.sort"
            :min="0"
            class="w-full"
          />
        </a-form-item>

        <!-- 验证规则配置 -->
        <a-form-item label="验证规则">
          <div class="rules-config">
            <div v-if="(formWarnings || []).length > 0" class="rule-tip rule-tip-warning mb-2">
              {{ (formWarnings || []).join('；') }}
            </div>
            <div
              v-for="(rule, index) in formData.rules"
              :key="index"
              class="rule-item"
            >
              <div class="rule-row">
                <a-select
                  v-model:value="rule.type"
                  :options="ruleTypeSelectOptions"
                  placeholder="请选择规则类型"
                  class="rule-type-select"
                  @change="handleRuleTypeChange(index)"
                />
                <!-- 有 options 时显示复选框 -->
                <template v-if="getRuleOptions(rule.type).length > 0">
                  <a-checkbox-group
                    :value="Array.isArray(rule.value) ? rule.value : []"
                    class="rule-options-group"
                    @change="
                      (vals: any[]) => handleRuleOptionsChange(index, vals)
                    "
                  >
                    <a-checkbox
                      v-for="opt in getRuleOptions(rule.type)"
                      :key="opt.value"
                      :value="opt.value"
                    >
                      {{ opt.label }}
                    </a-checkbox>
                  </a-checkbox-group>
                </template>
                <!-- 无 options 但 need_value 时显示输入框 -->
                <template v-else-if="getRuleTypeDef(rule.type)?.need_value">
                  <a-input-number
                    v-if="isRuleNumericInput(rule.type)"
                    v-model:value="rule.value"
                    :min="rule.type === 'max_count' ? 1 : 0.1"
                    :max="getRuleValueMax(rule.type)"
                    :step="rule.type === 'max_count' ? 1 : 0.1"
                    :precision="rule.type === 'max_count' ? 0 : 2"
                    :placeholder="
                      getRuleTypeDef(rule.type)?.value_placeholder || '参数值'
                    "
                    class="rule-value-input"
                    @change="handleRuleValueChange(index)"
                  />
                  <a-input
                    v-else
                    v-model:value="rule.value"
                    :placeholder="
                      getRuleTypeDef(rule.type)?.value_placeholder || '参数值'
                    "
                    class="rule-value-input"
                    @change="handleRuleValueChange(index)"
                  />
                </template>
                <a-button
                  type="text"
                  danger
                  size="small"
                  class="rule-remove-btn"
                  @click="removeRule(index)"
                >
                  删除
                </a-button>
              </div>
              <div class="rule-row" style="margin-top: 6px">
                <a-input
                  v-model:value="rule.message"
                  :placeholder="`验证失败提示（为空则后端自动生成，默认：${getRuleTypeDef(rule.type)?.default_message_template || ''}）`"
                  class="rule-message-input"
                />
                <a-input
                  v-if="getRuleTypeDef(rule.type)?.need_flags"
                  v-model:value="rule.flags"
                  placeholder="标志如 i"
                  class="rule-flags-input"
                />
              </div>
              <div v-if="getRuleHint(rule.type)" class="rule-tip rule-tip-warning mt-2">
                {{ getRuleHint(rule.type) }}
              </div>
            </div>

            <a-button
              type="dashed"
              block
              :disabled="ruleTypes.length === 0"
              @click="addRule"
            >
              <template #icon>
                <span class="i-ant-design:plus-outlined mr-1"></span>
              </template>
              添加验证规则
            </a-button>

            <div
              v-if="ruleTypes.length === 0"
              class="rule-tip rule-tip-warning"
            >
              当前表单类型暂无可用的验证规则
            </div>
            <div
              v-if="formData.rules.length === 0 && ruleTypes.length > 0"
              class="rule-tip"
            >
              不添加规则时，将使用「必填」字段进行简单验证
            </div>
          </div>
        </a-form-item>
      </a-form>
    </div>
  </a-modal>
</template>

<style lang="css" scoped>
.options-config {
  width: 100%;
}

.option-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}

.option-label-input {
  flex: 1;
}

.option-value-input {
  flex: 1;
}

.rules-config {
  width: 100%;
}

.rule-item {
  margin-bottom: 12px;
  padding: 12px;
  background: hsl(var(--popover));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.rule-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.rule-type-select {
  width: 180px;
  flex-shrink: 0;
}

.rule-value-input {
  flex: 1;
  min-width: 80px;
}

.rule-options-group {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.rule-message-input {
  flex: 1;
}

.rule-flags-input {
  width: 80px;
  flex-shrink: 0;
}

.rule-remove-btn {
  flex-shrink: 0;
}

.rule-tip {
  margin-top: 8px;
  font-size: 12px;
  color: hsl(var(--muted-foreground));
}

.rule-tip-warning {
  color: hsl(var(--warning));
}

.rule-options-group :deep(.ant-checkbox-wrapper) {
  color: hsl(var(--foreground));
}
</style>
