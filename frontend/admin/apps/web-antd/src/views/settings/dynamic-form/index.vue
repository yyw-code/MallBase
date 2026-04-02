<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { JsonViewer } from '@vben/common-ui';

import { message, Spin } from 'ant-design-vue';

import { uploadFileApi, uploadImageApi } from '#/api/core/upload';
import { getSettingConfigApi, saveSettingConfigApi } from '#/api/setting';
import Upload from '#/components/upload/index.vue';

defineOptions({ name: 'SettingDynamicForm' });

const route = useRoute();
const loading = ref(false);
const saving = ref(false);
const groupInfo = ref<SettingApi.ConfigResponse['group']>();
const settings = ref<SettingApi.SettingItem[]>([]);
const formValues = ref<Record<string, any>>({});
const formErrors = reactive<Record<string, string>>({});

/** API 基础地址，用于图片/文件回显兜底 */
const apiBaseUrl = import.meta.env.VITE_GLOB_API_URL || '';

/** 将相对路径转为完整 URL（兜底用，优先使用后端返回的 preview_url） */
const toFullUrl = (path: string) => {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  return `${apiBaseUrl}${path}`;
};

/**
 * 将 formValues 中媒体值转为 Upload 组件可显示的 URL
 * 内部存储格式: { url, preview_url, name } 或 { url, preview_url, name }[]
 * Upload 组件需要: string 或 string[]
 */
const getUploadValue = (value: any) => {
  if (!value) return value;
  // 多文件/多图: [{ url, preview_url, name }, ...]
  if (Array.isArray(value)) {
    return value.map((item: any) => {
      if (typeof item === 'object' && item?.preview_url)
        return item.preview_url;
      if (typeof item === 'object' && item?.url) return toFullUrl(item.url);
      if (typeof item === 'string') return toFullUrl(item);
      return '';
    });
  }
  // 单文件/单图: { url, preview_url, name }
  if (typeof value === 'object' && value?.preview_url)
    return value.preview_url;
  if (typeof value === 'object' && value?.url) return toFullUrl(value.url);
  // 兜底: 纯字符串
  return toFullUrl(value);
};

/** 从路由路径中提取 groupCode */
const groupCode = computed(() => {
  const path = route.path;
  const segments = path.split('/').filter(Boolean);
  return segments[segments.length - 1] || '';
});

/** 按类型分组 */
const basicSettings = computed(() =>
  settings.value.filter((s) =>
    [
      'checkbox',
      'input',
      'number',
      'password',
      'radio',
      'select',
      'switch',
      'textarea',
    ].includes(s.type),
  ),
);

const mediaSettings = computed(() =>
  settings.value.filter((s) =>
    ['file', 'files', 'image', 'images'].includes(s.type),
  ),
);

const advancedSettings = computed(() =>
  settings.value.filter((s) => ['editor', 'json'].includes(s.type)),
);

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
  alphaNum: /^[a-z0-9]+$/i,
  chinese: /^[\u4e00-\u9fa5]+$/,
  digits: /^\d+$/,
  email: /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/,
  english: /^[a-z]+$/i,
  float: /^-?\d+(\.\d+)?$/,
  idCard: /^\d{17}[\dX]$/i,
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
    case 'maxLength': {
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
    case 'minLength': {
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

/** 验证所有字段 */
const validateAll = (): boolean => {
  let allValid = true;
  // 先清除所有错误
  for (const key of Object.keys(formErrors)) {
    delete formErrors[key];
  }

  for (const item of settings.value) {
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
    settings.value = res.settings;

    const values: Record<string, any> = {};
    for (const item of res.settings) {
      values[item.code] = convertValue(item.value, item.type, item.preview_url);
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
const convertValue = (value: string, type: string, previewUrl?: string) => {
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
    case 'image': {
      if (value) {
        return {
          url: value,
          preview_url: previewUrl || toFullUrl(value),
          name: value.split('/').pop() || 'file',
        };
      }
      return undefined;
    }
    case 'files':
    case 'images': {
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          const urls: string[] = JSON.parse(value);
          return urls.map((u) => ({
            url: u,
            preview_url: toFullUrl(u),
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
    case 'image': {
      if (typeof value === 'object' && value?.url) return value.url;
      return String(value);
    }
    case 'files':
    case 'images': {
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
const getUploadType = (type: string): 'file' | 'files' | 'image' | 'images' => {
  return type as 'file' | 'files' | 'image' | 'images';
};

/** JSON 编辑器占位符 */
const jsonPlaceholder = '{"key": "value"}';

/** 获取 editor 预览内容 */
const getEditorHtml = (code: string) => {
  return formValues.value[code] || '';
};

/** 上传处理 —— 存储完整信息 { url, preview_url, name } */
const handleUpload = (code: string, type: string) => {
  return async (file: File) => {
    try {
      const isImage = ['image', 'images'].includes(type);
      const res = isImage
        ? await uploadImageApi(file)
        : await uploadFileApi(file);

      const fileInfo = {
        url: res?.url || '',
        preview_url: res?.preview_url || res?.full_url || toFullUrl(res?.url || ''),
        name: res?.name || file.name,
      };

      if (['files', 'images'].includes(type)) {
        const current = formValues.value[code] || [];
        formValues.value[code] = [...current, fileInfo];
      } else {
        formValues.value[code] = fileInfo;
      }

      // 上传成功后清除该字段的验证错误
      delete formErrors[code];
    } catch (error) {
      console.error('上传失败:', error);
      message.error('上传失败');
    }
  };
};

const handleUploadRemove = (code: string, type: string) => {
  return async (index?: number) => {
    if (['files', 'images'].includes(type)) {
      const current = formValues.value[code] || [];
      if (index !== undefined) {
        current.splice(index, 1);
        formValues.value[code] = [...current];
      }
    } else {
      formValues.value[code] = '';
    }
  };
};

/** 字段值变更时触发验证 */
const handleFieldChange = (item: SettingApi.SettingItem) => {
  validateField(item);
};

/** 保存配置 */
const handleSave = async () => {
  if (!groupCode.value) return;

  // 使用后端 rules 进行表单验证
  if (!validateAll()) {
    return;
  }

  saving.value = true;
  try {
    const submitData: Record<string, any> = {};
    for (const item of settings.value) {
      submitData[item.code] = serializeValue(
        formValues.value[item.code],
        item.type,
      );
    }

    await saveSettingConfigApi(groupCode.value, submitData);
    message.success('保存成功');
  } catch (error) {
    console.error('保存失败:', error);
    message.error('保存失败');
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

      <!-- 基础设置 -->
      <div v-if="basicSettings.length > 0" class="setting-section">
        <div class="section-title">
          <span class="i-ant-design:setting-outlined mr-2 text-lg"></span>
          基础设置
        </div>
        <div class="setting-card">
          <div class="form-grid">
            <div
              v-for="item in basicSettings"
              :key="item.code"
              class="form-item-wrapper"
              :class="{ 'has-error': getFieldError(item.code) }"
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

              <a-input
                v-if="item.type === 'input'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请输入${item.name}`"
                :status="getFieldError(item.code) ? 'error' : undefined"
                @blur="handleFieldChange(item)"
              />

              <a-input-password
                v-else-if="item.type === 'password'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请输入${item.name}`"
                :status="getFieldError(item.code) ? 'error' : undefined"
                @blur="handleFieldChange(item)"
              />

              <a-textarea
                v-else-if="item.type === 'textarea'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请输入${item.name}`"
                :rows="3"
                :status="getFieldError(item.code) ? 'error' : undefined"
                @blur="handleFieldChange(item)"
              />

              <a-input-number
                v-else-if="item.type === 'number'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请输入${item.name}`"
                :status="getFieldError(item.code) ? 'error' : undefined"
                class="w-full"
                @blur="handleFieldChange(item)"
              />

              <div v-else-if="item.type === 'switch'" class="switch-wrapper">
                <a-switch v-model:checked="formValues[item.code]" />
                <span class="switch-label">
                  {{ formValues[item.code] ? '已开启' : '已关闭' }}
                </span>
              </div>

              <a-select
                v-else-if="item.type === 'select'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请选择${item.name}`"
                :options="parseOptions(item.options)"
                :status="getFieldError(item.code) ? 'error' : undefined"
                class="w-full"
                @change="handleFieldChange(item)"
              />

              <a-radio-group
                v-else-if="item.type === 'radio'"
                v-model:value="formValues[item.code]"
                :options="parseOptions(item.options)"
                @change="handleFieldChange(item)"
              />

              <a-checkbox-group
                v-else-if="item.type === 'checkbox'"
                v-model:value="formValues[item.code]"
                :options="parseOptions(item.options)"
                @change="handleFieldChange(item)"
              />

              <!-- 验证错误提示 -->
              <div v-if="getFieldError(item.code)" class="form-error">
                {{ getFieldError(item.code) }}
              </div>

              <div v-if="item.remark" class="form-remark">
                {{ item.remark }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 媒体文件 -->
      <div v-if="mediaSettings.length > 0" class="setting-section">
        <div class="section-title">
          <span class="i-ant-design:picture-outlined mr-2 text-lg"></span>
          媒体文件
        </div>
        <div class="setting-card">
          <div class="media-grid">
            <div
              v-for="item in mediaSettings"
              :key="item.code"
              class="media-item-wrapper"
              :class="{ 'has-error': getFieldError(item.code) }"
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
              <Upload
                :type="getUploadType(item.type)"
                :value="getUploadValue(formValues[item.code])"
                :custom-upload="handleUpload(item.code, item.type)"
                :custom-remove="handleUploadRemove(item.code, item.type)"
              />
              <!-- 验证错误提示 -->
              <div v-if="getFieldError(item.code)" class="form-error">
                {{ getFieldError(item.code) }}
              </div>
              <div v-if="item.remark" class="form-remark">
                {{ item.remark }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 高级配置 -->
      <div v-if="advancedSettings.length > 0" class="setting-section">
        <div class="section-title">
          <span class="i-ant-design:code-outlined mr-2 text-lg"></span>
          高级配置
        </div>
        <div class="setting-card">
          <div class="advanced-grid">
            <div
              v-for="item in advancedSettings"
              :key="item.code"
              class="advanced-item-wrapper"
              :class="{ 'has-error': getFieldError(item.code) }"
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

              <!-- editor: HTML 编辑器 + 预览 -->
              <template v-if="item.type === 'editor'">
                <a-tabs type="card" size="small" class="editor-tabs">
                  <a-tab-pane key="edit" tab="编辑">
                    <a-textarea
                      v-model:value="formValues[item.code]"
                      :placeholder="item.placeholder || '请输入内容'"
                      :rows="8"
                      :status="getFieldError(item.code) ? 'error' : undefined"
                      class="font-mono text-sm"
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

              <!-- json: 编辑 + JsonViewer 预览 -->
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

              <div v-if="item.remark" class="form-remark">
                {{ item.remark }}
              </div>
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
  background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
}

.setting-header {
  margin-bottom: 24px;
  padding: 24px 28px;
  background: #fff;
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
  color: #1a1a2e;
}

.header-desc {
  margin: 4px 0 0;
  font-size: 13px;
  color: #8c8c8c;
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
  color: #333;
}

.setting-card {
  padding: 24px;
  background: #fff;
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
  color: #333;
}

.required-star {
  color: #ff4d4f;
  margin-left: 2px;
}

.form-remark {
  font-size: 12px;
  color: #8c8c8c;
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
  color: #8c8c8c;
}

.editor-tabs {
  border: 1px solid #f0f0f0;
  border-radius: 8px;
  overflow: hidden;
}

.editor-preview {
  min-height: 160px;
  padding: 12px 16px;
  background: #fafafa;
  border-radius: 0 0 8px 8px;
  font-size: 14px;
  line-height: 1.8;
  color: #333;
}

.editor-preview :deep(img) {
  max-width: 100%;
  border-radius: 4px;
}

.json-preview {
  min-height: 120px;
  padding: 12px 16px;
  background: #fafafa;
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
  background: #fff;
  border-top: 1px solid #f0f0f0;
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
  color: #8c8c8c;
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
