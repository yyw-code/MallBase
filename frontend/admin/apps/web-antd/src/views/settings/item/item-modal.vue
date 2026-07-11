<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { SettingApi } from '#/api/setting';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { invalidateUploadConfig } from '#/api/core/upload-config-cache';
import {
  createSettingItemApi,
  getSettingItemListApi,
  getSettingSectionListApi,
  updateSettingItemApi,
} from '#/api/setting';

type GroupOption = {
  disabled?: boolean;
  label: string;
  value: number;
};

type UiComponentValue = '' | SettingApi.UiComponentValue;
type UiOptionSourceValue = '' | SettingApi.UiOptionSourceValue;
type UiOperatorValue = 'equals' | 'falsy' | 'in' | 'not_equals' | 'truthy';

interface UiConditionForm {
  field: string;
  operator: UiOperatorValue;
  value?: string | string[];
}

const props = defineProps<{
  editData?: null | SettingApi.SettingItem;
  /** 表单级告警（系统上限等） */
  formWarnings?: string[];
  /** 分组选项（扁平数组，从父组件传入） */
  groupOptions: GroupOption[];
  /** 验证规则类型映射（从父组件传入，页面级缓存） */
  ruleTypesMap: SettingApi.RuleTypesMap;
  /** 表单类型下拉选项（从后端获取） */
  typeOptions: SettingApi.TypeOption[];
  /** 动态表单输入组件选项 */
  uiComponentOptions?: SettingApi.UiComponentOption[];
  /** 远程下拉可用数据源 */
  uiOptionSourceOptions?: SettingApi.UiOptionSourceOption[];
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
const drawerWidth = 'min(860px, calc(100vw - 24px))';
const saving = ref(false);
const activeTab = ref('base');
const isSystemSetting = computed(
  () => isEdit.value && Number(props.editData?.is_system || 0) === 1,
);
const hasInheritedUiConditions = computed(() => {
  const savedConditions = props.editData?.ui?.visible_when;
  const resolvedConditions = props.editData?.resolved_ui?.visible_when;
  return (
    (!Array.isArray(savedConditions) || savedConditions.length === 0) &&
    Array.isArray(resolvedConditions) &&
    resolvedConditions.length > 0
  );
});

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
          return Promise.reject(new Error('请选择所属分组'));
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

const getRuleHint = (ruleType: string): string =>
  getRuleTypeDef(ruleType)?.hint || '';

const isRuleNumericInput = (ruleType: string): boolean =>
  ['max_count', 'max_size'].includes(ruleType);

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
    .filter((opt): opt is { label: string; value: string } => opt !== null);
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

const FALLBACK_UI_COMPONENT_OPTIONS: SettingApi.UiComponentOption[] = [
  {
    description: '按表单类型自动渲染',
    label: '默认组件',
    value: '',
  },
  {
    description: '元输入，分保存',
    label: '金额输入',
    value: 'money_yuan',
  },
  {
    description: '从系统数据源选择',
    label: '远程下拉',
    value: 'remote_select',
  },
];

const FALLBACK_UI_OPTION_SOURCE_OPTIONS: SettingApi.UiOptionSourceOption[] = [
  {
    description: '分销模块等级列表',
    label: '分销等级',
    value: 'distribution_level',
  },
];

const uiComponent = ref<UiComponentValue>('');
const uiOptionSource = ref<UiOptionSourceValue>('');
const uiSectionCode = ref('');
const conditionItems = ref<UiConditionForm[]>([]);
const dependencyLoading = ref(false);
const dependencySettings = ref<SettingApi.SettingItem[]>([]);
const sectionLoading = ref(false);
const sectionItems = ref<SettingApi.SettingSection[]>([]);
const initialEditableUiSignature = ref('');
const initialSavedUiPayload = ref<null | SettingApi.SettingItemUi>(null);

const rawUiComponentOptions = computed(() =>
  props.uiComponentOptions && props.uiComponentOptions.length > 0
    ? props.uiComponentOptions
    : FALLBACK_UI_COMPONENT_OPTIONS,
);

const uiOptionSourceSelectOptions = computed(() =>
  (props.uiOptionSourceOptions && props.uiOptionSourceOptions.length > 0
    ? props.uiOptionSourceOptions
    : FALLBACK_UI_OPTION_SOURCE_OPTIONS
  ).map((item) => ({
    label: item.description
      ? `${item.label}（${item.description}）`
      : item.label,
    value: item.value,
  })),
);

const uiComponentPickerOptions = computed(() =>
  rawUiComponentOptions.value
    .filter(
      (item) =>
        item.value !== 'remote_select' ||
        uiComponent.value === 'remote_select' ||
        uiOptionSourceSelectOptions.value.length > 1,
    )
    .map((item) => ({
      description: item.description || '',
      label: item.label,
      value: item.value as UiComponentValue,
    })),
);

const dependencyFieldOptions = computed(() =>
  dependencySettings.value
    .filter((item) => {
      if (props.editData?.id && item.id === props.editData.id) return false;
      if (formData.value.code && item.code === formData.value.code) {
        return false;
      }
      return ['radio', 'select', 'switch'].includes(item.type);
    })
    .map((item) => ({
      label: `${item.name}（${item.code}）`,
      value: item.code,
    })),
);

const sectionSelectOptions = computed(() =>
  sectionItems.value.map((item) => ({
    label: item.name,
    value: item.code,
  })),
);

const resetUiConfig = () => {
  uiComponent.value = '';
  uiOptionSource.value = '';
  uiSectionCode.value = '';
  conditionItems.value = [];
};

const normalizeUiConditionValue = (
  value: SettingApi.UiCondition['value'],
): string | string[] | undefined => {
  if (Array.isArray(value)) {
    return value.map(String);
  }
  if (value === undefined) {
    return undefined;
  }
  return String(value);
};

const extractEditableUi = (
  ui?: null | SettingApi.SettingItemUi,
): null | SettingApi.SettingItemUi => {
  if (!ui) return null;

  const payload: SettingApi.SettingItemUi = {};
  if (ui.component) {
    payload.component = ui.component;
  }
  if (ui.component === 'remote_select' && ui.option_source) {
    payload.option_source = ui.option_source;
  }

  const sectionCode = String(ui.section_code || '').trim();
  if (sectionCode) {
    payload.section_code = sectionCode.slice(0, 64);
  }

  if (Array.isArray(ui.visible_when) && ui.visible_when.length > 0) {
    const visibleWhen = ui.visible_when
      .filter((condition) => condition.field)
      .map((condition) => {
        const normalized: SettingApi.UiCondition = {
          field: condition.field,
          operator: condition.operator || 'equals',
        };
        if (Array.isArray(condition.value)) {
          normalized.value = condition.value.map(String);
        } else if (condition.value !== undefined) {
          normalized.value = String(condition.value);
        }
        return normalized;
      });
    if (visibleWhen.length > 0) {
      payload.visible_when = visibleWhen;
    }
  }

  return Object.keys(payload).length > 0 ? payload : null;
};

const getUiPayloadSignature = (ui: null | SettingApi.SettingItemUi): string =>
  JSON.stringify(extractEditableUi(ui));

const resolveEditableUi = (
  item: SettingApi.SettingItem,
): null | SettingApi.SettingItemUi =>
  extractEditableUi(item.resolved_ui) || extractEditableUi(item.ui);

const parseUiToForm = (ui?: null | SettingApi.SettingItemUi) => {
  resetUiConfig();
  if (!ui) return;

  uiComponent.value = (ui.component || '') as UiComponentValue;
  uiOptionSource.value = (ui.option_source || '') as UiOptionSourceValue;
  uiSectionCode.value = ui.section_code || '';
  conditionItems.value = (ui.visible_when || []).map((condition) => ({
    field: condition.field,
    operator: (condition.operator || 'equals') as UiOperatorValue,
    value: normalizeUiConditionValue(condition.value),
  }));
};

const loadDependencySettings = async (groupId: number) => {
  if (!groupId) {
    dependencySettings.value = [];
    return;
  }

  dependencyLoading.value = true;
  try {
    const data = await getSettingItemListApi({
      group_id: groupId,
      limit: 200,
      page: 1,
    });
    dependencySettings.value = data.list || [];
  } catch (error) {
    console.error('加载依赖字段失败:', error);
    dependencySettings.value = [];
  } finally {
    dependencyLoading.value = false;
  }
};

const loadSections = async (groupId: number) => {
  if (!groupId) {
    sectionItems.value = [];
    return;
  }

  sectionLoading.value = true;
  try {
    sectionItems.value = await getSettingSectionListApi(groupId);
  } catch (error) {
    console.error('加载页内分组失败:', error);
    sectionItems.value = [];
  } finally {
    sectionLoading.value = false;
  }
};

const getDependencySetting = (code: string) =>
  dependencySettings.value.find((item) => item.code === code);

const getDependencyValueOptions = (code: string) => {
  const setting = getDependencySetting(code);
  const options = setting?.options || [];
  if (typeof options === 'string') {
    try {
      const parsed = JSON.parse(options);
      return Array.isArray(parsed)
        ? parsed.map((item: any) => ({
            label: String(item.label ?? ''),
            value: String(item.value ?? ''),
          }))
        : [];
    } catch {
      return [];
    }
  }
  if (!Array.isArray(options)) return [];
  return options.map((item: any) => ({
    label: String(item.label ?? ''),
    value: String(item.value ?? ''),
  }));
};

const getConditionOperatorOptions = (condition: UiConditionForm) => {
  const setting = getDependencySetting(condition.field);
  if (setting?.type === 'switch') {
    return [
      { label: '开启时显示', value: 'truthy' },
      { label: '关闭时显示', value: 'falsy' },
    ];
  }
  return [
    { label: '等于任一值', value: 'in' },
    { label: '等于', value: 'equals' },
    { label: '不等于', value: 'not_equals' },
  ];
};

const handleConditionFieldChange = (condition: UiConditionForm) => {
  const setting = getDependencySetting(condition.field);
  condition.operator = setting?.type === 'switch' ? 'truthy' : 'in';
  condition.value = setting?.type === 'switch' ? undefined : [];
};

const handleConditionOperatorChange = (condition: UiConditionForm) => {
  const setting = getDependencySetting(condition.field);
  if (setting?.type === 'switch') {
    condition.value = undefined;
    return;
  }
  condition.value = condition.operator === 'in' ? [] : '';
};

const handleUiComponentChange = (value: UiComponentValue) => {
  uiComponent.value = value;
  if (value !== 'remote_select') {
    uiOptionSource.value = '';
  }
};

const handleGroupChange = (value: number) => {
  uiSectionCode.value = '';
  conditionItems.value = [];
  loadSections(Number(value || 0));
  loadDependencySettings(Number(value || 0));
};

const addConditionItem = () => {
  conditionItems.value.push({
    field: '',
    operator: 'truthy',
    value: undefined,
  });
};

const removeConditionItem = (index: number) => {
  conditionItems.value.splice(index, 1);
};

const resolveSubmitUiPayload = (
  uiPayload: null | SettingApi.SettingItemUi,
): null | SettingApi.SettingItemUi => {
  if (getUiPayloadSignature(uiPayload) === initialEditableUiSignature.value) {
    return initialSavedUiPayload.value;
  }
  if (isSystemSetting.value) {
    return uiPayload?.section_code
      ? { section_code: uiPayload.section_code }
      : null;
  }
  return uiPayload;
};

const buildUiPayload = (): null | SettingApi.SettingItemUi => {
  const payload: SettingApi.SettingItemUi = {};

  if (uiComponent.value) {
    payload.component = uiComponent.value;
  }
  if (uiComponent.value === 'remote_select') {
    if (!uiOptionSource.value) {
      message.warning('请选择远程下拉的数据源');
      throw new Error('missing option source');
    }
    payload.option_source = uiOptionSource.value;
  }

  const sectionCode = uiSectionCode.value.trim();
  if (sectionCode) {
    payload.section_code = sectionCode.slice(0, 64);
  }

  const visibleWhen: SettingApi.UiCondition[] = [];
  for (let i = 0; i < conditionItems.value.length; i++) {
    const condition = conditionItems.value[i];
    if (!condition) continue;
    if (!condition.field) {
      message.warning(`第 ${i + 1} 条显示条件未选择依赖字段`);
      throw new Error('missing condition field');
    }

    const setting = getDependencySetting(condition.field);
    if (!setting) {
      message.warning(`第 ${i + 1} 条显示条件的依赖字段不存在`);
      throw new Error('invalid condition field');
    }

    if (setting.type === 'switch') {
      visibleWhen.push({
        field: condition.field,
        operator: condition.operator === 'falsy' ? 'falsy' : 'truthy',
      });
      continue;
    }

    if (condition.operator === 'in') {
      const values = Array.isArray(condition.value) ? condition.value : [];
      if (values.length === 0) {
        message.warning(`第 ${i + 1} 条显示条件未选择匹配值`);
        throw new Error('missing condition values');
      }
      visibleWhen.push({
        field: condition.field,
        operator: 'in',
        value: values,
      });
      continue;
    }

    const value = Array.isArray(condition.value)
      ? condition.value[0]
      : condition.value;
    if (!value) {
      message.warning(`第 ${i + 1} 条显示条件未选择匹配值`);
      throw new Error('missing condition value');
    }
    visibleWhen.push({
      field: condition.field,
      operator: condition.operator === 'not_equals' ? 'not_equals' : 'equals',
      value,
    });
  }

  if (visibleWhen.length > 0) {
    payload.visible_when = visibleWhen;
  }

  return Object.keys(payload).length > 0 ? payload : null;
};

/** 打开弹窗时初始化 */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      activeTab.value = 'base';
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
        const editableUi = resolveEditableUi(item);
        initialSavedUiPayload.value = extractEditableUi(item.ui);
        initialEditableUiSignature.value = getUiPayloadSignature(editableUi);
        parseUiToForm(editableUi);
        loadSections(item.group_id);
        loadDependencySettings(item.group_id);
      } else {
        // 新增时，默认选中第一个分组，如果没有分组则默认为 0
        const defaultGroupId =
          props.groupOptions.find((item) => !item.disabled)?.value || 0;
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
        resetUiConfig();
        initialSavedUiPayload.value = null;
        initialEditableUiSignature.value = getUiPayloadSignature(null);
        loadSections(defaultGroupId);
        loadDependencySettings(defaultGroupId);
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

  let uiPayload: null | SettingApi.SettingItemUi = null;
  try {
    uiPayload = resolveSubmitUiPayload(buildUiPayload());
  } catch {
    return;
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

    const submitData: any = isSystemSetting.value
      ? { ui: uiPayload }
      : {
          ...formData.value,
          options: formData.value.options || null,
          rules: serializedRules.length > 0 ? serializedRules : null,
          ui: uiPayload,
        };

    if (isEdit.value && props.editData) {
      const updateResult = await updateSettingItemApi(
        props.editData.id,
        submitData,
      );
      if (
        Array.isArray(updateResult?.warnings) &&
        updateResult.warnings.length > 0
      ) {
        message.warning(updateResult.warnings.join('；'));
      }
      message.success('更新成功');
    } else {
      const createResult = await createSettingItemApi(submitData);
      if (
        Array.isArray(createResult?.warnings) &&
        createResult.warnings.length > 0
      ) {
        message.warning(createResult.warnings.join('；'));
      }
      message.success('创建成功');
    }
    // 设置项的 rules 可能影响 /config/uploadConfig 的输出，主动失效缓存
    invalidateUploadConfig();
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
  <a-drawer
    :open="visible"
    :title="modalTitle"
    :width="drawerWidth"
    class="setting-item-drawer"
    placement="right"
    @close="handleCancel"
  >
    <div class="form-scroll-wrapper">
      <a-alert
        v-if="isSystemSetting"
        class="mb-4"
        message="系统内置设置项基础信息只允许查看，仅可调整页内分组归属。"
        show-icon
        type="info"
      />
      <a-form
        ref="formRef"
        :model="formData"
        :rules="formRules"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-tabs v-model:active-key="activeTab" class="setting-item-tabs">
          <a-tab-pane key="base" tab="基础信息">
            <a-form-item label="所属分组" name="group_id">
              <a-select
                v-model:value="formData.group_id"
                :disabled="isSystemSetting"
                placeholder="请选择所属分组"
                allow-clear
                show-search
                option-filter-prop="label"
                :options="groupSelectOptions"
                @change="handleGroupChange"
              />
            </a-form-item>

            <a-form-item label="名称" name="name">
              <a-input
                v-model:value="formData.name"
                :disabled="isSystemSetting"
                placeholder="如：AppID"
              />
            </a-form-item>

            <a-form-item label="编码" name="code">
              <a-input
                v-model:value="formData.code"
                :disabled="isSystemSetting"
                placeholder="如：wechat_appid"
              />
            </a-form-item>

            <a-form-item label="表单类型" name="type">
              <a-select
                v-model:value="formData.type"
                :disabled="isSystemSetting"
                :options="typeOptions"
                placeholder="请选择表单类型"
              />
            </a-form-item>

            <a-form-item label="默认值" name="value">
              <a-input-password
                v-if="formData.type === 'password'"
                v-model:value="formData.value"
                :disabled="isSystemSetting"
                :placeholder="
                  isEdit && editData?.has_value
                    ? '已设置，留空表示不修改'
                    : '请输入密码'
                "
                autocomplete="new-password"
              />
              <a-input
                v-else
                v-model:value="formData.value"
                :disabled="isSystemSetting"
                placeholder="默认值"
              />
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
                    :disabled="isSystemSetting"
                    placeholder="标签（如：启用）"
                    class="option-label-input"
                  />
                  <a-input
                    v-model:value="item.value"
                    :disabled="isSystemSetting"
                    placeholder="值（如：1）"
                    class="option-value-input"
                  />
                  <a-button
                    type="text"
                    danger
                    :disabled="isSystemSetting"
                    size="small"
                    @click="removeOptionItem(index)"
                  >
                    删除
                  </a-button>
                </div>
                <a-button
                  type="dashed"
                  block
                  :disabled="isSystemSetting"
                  @click="addOptionItem"
                >
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
                :disabled="isSystemSetting"
                placeholder="输入框提示文字"
              />
            </a-form-item>

            <a-form-item label="备注" name="remark">
              <a-textarea
                v-model:value="formData.remark"
                :disabled="isSystemSetting"
                placeholder="设置项说明"
                :rows="2"
              />
            </a-form-item>

            <a-form-item label="排序" name="sort">
              <a-input-number
                v-model:value="formData.sort"
                :disabled="isSystemSetting"
                :min="0"
                class="w-full"
              />
            </a-form-item>

            <a-form-item label="输入组件">
              <div class="component-config">
                <div class="component-picker">
                  <button
                    v-for="option in uiComponentPickerOptions"
                    :key="option.value || 'default'"
                    type="button"
                    class="component-option"
                    :class="{
                      'component-option-active': uiComponent === option.value,
                    }"
                    :disabled="isSystemSetting"
                    @click="handleUiComponentChange(option.value)"
                  >
                    <span class="component-option-label">{{
                      option.label
                    }}</span>
                    <span class="component-option-desc">
                      {{ option.description || '-' }}
                    </span>
                  </button>
                </div>

                <div
                  v-if="uiComponent === 'remote_select'"
                  class="component-source"
                >
                  <span class="component-source-label">系统数据源</span>
                  <a-select
                    v-model:value="uiOptionSource"
                    :disabled="isSystemSetting"
                    :options="uiOptionSourceSelectOptions"
                    class="component-source-select"
                    placeholder="请选择系统数据源"
                  />
                </div>
              </div>
            </a-form-item>

            <a-form-item label="显示条件">
              <div class="condition-config">
                <div class="condition-header">
                  <a-button
                    size="small"
                    type="dashed"
                    :disabled="
                      isSystemSetting || dependencyFieldOptions.length === 0
                    "
                    @click="addConditionItem"
                  >
                    添加条件
                  </a-button>
                </div>
                <div class="rule-tip">
                  判断规则：只能依赖当前分组的开关、单选、下拉字段；开关判断开启/关闭，
                  单选和下拉判断等于、不等于、等于任一值。
                </div>
                <div v-if="hasInheritedUiConditions" class="rule-tip">
                  已加载代码默认显示条件，未修改保存时不会写入数据库。
                </div>

                <div
                  v-if="dependencyFieldOptions.length === 0"
                  class="rule-tip"
                >
                  当前分组暂无可作为显示条件的依赖字段
                </div>

                <div
                  v-for="(condition, index) in conditionItems"
                  :key="index"
                  class="condition-item"
                >
                  <a-select
                    v-model:value="condition.field"
                    :disabled="isSystemSetting"
                    :loading="dependencyLoading"
                    :options="dependencyFieldOptions"
                    class="condition-field-select"
                    placeholder="选择依赖字段"
                    show-search
                    option-filter-prop="label"
                    @change="() => handleConditionFieldChange(condition)"
                  />
                  <a-select
                    v-model:value="condition.operator"
                    :disabled="isSystemSetting || !condition.field"
                    :options="getConditionOperatorOptions(condition)"
                    class="condition-operator-select"
                    placeholder="判断方式"
                    @change="() => handleConditionOperatorChange(condition)"
                  />
                  <a-select
                    v-if="
                      condition.field &&
                      getDependencySetting(condition.field)?.type !== 'switch'
                    "
                    v-model:value="condition.value"
                    :disabled="isSystemSetting || !condition.operator"
                    :mode="condition.operator === 'in' ? 'multiple' : undefined"
                    :options="getDependencyValueOptions(condition.field)"
                    class="condition-value-select"
                    placeholder="选择匹配值"
                  />
                  <a-button
                    type="text"
                    danger
                    :disabled="isSystemSetting"
                    size="small"
                    class="condition-remove-btn"
                    @click="removeConditionItem(index)"
                  >
                    删除
                  </a-button>
                </div>
              </div>
            </a-form-item>

            <!-- 验证规则配置 -->
            <a-form-item label="验证规则">
              <div class="rules-config">
                <div
                  v-if="(formWarnings || []).length > 0"
                  class="rule-tip rule-tip-warning mb-2"
                >
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
                      :disabled="isSystemSetting"
                      :options="ruleTypeSelectOptions"
                      placeholder="请选择规则类型"
                      class="rule-type-select"
                      @change="handleRuleTypeChange(index)"
                    />
                    <!-- 有 options 时显示复选框 -->
                    <template v-if="getRuleOptions(rule.type).length > 0">
                      <a-checkbox-group
                        :value="Array.isArray(rule.value) ? rule.value : []"
                        :disabled="isSystemSetting"
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
                        :disabled="isSystemSetting"
                        :min="rule.type === 'max_count' ? 1 : 0.1"
                        :max="getRuleValueMax(rule.type)"
                        :step="rule.type === 'max_count' ? 1 : 0.1"
                        :precision="rule.type === 'max_count' ? 0 : 2"
                        :placeholder="
                          getRuleTypeDef(rule.type)?.value_placeholder ||
                          '参数值'
                        "
                        class="rule-value-input"
                        @change="handleRuleValueChange(index)"
                      />
                      <a-input
                        v-else
                        v-model:value="rule.value"
                        :disabled="isSystemSetting"
                        :placeholder="
                          getRuleTypeDef(rule.type)?.value_placeholder ||
                          '参数值'
                        "
                        class="rule-value-input"
                        @change="handleRuleValueChange(index)"
                      />
                    </template>
                    <a-button
                      type="text"
                      danger
                      :disabled="isSystemSetting"
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
                      :disabled="isSystemSetting"
                      :placeholder="`验证失败提示（为空则后端自动生成，默认：${getRuleTypeDef(rule.type)?.default_message_template || ''}）`"
                      class="rule-message-input"
                    />
                    <a-input
                      v-if="getRuleTypeDef(rule.type)?.need_flags"
                      v-model:value="rule.flags"
                      :disabled="isSystemSetting"
                      placeholder="标志如 i"
                      class="rule-flags-input"
                    />
                  </div>
                  <div
                    v-if="getRuleHint(rule.type)"
                    class="rule-tip rule-tip-warning mt-2"
                  >
                    {{ getRuleHint(rule.type) }}
                  </div>
                </div>

                <a-button
                  type="dashed"
                  block
                  :disabled="isSystemSetting || ruleTypes.length === 0"
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
          </a-tab-pane>

          <a-tab-pane key="section" tab="页内分组">
            <a-form-item label="页内分组">
              <a-select
                v-model:value="uiSectionCode"
                :loading="sectionLoading"
                :options="sectionSelectOptions"
                allow-clear
                placeholder="请选择页内分组"
                show-search
                option-filter-prop="label"
              />
              <div class="rule-tip">页内分组在设置分组编辑中统一维护。</div>
            </a-form-item>
          </a-tab-pane>
        </a-tabs>
      </a-form>
    </div>
    <template #footer>
      <div class="drawer-footer">
        <a-button @click="handleCancel">取消</a-button>
        <a-button
          type="primary"
          :loading="saving"
          @click="handleOk"
          v-access:code="isEdit ? 'SettingItemUpdate' : 'SettingItemCreate'"
        >
          确定
        </a-button>
      </div>
    </template>
  </a-drawer>
</template>

<style lang="css" scoped>
.form-scroll-wrapper {
  width: 100%;
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.setting-item-drawer :deep(.ant-drawer-body) {
  padding-bottom: 12px;
}

.setting-item-drawer :deep(.ant-drawer-footer) {
  padding: 12px 24px;
  background: hsl(var(--background));
  border-top: 1px solid hsl(var(--border));
}

.setting-item-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 16px;
}

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

.condition-config {
  width: 100%;
}

.condition-header {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  color: hsl(var(--muted-foreground));
}

.condition-item {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-top: 8px;
  padding: 10px;
  background: hsl(var(--popover));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.condition-field-select {
  flex: 1.2;
  min-width: 160px;
}

.condition-operator-select {
  width: 130px;
  flex-shrink: 0;
}

.condition-value-select {
  flex: 1;
  min-width: 140px;
}

.condition-remove-btn {
  flex-shrink: 0;
}

.component-config {
  width: 100%;
}

.component-picker {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 8px;
}

.component-option {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-height: 62px;
  padding: 10px 12px;
  text-align: left;
  cursor: pointer;
  background: hsl(var(--popover));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;
}

.component-option:hover,
.component-option-active {
  border-color: hsl(var(--primary));
  box-shadow: 0 0 0 1px hsl(var(--primary) / 20%);
}

.component-option-label {
  font-size: 13px;
  font-weight: 500;
  color: hsl(var(--foreground));
}

.component-option-desc {
  font-size: 12px;
  line-height: 1.4;
  color: hsl(var(--muted-foreground));
}

.component-source {
  margin-top: 8px;
}

.component-source-label {
  display: block;
  margin-bottom: 6px;
  font-size: 12px;
  color: hsl(var(--muted-foreground));
}

.component-source-select {
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
