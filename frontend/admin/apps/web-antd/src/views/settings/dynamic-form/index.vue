<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { JsonViewer } from '@vben/common-ui';

import { message, Spin } from 'ant-design-vue';

import { getSettingConfigApi, saveSettingConfigApi } from '#/api/setting';
import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

defineOptions({ name: 'SettingDynamicForm' });

const route = useRoute();
const loading = ref(false);
const saving = ref(false);
const groupInfo = ref<SettingApi.ConfigResponse['group']>();
const settings = ref<SettingApi.SettingItem[]>([]);
const formValues = ref<Record<string, any>>({});
const formErrors = reactive<Record<string, string>>({});

// 选项卡模式
const isTabMode = ref(false);
const hasTabs = ref(false); // 是否有 tab 子分组（区分纯 page 和 page+tabs）
const tabs = ref<SettingApi.TabConfigItem[]>([]);
const activeTab = ref('');
// Page 自己的设置项（Page+Tabs 模式下使用）
const pageSettings = ref<SettingApi.SettingItem[]>([]);

/** API 基础地址，用于图片/文件回显兜底 */
const apiBaseUrl = import.meta.env.VITE_GLOB_API_URL || '';

/** 将相对路径转为完整 URL（兜底用，优先使用后端返回的 full_url） */
const toFullUrl = (path: string) => {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  return `${apiBaseUrl}${path}`;
};

/** 从路由路径中提取 groupCode */
const groupCode = computed(() => {
  const path = route.path;
  const segments = path.split('/').filter(Boolean);
  return segments[segments.length - 1] || '';
});

/** 获取当前激活选项卡的设置项 */
const activeTabConfig = computed(() =>
  tabs.value.find((tab) => tab.code === activeTab.value),
);

const currentTabSettings = computed(() => {
  if (!isTabMode.value && !hasTabs.value) return settings.value;
  return activeTabConfig.value?.settings || [];
});

/** 获取当前保存范围内的设置项 */
const currentSaveSettings = computed(() => {
  if (isTabMode.value) return currentTabSettings.value;
  if (hasTabs.value)
    return [...pageSettings.value, ...currentTabSettings.value];
  return settings.value;
});

/** 解析 JSON 字符串为对象（给 JsonViewer 用） */
const getJsonObject = (code: string) => {
  const val = formValues.value[code];
  if (!val) return {};
  if (typeof val === 'object') return val;
  try {
    return JSON.parse(val);
  } catch {
    return {};
  }
};

// ==================== 表单验证 ====================

/** 常用验证正则 */
const REGEX_MAP: Record<string, RegExp> = {
  alpha_num: /^[a-z0-9]+$/i,
  chinese: /^[\u4E00-\u9FA5]+$/,
  digits: /^\d+$/,
  email: /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/,
  english: /^[a-z]+$/i,
  float: /^-?\d+(\.\d+)?$/,
  id_card: /^\d{17}[\dX]$/i,
  integer: /^-?\d+$/,
  ip: /^(\d{1,3}\.){3}\d{1,3}$/,
  phone: /^1[3-9]\d{9}$/,
  url: /^https?:\/\/([\w-]+\.)+[\w-]+(\/[\w\-./?%&=@]*)?$/,
};

/**
 * 根据后端返回的 rules 验证单个字段
 * @returns 错误信息，验证通过返回空字符串
 */
const validateFieldValue = (
  item: SettingApi.SettingItem,
  value: any,
): string => {
  // 优先使用后端返回的 rules
  if (item.rules && item.rules.length > 0) {
    for (const rule of item.rules) {
      const error = applyRule(rule, value, item);
      if (error) return error;
    }
    return '';
  }

  // 兜底：兼容旧的 is_required 逻辑
  if (
    item.is_required === 1 &&
    (value === undefined ||
      value === null ||
      value === '' ||
      (Array.isArray(value) && value.length === 0))
  ) {
    return `请填写「${item.name}」`;
  }

  // json 类型特殊验证
  if (item.type === 'json' && value) {
    try {
      JSON.parse(value);
    } catch {
      return `「${item.name}」JSON 格式不正确`;
    }
  }

  return '';
};

/** 应用单条验证规则 */
const applyRule = (
  rule: SettingApi.ValidationRule,
  value: any,
  item: SettingApi.SettingItem,
): string => {
  const strVal = value === undefined || value === null ? '' : String(value);
  const isEmpty =
    value === undefined ||
    value === null ||
    value === '' ||
    (Array.isArray(value) && value.length === 0);

  switch (rule.type) {
    case 'json': {
      if (!isEmpty) {
        try {
          JSON.parse(strVal);
        } catch {
          return rule.message || `「${item.name}」JSON 格式不正确`;
        }
      }
      break;
    }
    case 'max': {
      if (!isEmpty && Number(value) > Number(rule.value)) {
        return rule.message || `「${item.name}」最大值为 ${rule.value}`;
      }
      break;
    }
    case 'max_length': {
      if (!isEmpty && strVal.length > Number(rule.value)) {
        return rule.message || `「${item.name}」最多输入 ${rule.value} 个字符`;
      }
      break;
    }
    case 'min': {
      if (!isEmpty && Number(value) < Number(rule.value)) {
        return rule.message || `「${item.name}」最小值为 ${rule.value}`;
      }
      break;
    }
    case 'min_length': {
      if (!isEmpty && strVal.length < Number(rule.value)) {
        return rule.message || `「${item.name}」最少输入 ${rule.value} 个字符`;
      }
      break;
    }
    case 'pattern': {
      if (!isEmpty && rule.value) {
        const flags = rule.flags || '';
        const reg = new RegExp(String(rule.value), flags);
        if (!reg.test(strVal)) {
          return rule.message || `「${item.name}」格式不正确`;
        }
      }
      break;
    }
    case 'required': {
      if (isEmpty) {
        return rule.message || `请填写「${item.name}」`;
      }
      break;
    }
    default: {
      // 正则类规则：email / url / phone / idCard / integer / float / digits / chinese / english / alphaNum / ip
      const regex = REGEX_MAP[rule.type];
      if (regex && !isEmpty && !regex.test(strVal)) {
        return rule.message || `「${item.name}」格式不正确`;
      }
      break;
    }
  }

  return '';
};

/** 验证单个字段并更新 formErrors */
const validateField = (item: SettingApi.SettingItem) => {
  const value = formValues.value[item.code];
  const error = validateFieldValue(item, value);
  if (error) {
    formErrors[item.code] = error;
  } else {
    delete formErrors[item.code];
  }
  return !error;
};

/** 验证当前保存范围内的字段 */
const validateAll = (): boolean => {
  let allValid = true;

  for (const item of currentSaveSettings.value) {
    delete formErrors[item.code];
  }

  for (const item of currentSaveSettings.value) {
    const value = formValues.value[item.code];
    const error = validateFieldValue(item, value);
    if (error) {
      formErrors[item.code] = error;
      if (allValid) {
        // 第一个错误就提示
        message.warning(error);
      }
      allValid = false;
    }
  }

  return allValid;
};

const buildSubmitData = (items: SettingApi.SettingItem[]) => {
  const submitData: Record<string, any> = {};
  for (const item of items) {
    submitData[item.code] = serializeValue(
      formValues.value[item.code],
      item.type,
    );
  }
  return submitData;
};

/** 获取字段的错误信息 */
const getFieldError = (code: string): string => {
  return formErrors[code] || '';
};

// ==================== 数据加载与转换 ====================

/** 加载配置 */
const loadConfig = async () => {
  if (!groupCode.value) return;

  loading.value = true;
  try {
    const res = await getSettingConfigApi(groupCode.value);
    groupInfo.value = res.group;

    // 判断是否为选项卡模式（tab 类型分组）
    isTabMode.value = res.display_type === 'tab' && Boolean(res.tabs?.length);

    // 判断是否有 tab 子分组（page 类型分组下有 tabs）
    hasTabs.value = res.display_type === 'page' && Boolean(res.tabs?.length);

    if (isTabMode.value) {
      // Tab 模式：只显示选项卡，每个 tab 的设置项作为内容
      tabs.value = res.tabs || [];
      activeTab.value = tabs.value[0]?.code || '';
      pageSettings.value = [];

      // 合并所有选项卡的设置项用于验证和序列化
      const allSettings = tabs.value.flatMap((t) => t.settings);
      settings.value = allSettings;
    } else if (hasTabs.value) {
      // Page + Tabs 模式：Page 的设置项直接显示，Tab 子分组的设置项显示在选项卡中
      tabs.value = res.tabs || [];
      activeTab.value = tabs.value[0]?.code || '';
      pageSettings.value = res.settings || [];

      // settings 用于验证和保存：Page 设置项 + 所有 Tab 的设置项
      const tabSettings = tabs.value.flatMap((t) => t.settings);
      settings.value = [...pageSettings.value, ...tabSettings];
    } else {
      // 普通 Page 或 Category 模式
      tabs.value = [];
      pageSettings.value = res.settings || [];
      settings.value = pageSettings.value;
    }

    const values: Record<string, any> = {};
    for (const item of settings.value) {
      values[item.code] = convertValue(item.value, item.type, item.full_url);
    }
    formValues.value = values;

    // 清除验证错误
    for (const key of Object.keys(formErrors)) {
      delete formErrors[key];
    }
  } catch (error) {
    console.error('加载配置失败:', error);
    message.error('加载配置失败');
  } finally {
    loading.value = false;
  }
};

/** 根据类型转换值 */
const convertValue = (value: string, type: string, fullUrl?: string) => {
  if (value === undefined || value === null) return undefined;

  switch (type) {
    case 'checkbox': {
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          return JSON.parse(value);
        } catch {
          return value ? value.split(',') : [];
        }
      }
      return value ? value.split(',') : [];
    }
    case 'file':
    case 'image':
    case 'video': {
      if (value) {
        return {
          url: value,
          full_url: fullUrl || toFullUrl(value),
          name: value.split('/').pop() || 'file',
        };
      }
      return undefined;
    }
    case 'files':
    case 'images':
    case 'videos': {
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          const urls: string[] = JSON.parse(value);
          // full_url 可能是数组（后端逐个返回完整路径）
          const fullUrls = Array.isArray(fullUrl) ? fullUrl : [];
          return urls.map((u, i) => ({
            url: u,
            full_url: fullUrls[i] || toFullUrl(u),
            name: u.split('/').pop() || 'file',
          }));
        } catch {
          return [];
        }
      }
      return [];
    }
    case 'number': {
      const num = Number(value);
      return Number.isNaN(num) ? value : num;
    }
    case 'switch': {
      return value === '1' || value === 'true';
    }
    default: {
      return value;
    }
  }
};

/** 序列化值（提交给后端） */
const serializeValue = (value: any, type: string): any => {
  if (value === undefined || value === null) return '';

  switch (type) {
    case 'checkbox': {
      if (Array.isArray(value)) {
        return JSON.stringify(value);
      }
      return String(value);
    }
    case 'file':
    case 'image':
    case 'video': {
      if (typeof value === 'object' && value?.url) return value.url;
      return String(value);
    }
    case 'files':
    case 'images':
    case 'videos': {
      if (Array.isArray(value)) {
        const urls = value.map((item: any) =>
          typeof item === 'object' ? item.url : item,
        );
        return JSON.stringify(urls);
      }
      return String(value);
    }
    case 'number': {
      return String(value);
    }
    case 'switch': {
      return value ? '1' : '0';
    }
    default: {
      return String(value);
    }
  }
};

/** 解析 options */
const parseOptions = (options: any) => {
  if (!options) return [];
  if (typeof options === 'string') {
    try {
      return JSON.parse(options);
    } catch {
      return [];
    }
  }
  return options;
};

/** 获取上传组件类型 */
const getUploadType = (
  type: string,
): 'file' | 'files' | 'image' | 'images' | 'video' | 'videos' => {
  return type as 'file' | 'files' | 'image' | 'images' | 'video' | 'videos';
};

/** 从 rules 中提取上传配置（maxSize / maxCount / accept），传给 Upload 组件 */
const getUploadConfigFromRules = (
  item: SettingApi.SettingItem,
): Record<string, any> => {
  const result: Record<string, any> = {};
  if (!item.rules?.length) return result;

  for (const rule of item.rules) {
    if (
      rule.type === 'max_size' &&
      rule.value !== undefined &&
      rule.value !== null
    ) {
      result.maxSize = Number(rule.value);
    }
    if (
      rule.type === 'max_count' &&
      rule.value !== undefined &&
      rule.value !== null
    ) {
      result.maxCount = Number(rule.value);
    }
    if (rule.type === 'accept_types' && rule.value) {
      const vals = Array.isArray(rule.value) ? rule.value : [rule.value];
      result.accept = vals.filter(
        (v: any): v is string => typeof v === 'string' && Boolean(v),
      );
    }
  }
  return result;
};

/** JSON 编辑器占位符 */
const jsonPlaceholder = '{"key": "value"}';

/** 获取 editor 预览内容 */
const getEditorHtml = (code: string) => {
  return formValues.value[code] || '';
};

/** 字段值变更时触发验证 */
const handleFieldChange = (item: SettingApi.SettingItem) => {
  validateField(item);
};

/** 滚动到指定 code 的表单项并高亮 */
const scrollToField = (code: string) => {
  nextTick(() => {
    const el = document.querySelector(`[data-field-code="${code}"]`);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      // 闪烁高亮效果
      el.classList.add('field-flash');
      setTimeout(() => el.classList.remove('field-flash'), 2000);
    }
  });
};

/** 处理后端返回的验证错误 */
const handleBackendErrors = (errors: Record<string, string>) => {
  // 清除旧错误
  for (const key of Object.keys(formErrors)) {
    delete formErrors[key];
  }

  // 填入后端返回的错误
  let firstErrorCode = '';
  for (const [code, msg] of Object.entries(errors)) {
    formErrors[code] = msg;
    if (!firstErrorCode) firstErrorCode = code;
  }

  // 滚动到第一个错误字段
  if (firstErrorCode) {
    scrollToField(firstErrorCode);
    // 注意：不再调用 message.warning，因为全局拦截器已展示通用错误提示，
    // 字段级错误在表单项下方内联显示即可
  }
};

/** 保存配置 */
const handleSave = async () => {
  if (!groupCode.value) return;

  // 前端验证
  if (!validateAll()) {
    // 滚动到第一个前端验证失败的字段
    const firstError = currentSaveSettings.value.find(
      (item) => formErrors[item.code],
    )?.code;
    if (firstError) scrollToField(firstError);
    return;
  }

  saving.value = true;
  try {
    if (isTabMode.value) {
      if (!activeTabConfig.value) {
        message.warning('请先选择要保存的选项卡');
        return;
      }
      await saveSettingConfigApi(
        activeTabConfig.value.code,
        buildSubmitData(activeTabConfig.value.settings),
      );
    } else if (hasTabs.value) {
      if (pageSettings.value.length > 0) {
        await saveSettingConfigApi(
          groupCode.value,
          buildSubmitData(pageSettings.value),
        );
      }
      if (activeTabConfig.value) {
        await saveSettingConfigApi(
          activeTabConfig.value.code,
          buildSubmitData(activeTabConfig.value.settings),
        );
      }
    } else {
      await saveSettingConfigApi(
        groupCode.value,
        buildSubmitData(settings.value),
      );
    }
    message.success('保存成功');
  } catch (error: any) {
    // 错误对象实际结构（经过拦截器处理后）：
    // { code: 400, message: "配置验证失败", data: { field_code: "错误信息" }, timestamp: ... }
    // - error.code: 业务状态码
    // - error.message: 通用错误提示（全局拦截器已展示 message.error）
    // - error.data: 字段级验证错误 { field_code: "错误信息" }
    const fieldErrors = error?.data;
    if (
      fieldErrors &&
      typeof fieldErrors === 'object' &&
      !Array.isArray(fieldErrors)
    ) {
      handleBackendErrors(fieldErrors);
    }
    // 全局 errorMessageResponseInterceptor 已展示通用错误提示（error.message），
    // 此处不再重复调用 message.error，避免弹出两次
  } finally {
    saving.value = false;
  }
};

watch(() => route.path, loadConfig);

onMounted(loadConfig);
</script>

<template>
  <div class="setting-form-page">
    <Spin :spinning="loading">
      <!-- 页面头部 -->
      <div v-if="groupInfo" class="setting-header">
        <div class="header-content">
          <div v-if="groupInfo.icon" class="header-icon">
            <span :class="`i-${groupInfo.icon}`" class="text-2xl"></span>
          </div>
          <div class="header-text">
            <h2 class="header-title">{{ groupInfo.name }}</h2>
            <p class="header-desc">共 {{ settings.length }} 项配置</p>
          </div>
        </div>
      </div>

      <!-- 选项卡导航（Tab 模式或 Page+Tabs 模式） -->
      <div
        v-if="(isTabMode || hasTabs) && tabs.length > 0"
        class="setting-tabs"
      >
        <a-tabs v-model:active-key="activeTab" type="card" size="large">
          <a-tab-pane v-for="tab in tabs" :key="tab.code" :tab="tab.name" />
        </a-tabs>
      </div>

      <!-- Page 设置项区域（Page+Tabs 模式） -->
      <div v-if="hasTabs && pageSettings.length > 0" class="setting-card">
        <div class="form-grid">
          <div
            v-for="item in pageSettings"
            :key="item.code"
            :data-field-code="item.code"
            class="form-item-wrapper"
            :class="{
              'has-error': getFieldError(item.code),
              'full-row': item.type === 'editor' || item.type === 'json',
            }"
          >
            <div class="form-label">
              <span class="label-text">{{ item.name }}</span>
              <span
                v-if="item.rules?.some((r) => r.type === 'required')"
                class="required-star"
              >
                *
              </span>
            </div>

            <!-- input: 文本输入 -->
            <a-input
              v-if="item.type === 'input'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- password: 密码输入 -->
            <a-input-password
              v-else-if="item.type === 'password'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- textarea: 多行文本 -->
            <a-textarea
              v-else-if="item.type === 'textarea'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :rows="3"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- number: 数字输入 -->
            <a-input-number
              v-else-if="item.type === 'number'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              class="w-full"
              @blur="handleFieldChange(item)"
            />

            <!-- switch: 开关 -->
            <div v-else-if="item.type === 'switch'" class="switch-wrapper">
              <a-switch v-model:checked="formValues[item.code]" />
              <span class="switch-label">
                {{ formValues[item.code] ? '已开启' : '已关闭' }}
              </span>
            </div>

            <!-- select: 下拉选择 -->
            <a-select
              v-else-if="item.type === 'select'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请选择${item.name}`"
              :options="parseOptions(item.options)"
              :status="getFieldError(item.code) ? 'error' : undefined"
              class="w-full"
              @change="handleFieldChange(item)"
            />

            <!-- radio: 单选框 -->
            <a-radio-group
              v-else-if="item.type === 'radio'"
              v-model:value="formValues[item.code]"
              :options="parseOptions(item.options)"
              @change="handleFieldChange(item)"
            />

            <!-- checkbox: 多选框 -->
            <a-checkbox-group
              v-else-if="item.type === 'checkbox'"
              v-model:value="formValues[item.code]"
              :options="parseOptions(item.options)"
              @change="handleFieldChange(item)"
            />

            <!-- image: 单图片上传 -->
            <Upload
              v-else-if="item.type === 'image'"
              type="image"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- images: 多图片上传 -->
            <Upload
              v-else-if="item.type === 'images'"
              type="images"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- file: 单文件上传 -->
            <Upload
              v-else-if="item.type === 'file' || item.type === 'video'"
              :type="getUploadType(item.type)"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- files: 多文件上传 -->
            <Upload
              v-else-if="item.type === 'files' || item.type === 'videos'"
              :type="getUploadType(item.type)"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- editor: HTML 编辑器 + 预览 -->
            <template v-else-if="item.type === 'editor'">
              <a-tabs type="card" size="small" class="editor-tabs">
                <a-tab-pane key="edit" tab="编辑">
                  <RichTextEditor
                    :model-value="formValues[item.code]"
                    module="dynamic_form"
                    :placeholder="item.placeholder || '请输入内容'"
                    :related-id="item.id"
                    @update:model-value="
                      (val: string) => {
                        formValues[item.code] = val;
                        delete formErrors[item.code];
                      }
                    "
                    @blur="handleFieldChange(item)"
                  />
                </a-tab-pane>
                <a-tab-pane key="preview" tab="预览">
                  <div class="editor-preview">
                    <div
                      v-if="getEditorHtml(item.code)"
                      v-html="getEditorHtml(item.code)"
                    ></div>
                    <span v-else class="text-gray-300">暂无内容</span>
                  </div>
                </a-tab-pane>
              </a-tabs>
            </template>

            <!-- json: JSON 编辑器 + JsonViewer 预览 -->
            <template v-else-if="item.type === 'json'">
              <a-tabs type="card" size="small" class="editor-tabs">
                <a-tab-pane key="edit" tab="编辑">
                  <a-textarea
                    v-model:value="formValues[item.code]"
                    :placeholder="jsonPlaceholder"
                    :rows="6"
                    :status="getFieldError(item.code) ? 'error' : undefined"
                    class="font-mono text-sm"
                    @blur="handleFieldChange(item)"
                  />
                </a-tab-pane>
                <a-tab-pane key="preview" tab="预览">
                  <div class="json-preview">
                    <JsonViewer
                      :value="getJsonObject(item.code)"
                      :expand-depth="3"
                      :copyable="true"
                      :boxed="true"
                      theme="default-json-theme"
                    />
                  </div>
                </a-tab-pane>
              </a-tabs>
            </template>

            <!-- 验证错误提示 -->
            <div v-if="getFieldError(item.code)" class="form-error">
              {{ getFieldError(item.code) }}
            </div>

            <!-- 备注 -->
            <div v-if="item.remark" class="form-remark">
              {{ item.remark }}
            </div>
          </div>
        </div>
      </div>

      <!-- 分隔线（Page+Tabs 模式） -->
      <div v-if="hasTabs && pageSettings.length > 0" class="tab-divider">
        <span class="tab-divider-text">选项卡配置</span>
      </div>

      <!-- 当前选项卡的设置项（Tab 模式或 Page+Tabs 模式） -->
      <div v-if="isTabMode || hasTabs" class="setting-card">
        <div class="form-grid">
          <div
            v-for="item in currentTabSettings"
            :key="item.code"
            :data-field-code="item.code"
            class="form-item-wrapper"
            :class="{
              'has-error': getFieldError(item.code),
              'full-row': item.type === 'editor' || item.type === 'json',
            }"
          >
            <div class="form-label">
              <span class="label-text">{{ item.name }}</span>
              <span
                v-if="item.rules?.some((r) => r.type === 'required')"
                class="required-star"
              >
                *
              </span>
            </div>

            <!-- input: 文本输入 -->
            <a-input
              v-if="item.type === 'input'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- password: 密码输入 -->
            <a-input-password
              v-else-if="item.type === 'password'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- textarea: 多行文本 -->
            <a-textarea
              v-else-if="item.type === 'textarea'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :rows="3"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- number: 数字输入 -->
            <a-input-number
              v-else-if="item.type === 'number'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              class="w-full"
              @blur="handleFieldChange(item)"
            />

            <!-- switch: 开关 -->
            <div v-else-if="item.type === 'switch'" class="switch-wrapper">
              <a-switch v-model:checked="formValues[item.code]" />
              <span class="switch-label">
                {{ formValues[item.code] ? '已开启' : '已关闭' }}
              </span>
            </div>

            <!-- select: 下拉选择 -->
            <a-select
              v-else-if="item.type === 'select'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请选择${item.name}`"
              :options="parseOptions(item.options)"
              :status="getFieldError(item.code) ? 'error' : undefined"
              class="w-full"
              @change="handleFieldChange(item)"
            />

            <!-- radio: 单选框 -->
            <a-radio-group
              v-else-if="item.type === 'radio'"
              v-model:value="formValues[item.code]"
              :options="parseOptions(item.options)"
              @change="handleFieldChange(item)"
            />

            <!-- checkbox: 多选框 -->
            <a-checkbox-group
              v-else-if="item.type === 'checkbox'"
              v-model:value="formValues[item.code]"
              :options="parseOptions(item.options)"
              @change="handleFieldChange(item)"
            />

            <!-- image: 单图片上传 -->
            <Upload
              v-else-if="item.type === 'image'"
              type="image"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- images: 多图片上传 -->
            <Upload
              v-else-if="item.type === 'images'"
              type="images"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- file: 单文件上传 -->
            <Upload
              v-else-if="item.type === 'file' || item.type === 'video'"
              :type="getUploadType(item.type)"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- files: 多文件上传 -->
            <Upload
              v-else-if="item.type === 'files' || item.type === 'videos'"
              :type="getUploadType(item.type)"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- editor: HTML 编辑器 + 预览 -->
            <template v-else-if="item.type === 'editor'">
              <a-tabs type="card" size="small" class="editor-tabs">
                <a-tab-pane key="edit" tab="编辑">
                  <RichTextEditor
                    :model-value="formValues[item.code]"
                    module="dynamic_form"
                    :placeholder="item.placeholder || '请输入内容'"
                    :related-id="item.id"
                    @update:model-value="
                      (val: string) => {
                        formValues[item.code] = val;
                        delete formErrors[item.code];
                      }
                    "
                    @blur="handleFieldChange(item)"
                  />
                </a-tab-pane>
                <a-tab-pane key="preview" tab="预览">
                  <div class="editor-preview">
                    <div
                      v-if="getEditorHtml(item.code)"
                      v-html="getEditorHtml(item.code)"
                    ></div>
                    <span v-else class="text-gray-300">暂无内容</span>
                  </div>
                </a-tab-pane>
              </a-tabs>
            </template>

            <!-- json: JSON 编辑器 + JsonViewer 预览 -->
            <template v-else-if="item.type === 'json'">
              <a-tabs type="card" size="small" class="editor-tabs">
                <a-tab-pane key="edit" tab="编辑">
                  <a-textarea
                    v-model:value="formValues[item.code]"
                    :placeholder="jsonPlaceholder"
                    :rows="6"
                    :status="getFieldError(item.code) ? 'error' : undefined"
                    class="font-mono text-sm"
                    @blur="handleFieldChange(item)"
                  />
                </a-tab-pane>
                <a-tab-pane key="preview" tab="预览">
                  <div class="json-preview">
                    <JsonViewer
                      :value="getJsonObject(item.code)"
                      :expand-depth="3"
                      :copyable="true"
                      :boxed="true"
                      theme="default-json-theme"
                    />
                  </div>
                </a-tab-pane>
              </a-tabs>
            </template>

            <!-- 验证错误提示 -->
            <div v-if="getFieldError(item.code)" class="form-error">
              {{ getFieldError(item.code) }}
            </div>

            <!-- 备注 -->
            <div v-if="item.remark" class="form-remark">
              {{ item.remark }}
            </div>
          </div>
        </div>
      </div>

      <!-- 普通 Page 模式（无子分组时） -->
      <div
        v-if="!isTabMode && !hasTabs && settings.length > 0"
        class="setting-card"
      >
        <div class="form-grid">
          <div
            v-for="item in settings"
            :key="item.code"
            :data-field-code="item.code"
            class="form-item-wrapper"
            :class="{
              'has-error': getFieldError(item.code),
              'full-row': item.type === 'editor' || item.type === 'json',
            }"
          >
            <div class="form-label">
              <span class="label-text">{{ item.name }}</span>
              <span
                v-if="item.rules?.some((r) => r.type === 'required')"
                class="required-star"
              >
                *
              </span>
            </div>

            <!-- input: 文本输入 -->
            <a-input
              v-if="item.type === 'input'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- password: 密码输入 -->
            <a-input-password
              v-else-if="item.type === 'password'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- textarea: 多行文本 -->
            <a-textarea
              v-else-if="item.type === 'textarea'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :rows="3"
              :status="getFieldError(item.code) ? 'error' : undefined"
              @blur="handleFieldChange(item)"
            />

            <!-- number: 数字输入 -->
            <a-input-number
              v-else-if="item.type === 'number'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :status="getFieldError(item.code) ? 'error' : undefined"
              class="w-full"
              @blur="handleFieldChange(item)"
            />

            <!-- switch: 开关 -->
            <div v-else-if="item.type === 'switch'" class="switch-wrapper">
              <a-switch v-model:checked="formValues[item.code]" />
              <span class="switch-label">
                {{ formValues[item.code] ? '已开启' : '已关闭' }}
              </span>
            </div>

            <!-- select: 下拉选择 -->
            <a-select
              v-else-if="item.type === 'select'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请选择${item.name}`"
              :options="parseOptions(item.options)"
              :status="getFieldError(item.code) ? 'error' : undefined"
              class="w-full"
              @change="handleFieldChange(item)"
            />

            <!-- radio: 单选框 -->
            <a-radio-group
              v-else-if="item.type === 'radio'"
              v-model:value="formValues[item.code]"
              :options="parseOptions(item.options)"
              @change="handleFieldChange(item)"
            />

            <!-- checkbox: 多选框 -->
            <a-checkbox-group
              v-else-if="item.type === 'checkbox'"
              v-model:value="formValues[item.code]"
              :options="parseOptions(item.options)"
              @change="handleFieldChange(item)"
            />

            <!-- image: 单图片上传 -->
            <Upload
              v-else-if="item.type === 'image'"
              type="image"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- images: 多图片上传 -->
            <Upload
              v-else-if="item.type === 'images'"
              type="images"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- file: 单文件上传 -->
            <Upload
              v-else-if="item.type === 'file' || item.type === 'video'"
              :type="getUploadType(item.type)"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- files: 多文件上传 -->
            <Upload
              v-else-if="item.type === 'files' || item.type === 'videos'"
              :type="getUploadType(item.type)"
              :value="formValues[item.code]"
              module="dynamic_form"
              :related-id="item.id"
              v-bind="getUploadConfigFromRules(item)"
              @update:value="
                (val: any) => {
                  formValues[item.code] = val;
                  delete formErrors[item.code];
                }
              "
            />

            <!-- editor: HTML 编辑器 + 预览 -->
            <template v-else-if="item.type === 'editor'">
              <a-tabs type="card" size="small" class="editor-tabs">
                <a-tab-pane key="edit" tab="编辑">
                  <RichTextEditor
                    :model-value="formValues[item.code]"
                    module="dynamic_form"
                    :placeholder="item.placeholder || '请输入内容'"
                    :related-id="item.id"
                    @update:model-value="
                      (val: string) => {
                        formValues[item.code] = val;
                        delete formErrors[item.code];
                      }
                    "
                    @blur="handleFieldChange(item)"
                  />
                </a-tab-pane>
                <a-tab-pane key="preview" tab="预览">
                  <div class="editor-preview">
                    <div
                      v-if="getEditorHtml(item.code)"
                      v-html="getEditorHtml(item.code)"
                    ></div>
                    <span v-else class="text-gray-300">暂无内容</span>
                  </div>
                </a-tab-pane>
              </a-tabs>
            </template>

            <!-- json: JSON 编辑器 + JsonViewer 预览 -->
            <template v-else-if="item.type === 'json'">
              <a-tabs type="card" size="small" class="editor-tabs">
                <a-tab-pane key="edit" tab="编辑">
                  <a-textarea
                    v-model:value="formValues[item.code]"
                    :placeholder="jsonPlaceholder"
                    :rows="6"
                    :status="getFieldError(item.code) ? 'error' : undefined"
                    class="font-mono text-sm"
                    @blur="handleFieldChange(item)"
                  />
                </a-tab-pane>
                <a-tab-pane key="preview" tab="预览">
                  <div class="json-preview">
                    <JsonViewer
                      :value="getJsonObject(item.code)"
                      :expand-depth="3"
                      :copyable="true"
                      :boxed="true"
                      theme="default-json-theme"
                    />
                  </div>
                </a-tab-pane>
              </a-tabs>
            </template>

            <!-- 验证错误提示 -->
            <div v-if="getFieldError(item.code)" class="form-error">
              {{ getFieldError(item.code) }}
            </div>

            <!-- 备注 -->
            <div v-if="item.remark" class="form-remark">
              {{ item.remark }}
            </div>
          </div>
        </div>
      </div>

      <!-- 空状态 -->
      <div v-if="settings.length === 0 && !loading" class="empty-state">
        <span class="i-ant-design:inbox-outlined text-5xl text-gray-300"></span>
        <p class="mt-3 text-gray-400">暂无配置项</p>
      </div>

      <!-- 底部保存栏 -->
      <div v-if="settings.length > 0" class="save-bar">
        <div class="save-bar-inner">
          <span class="save-tip">修改后请点击保存</span>
          <a-button
            type="primary"
            size="large"
            :loading="saving"
            @click="handleSave"
          >
            <template #icon>
              <span class="i-ant-design:save-outlined mr-1"></span>
            </template>
            保存设置
          </a-button>
        </div>
      </div>
    </Spin>
  </div>
</template>

<style lang="css" scoped>
.setting-form-page {
  min-height: 100%;
  padding: 24px;
  background: hsl(var(--background-deep));
}

.setting-header {
  margin-bottom: 24px;
  padding: 24px 28px;
  background: hsl(var(--card));
  border-radius: 12px;
  box-shadow: 0 1px 4px rgb(0 0 0 / 5%);
}

.header-content {
  display: flex;
  align-items: center;
  gap: 16px;
}

.header-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 12px;
  color: #fff;
  flex-shrink: 0;
}

.header-title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: hsl(var(--foreground));
}

.header-desc {
  margin: 4px 0 0;
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

.setting-section {
  margin-bottom: 20px;
}

.section-title {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
  padding-left: 4px;
  font-size: 15px;
  font-weight: 600;
  color: hsl(var(--foreground));
}

.setting-card {
  padding: 24px;
  background: hsl(var(--card));
  border-radius: 12px;
  box-shadow: 0 1px 4px rgb(0 0 0 / 5%);
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
  gap: 24px;
}

.media-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 24px;
}

.advanced-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
}

/* 让 editor 和 json 类型独占一行 */
.form-item-wrapper.full-row {
  grid-column: 1 / -1;
}

.form-item-wrapper,
.media-item-wrapper,
.advanced-item-wrapper {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  display: flex;
  align-items: center;
  gap: 2px;
  font-size: 14px;
  font-weight: 500;
  color: hsl(var(--foreground));
}

.required-star {
  color: #ff4d4f;
  margin-left: 2px;
}

.form-remark {
  font-size: 12px;
  color: hsl(var(--muted-foreground));
  line-height: 1.5;
  margin-top: 2px;
}

/* 验证错误样式 */
.form-error {
  font-size: 12px;
  color: #ff4d4f;
  line-height: 1.5;
  margin-top: 2px;
}

.has-error :deep(.ant-input),
.has-error :deep(.ant-input-password),
.has-error :deep(.ant-input-number),
.has-error :deep(.ant-select-selector),
.has-error :deep(.ant-picker) {
  border-color: #ff4d4f !important;
}

.switch-wrapper {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 32px;
}

.switch-label {
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

.editor-tabs {
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  overflow: hidden;
}

.editor-preview {
  min-height: 160px;
  padding: 12px 16px;
  background: hsl(var(--popover));
  border-radius: 0 0 8px 8px;
  font-size: 14px;
  line-height: 1.8;
  color: hsl(var(--foreground));
}

.editor-preview :deep(img) {
  max-width: 100%;
  border-radius: 4px;
}

.json-preview {
  min-height: 120px;
  padding: 12px 16px;
  background: hsl(var(--popover));
  border-radius: 0 0 8px 8px;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 0;
}

.save-bar {
  position: sticky;
  bottom: 0;
  z-index: 10;
  margin-top: 24px;
  padding: 16px 24px;
  background: hsl(var(--card));
  border-top: 1px solid hsl(var(--border));
  border-radius: 12px;
  box-shadow: 0 -2px 8px rgb(0 0 0 / 6%);
}

.save-bar-inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 16px;
}

.save-tip {
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

/* 闪烁高亮动画 */
@keyframes field-flash {
  0%,
  100% {
    background-color: transparent;
  }
  50% {
    background-color: rgb(255 77 79 / 8%);
  }
}

.field-flash {
  animation: field-flash 0.5s ease-in-out 3;
  border-radius: 8px;
}

.tab-divider {
  position: relative;
  margin: 32px 0 24px;
  text-align: center;
}

.tab-divider::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  height: 1px;
  background: hsl(var(--border));
}

.tab-divider-text {
  position: relative;
  display: inline-block;
  padding: 0 16px;
  font-size: 14px;
  font-weight: 600;
  color: hsl(var(--muted-foreground));
  background: hsl(var(--background-deep));
}

@media (width <= 768px) {
  .setting-form-page {
    padding: 16px;
  }

  .form-grid,
  .media-grid {
    grid-template-columns: 1fr;
  }
}
</style>
