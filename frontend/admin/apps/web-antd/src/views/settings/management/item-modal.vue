<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { SettingApi } from '#/api/setting';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createSettingItemApi,
  updateSettingItemApi,
} from '#/api/setting';

const props = defineProps<{
  editData?: null | SettingApi.SettingItem;
  groupId: number;
  /** 验证规则类型映射（从父组件传入，页面级缓存） */
  ruleTypesMap: SettingApi.RuleTypesMap;
  /** 表单类型下拉选项（从后端获取） */
  typeOptions: SettingApi.TypeOption[];
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}>();

const isEdit = computed(() => !!props.editData);
const modalTitle = computed(() => (isEdit.value ? '编辑设置项' : '新增设置项'));
const saving = ref(false);

// 表单 ref
const formRef = ref<FormInstance>();

// 表单验证规则
const formRules: Record<string, Rule[]> = {
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

/** 创建空规则 */
const createEmptyRule = (): SettingApi.ValidationRule => ({
  type: '',
  message: '',
  value: undefined,
  flags: undefined,
});

/** 添加一条规则 */
const addRule = () => {
  formData.value.rules.push(createEmptyRule());
};

/** 删除一条规则 */
const removeRule = (index: number) => {
  formData.value.rules.splice(index, 1);
};

/** 规则类型变更 */
const handleRuleTypeChange = (index: number) => {
  const rule = formData.value.rules[index];
  if (!rule) return;
  const def = getRuleTypeDef(rule.type);

  if (!def?.need_value) {
    rule.value = undefined;
  }
  if (!def?.need_flags) {
    rule.flags = undefined;
  }

  // 切换规则类型时，始终根据新的类型模板更新 message
  rule.message = def?.default_message_template
    ? def.default_message_template
        .replace('{name}', formData.value.name || '此项')
        .replace('{value}', String(rule.value || ''))
    : '';
};

/** 获取规则的 options 复选框配置 */
const getRuleOptions = (
  ruleType: string,
): string[] => {
  const def = getRuleTypeDef(ruleType);
  return def?.options || [];
};

/** 处理 options 复选框变更 */
const handleRuleOptionsChange = (
  index: number,
  checkedValues: string[],
) => {
  const rule = formData.value.rules[index];
  if (!rule) return;
  rule.value = checkedValues;
};

// ==================== 表单数据 ====================

interface FormData {
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
        const optionsStr =
          typeof item.options === 'string'
            ? item.options || ''
            : item.options
              ? JSON.stringify(item.options, null, 2)
              : '';
        formData.value = {
          name: item.name,
          code: item.code,
          value: item.value || '',
          type: item.type,
          options: optionsStr,
          placeholder: item.placeholder || '',
          remark: item.remark || '',
          sort: item.sort,
          is_required: item.is_required,
          rules: item.rules ? [...item.rules] : [],
        };
      } else {
        formData.value = {
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

  if (needOptions.value && formData.value.options) {
    try {
      JSON.parse(formData.value.options);
    } catch {
      message.warning('选项 JSON 格式不正确');
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
    const def = getRuleTypeDef(rule.type);
    if (def?.need_value && !rule.value && rule.value !== 0) {
      message.warning(`第 ${i + 1} 条验证规则请填写规则参数`);
      return;
    }
  }

  saving.value = true;
  try {
    const serializedRules = formData.value.rules.map((rule) => {
      const def = getRuleTypeDef(rule.type);
      const cleaned: SettingApi.ValidationRule = {
        type: rule.type,
        message: rule.message,
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
      await updateSettingItemApi(props.editData.id, submitData);
      message.success('更新成功');
    } else {
      submitData.group_id = props.groupId;
      await createSettingItemApi(submitData);
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
    <a-form
      ref="formRef"
      :model="formData"
      :rules="formRules"
      :label-col="{ span: 5 }"
      :wrapper-col="{ span: 18 }"
      class="mt-4"
    >
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
        <a-textarea
          v-model:value="formData.options"
          placeholder='[{"label":"启用","value":"1"},{"label":"禁用","value":"0"}]'
          :rows="4"
          class="font-mono"
        />
        <div class="mt-1 text-xs text-gray-400">请输入 JSON 数组格式</div>
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
                  @change="(vals: any[]) => handleRuleOptionsChange(index, vals)"
                >
                  <a-checkbox
                    v-for="opt in getRuleOptions(rule.type)"
                    :key="opt"
                    :value="opt"
                  >
                    {{ opt }}
                  </a-checkbox>
                </a-checkbox-group>
              </template>
              <!-- 无 options 但 need_value 时显示输入框 -->
              <template
                v-else-if="getRuleTypeDef(rule.type)?.need_value"
              >
                <a-input
                  v-model:value="rule.value"
                  :placeholder="
                    getRuleTypeDef(rule.type)?.value_placeholder || '参数值'
                  "
                  class="rule-value-input"
                />
              </template>
              <a-button
                type="text"
                danger
                size="small"
                class="rule-remove-btn"
                @click="removeRule(index)"
              >
                <template #icon>
                  <span class="i-ant-design:delete-outlined"></span>
                </template>
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
  </a-modal>
</template>

<style lang="css" scoped>
.rules-config {
  width: 100%;
}

.rule-item {
  margin-bottom: 12px;
  padding: 12px;
  background: #fafafa;
  border: 1px solid #f0f0f0;
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
  color: #8c8c8c;
}

.rule-tip-warning {
  color: #fa8c16;
}
</style>
