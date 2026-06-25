<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { ClientThemeApi } from '#/api/client';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message, Modal } from 'ant-design-vue';

import {
  copyClientThemeApi,
  createClientThemeApi,
  deleteClientThemeApi,
  getClientThemeInfoApi,
  getClientThemeListApi,
  getClientThemeSettingApi,
  publishClientThemeApi,
  updateClientThemeApi,
  updateClientThemeSettingApi,
} from '#/api/client';
import { useTableCrud } from '#/composables/useTableCrud';

import ClientPhonePreview from '../components/ClientPhonePreview.vue';

defineOptions({ name: 'ClientThemeManagement' });

type TokenRow = {
  core?: boolean;
  desc?: string;
  key: string;
  label: string;
  required?: boolean;
  value: string;
};

const THEME_TYPE_OPTIONS: Array<{
  color: string;
  label: string;
  value: ClientThemeApi.ThemeType;
}> = [
  { color: 'gold', label: '浅色', value: 'light' },
  { color: 'blue', label: '深色', value: 'dark' },
  { color: 'purple', label: '自定义', value: 'custom' },
];

const MODE_OPTIONS: Array<{
  desc: string;
  label: string;
  value: ClientThemeApi.ThemeMode;
}> = [
  {
    desc: '客户端根据系统浅色/深色自动切换',
    label: '跟随系统',
    value: 'system',
  },
  { desc: '固定使用系统浅色主题', label: '浅色', value: 'light' },
  { desc: '固定使用系统深色主题', label: '深色', value: 'dark' },
  { desc: '固定使用管理员发布的自定义主题', label: '自定义', value: 'custom' },
];

const TOKEN_DEFS: TokenRow[] = [
  {
    desc: '按钮、链接、选中态等主色',
    key: 'colorPrimary',
    label: '品牌主色',
    core: true,
    required: true,
    value: '#0d50d5',
  },
  {
    desc: '页面最底层背景',
    key: 'colorBg',
    label: '页面背景',
    core: true,
    required: true,
    value: '#ffffff',
  },
  {
    desc: '卡片、模块、弹层背景',
    key: 'colorBgSurface',
    label: '卡片背景',
    core: true,
    required: true,
    value: '#f3f3fe',
  },
  {
    desc: '标题和正文主文字',
    key: 'colorText',
    label: '主文字',
    core: true,
    required: true,
    value: '#191b23',
  },
  {
    desc: '说明、辅助信息文字',
    key: 'colorTextSecondary',
    label: '次级文字',
    core: true,
    required: true,
    value: '#434654',
  },
  {
    desc: '卡片、输入框边框',
    key: 'colorBorder',
    label: '边框',
    core: true,
    required: true,
    value: '#e0e4e8',
  },
  {
    desc: '价格、促销价、金额强调',
    key: 'colorPrice',
    label: '价格色',
    core: true,
    required: true,
    value: '#ff5a1f',
  },
];

const SUMMARY_TOKEN_KEYS = [
  'colorPrimary',
  'colorBg',
  'colorBgSurface',
  'colorText',
  'colorPrice',
];

const DERIVED_PREVIEW_TOKENS: Record<string, string> = {
  colorBgSecondary: '#faf8ff',
  colorDivider: '#f0f2f5',
  colorError: '#ba1a1a',
  colorPrimaryLight: '#386bef',
  colorSuccess: '#34c759',
  colorTextTertiary: '#737686',
  colorWarning: '#f0ad4e',
};

const HIDDEN_TOKEN_KEYS = new Set([
  'colorErrorSoft',
  'colorPageBg',
  'colorPriceSoft',
  'colorPrimaryBorder',
  'colorPrimarySoft',
  'colorPrimarySofter',
  'colorSuccessSoft',
  'colorTextInverse',
  'colorTextTitle',
  'colorWarningSoft',
  ...Object.keys(DERIVED_PREVIEW_TOKENS),
]);

const DEFAULT_PREVIEW_TOKENS = {
  ...DERIVED_PREVIEW_TOKENS,
  ...Object.fromEntries(TOKEN_DEFS.map((item) => [item.key, item.value])),
} as Record<string, string>;

const activeTab = ref<'setting' | 'themes'>('setting');

const tokenDefMap = computed<Record<string, TokenRow>>(() =>
  Object.fromEntries(TOKEN_DEFS.map((item) => [item.key, item])),
);

const typeMap = computed<Record<string, (typeof THEME_TYPE_OPTIONS)[number]>>(
  () =>
    Object.fromEntries(THEME_TYPE_OPTIONS.map((item) => [item.value, item])),
);

const modeMap = computed<Record<string, (typeof MODE_OPTIONS)[number]>>(() =>
  Object.fromEntries(MODE_OPTIONS.map((item) => [item.value, item])),
);

const { tableData, loading, pagination, loadData } = useTableCrud<
  ClientThemeApi.ThemeItem,
  ClientThemeApi.ListParams
>(
  {
    delete: deleteClientThemeApi,
    list: getClientThemeListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref<ClientThemeApi.ListParams>({
  keyword: '',
  status: undefined,
  type: undefined,
});

const settingLoading = ref(false);
const settingForm = reactive<ClientThemeApi.ThemeSetting>({
  admin_theme_id: null,
  admin_theme_mode: 'system',
  user_select_enabled: 1,
});

const themeOptions = computed(() =>
  tableData.value
    .filter((item) => item.status === 1 && item.type === 'custom')
    .map((item) => ({
      label: item.name,
      value: item.id,
    })),
);

const currentModeDesc = computed(
  () => modeMap.value[settingForm.admin_theme_mode]?.desc || '',
);

const publishedThemes = computed(() =>
  tableData.value.filter((item) => item.status === 1),
);

const previewThemeCards = computed(() => tableData.value);

const getThemeTokens = (theme?: ClientThemeApi.ThemeItem | null) => ({
  ...DEFAULT_PREVIEW_TOKENS,
  ...theme?.tokens,
});

const getDefaultCustomTheme = () =>
  publishedThemes.value.find(
    (item) => item.id === settingForm.admin_theme_id && item.type === 'custom',
  );

const getThemeByType = (type: ClientThemeApi.ThemeType) =>
  publishedThemes.value.find((item) => item.type === type);

const currentPreviewTheme = computed(() => {
  if (settingForm.admin_theme_mode === 'custom') {
    return getDefaultCustomTheme() || getThemeByType('custom');
  }
  if (settingForm.admin_theme_mode === 'dark') {
    return getThemeByType('dark');
  }
  return getThemeByType('light');
});

const currentPreviewTokens = computed(() =>
  getThemeTokens(currentPreviewTheme.value),
);

const settingPriorityDesc = computed(() =>
  settingForm.user_select_enabled
    ? '开启后，用户在客户端选择主题后以用户个人选择为准；未选择时使用管理员指定主题。'
    : '关闭后客户端忽略用户个人选择，实际主题由管理员指定主题统一控制。',
);

const isSystemTheme = (record: ClientThemeApi.ThemeItem) =>
  record.is_system === 1;

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
    type: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const loadSetting = async () => {
  try {
    const setting = await getClientThemeSettingApi();
    Object.assign(settingForm, {
      admin_theme_id: setting.admin_theme_id ?? null,
      admin_theme_mode: setting.admin_theme_mode || 'system',
      user_select_enabled: setting.user_select_enabled ?? 1,
    });
  } catch (error) {
    console.error('加载主题设置失败:', error);
  }
};

const handleSaveSetting = async () => {
  settingLoading.value = true;
  try {
    await updateClientThemeSettingApi({
      admin_theme_id:
        settingForm.admin_theme_mode === 'custom'
          ? settingForm.admin_theme_id || null
          : null,
      admin_theme_mode: settingForm.admin_theme_mode,
      user_select_enabled: settingForm.user_select_enabled,
    });
    message.success('主题设置已保存');
  } catch (error) {
    console.error('保存主题设置失败:', error);
  } finally {
    settingLoading.value = false;
  }
};

watch(
  () => settingForm.admin_theme_mode,
  (mode) => {
    if (mode !== 'custom') {
      settingForm.admin_theme_id = null;
    }
  },
);

const modalVisible = ref(false);
const modalLoading = ref(false);
const formRef = ref<FormInstance>();
const editingId = ref<null | number>(null);

const formData = reactive({
  advancedTokenRows: [] as TokenRow[],
  name: '',
  sort: 0,
  status: 0,
  tokenRows: [] as TokenRow[],
  type: 'custom' as ClientThemeApi.ThemeType,
});

const formRules: Record<string, Rule[]> = {
  name: [{ message: '请输入主题名称', required: true, whitespace: true }],
};

const modalTitle = computed(() => (editingId.value ? '编辑主题' : '新增主题'));

const tokenRowsFromTokens = (tokens?: Record<string, string>) => {
  const source = tokens || {};
  return TOKEN_DEFS.map((item) => ({
    ...item,
    value: source[item.key] || item.value,
  }));
};

const advancedRowsFromTokens = (tokens?: Record<string, string>) => {
  const source = tokens || {};
  const rows: TokenRow[] = [];
  Object.entries(source).forEach(([key, value]) => {
    if (!tokenDefMap.value[key] && !HIDDEN_TOKEN_KEYS.has(key)) {
      rows.push({
        key,
        label: key,
        value: String(value),
      });
    }
  });
  return rows;
};

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    advancedTokenRows: [],
    name: '',
    sort: 0,
    status: 0,
    tokenRows: tokenRowsFromTokens(),
    type: 'custom',
  });
};

const rowsToTokens = () => {
  const tokens: Record<string, string> = {};
  for (const row of [...formData.tokenRows, ...formData.advancedTokenRows]) {
    const key = row.key.trim();
    if (key && row.value) {
      tokens[key] = row.value;
    }
  }
  return tokens;
};

const formPreviewTokens = computed(() => ({
  ...DEFAULT_PREVIEW_TOKENS,
  ...rowsToTokens(),
}));

const handleCreate = () => {
  editingId.value = null;
  resetForm();
  modalVisible.value = true;
};

const handleEdit = async (record: ClientThemeApi.ThemeItem) => {
  if (isSystemTheme(record)) {
    message.info('系统主题不能直接编辑，请复制为自定义主题后修改');
    return;
  }
  try {
    const detail = await getClientThemeInfoApi(record.id);
    editingId.value = detail.id;
    resetForm();
    Object.assign(formData, {
      advancedTokenRows: advancedRowsFromTokens(detail.tokens),
      name: detail.name,
      sort: detail.sort ?? 0,
      status: detail.status ?? 0,
      tokenRows: tokenRowsFromTokens(detail.tokens),
      type: detail.type,
    });
    modalVisible.value = true;
  } catch (error) {
    console.error('获取主题详情失败:', error);
    message.error('获取主题详情失败');
  }
};

const addTokenRow = () => {
  formData.advancedTokenRows.push({
    key: '',
    label: '自定义颜色',
    value: '#1677ff',
  });
};

const removeTokenRow = (index: number) => {
  formData.advancedTokenRows.splice(index, 1);
};

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    const tokens = rowsToTokens();
    const missed = TOKEN_DEFS.filter(
      (item) => item.required && !tokens[item.key],
    );
    if (missed.length > 0) {
      message.warning(
        `请完善核心颜色：${missed.map((item) => item.label).join('、')}`,
      );
      return;
    }

    modalLoading.value = true;
    const data: ClientThemeApi.SaveParams = {
      name: formData.name,
      sort: formData.sort,
      status: formData.status,
      tokens,
      type: formData.type,
    };
    if (editingId.value) {
      await updateClientThemeApi(editingId.value, data);
      message.success('更新成功');
    } else {
      await createClientThemeApi(data);
      message.success('创建成功');
    }
    modalVisible.value = false;
    await loadData(searchParams.value);
  } catch (error: any) {
    if (!error?.errorFields) {
      console.error('保存主题失败:', error);
    }
  } finally {
    modalLoading.value = false;
  }
};

const handleCopy = async (record: ClientThemeApi.ThemeItem) => {
  await copyClientThemeApi(record.id);
  message.success('复制成功');
  await loadData(searchParams.value);
};

const handlePublish = async (record: ClientThemeApi.ThemeItem) => {
  if (isSystemTheme(record)) {
    message.info('系统主题已内置发布');
    return;
  }
  await publishClientThemeApi(record.id);
  message.success('发布成功');
  await loadData(searchParams.value);
};

const handleDelete = (record: ClientThemeApi.ThemeItem) => {
  if (isSystemTheme(record)) {
    message.info('系统主题不能删除');
    return;
  }
  Modal.confirm({
    content: `确定要删除主题"${record.name}"吗？`,
    onOk: async () => {
      await deleteClientThemeApi(record.id);
      message.success('删除成功');
      await loadData(searchParams.value);
    },
  });
};

const handlePreviewPageChange = (page: number, pageSize: number) => {
  pagination.current = page;
  pagination.pageSize = pageSize;
  loadData(searchParams.value);
};

onMounted(async () => {
  await Promise.all([loadData(searchParams.value), loadSetting()]);
});
</script>

<template>
  <div class="theme-page">
    <a-tabs v-model:active-key="activeTab">
      <a-tab-pane key="setting" tab="主题设置">
        <a-card title="客户端主题设置">
          <a-alert
            class="mb-4"
            message="主题生效规则"
            :description="settingPriorityDesc"
            show-icon
            type="info"
          />
          <div class="setting-grid">
            <section class="setting-form">
              <a-form
                :label-col="{ style: { width: '108px' } }"
                :model="settingForm"
              >
                <a-form-item label="允许用户自选">
                  <a-switch
                    v-model:checked="settingForm.user_select_enabled"
                    :checked-value="1"
                    :un-checked-value="0"
                    checked-children="开启"
                    un-checked-children="关闭"
                  />
                </a-form-item>
                <a-form-item label="管理员指定">
                  <a-radio-group v-model:value="settingForm.admin_theme_mode">
                    <a-radio-button
                      v-for="item in MODE_OPTIONS"
                      :key="item.value"
                      :value="item.value"
                    >
                      {{ item.label }}
                    </a-radio-button>
                  </a-radio-group>
                </a-form-item>
                <a-form-item label="指定主题">
                  <a-select
                    v-model:value="settingForm.admin_theme_id"
                    :disabled="settingForm.admin_theme_mode !== 'custom'"
                    :options="themeOptions"
                    allow-clear
                    placeholder="选择已发布的自定义主题"
                    style="width: 280px"
                  />
                </a-form-item>
                <a-form-item>
                  <a-button
                    type="primary"
                    :loading="settingLoading"
                    @click="handleSaveSetting"
                  >
                    保存设置
                  </a-button>
                </a-form-item>
              </a-form>
            </section>

            <section class="setting-preview">
              <div class="preview-title">
                <strong>当前设置预览</strong>
                <span>{{ currentPreviewTheme?.name || '系统默认主题' }}</span>
              </div>
              <ClientPhonePreview
                kind="profile"
                size="compact"
                current-path="/pages/profile/index"
                :theme-tokens="currentPreviewTokens"
              />
              <div class="preview-setting">
                <div class="preview-row">
                  <span>用户端入口</span>
                  <strong>{{
                    settingForm.user_select_enabled
                      ? '显示'
                      : '显示，点击提示管理员统一设置'
                  }}</strong>
                </div>
                <div class="preview-row">
                  <span>管理员指定</span>
                  <strong>{{
                    modeMap[settingForm.admin_theme_mode]?.label
                  }}</strong>
                </div>
                <div class="preview-row">
                  <span>优先级</span>
                  <strong>{{
                    settingForm.user_select_enabled
                      ? '用户选择优先'
                      : '后台统一控制'
                  }}</strong>
                </div>
                <div class="preview-desc">{{ currentModeDesc }}</div>
              </div>
            </section>
          </div>
        </a-card>
      </a-tab-pane>

      <a-tab-pane key="themes" tab="主题列表">
        <a-card>
          <template #title>
            <div class="theme-card-title">
              <span>主题列表</span>
              <a-space>
                <a-button @click="() => loadData(searchParams)">刷新</a-button>
                <a-button type="primary" @click="handleCreate">
                  新增主题
                </a-button>
              </a-space>
            </div>
          </template>

          <a-form layout="inline" class="mb-4">
            <a-form-item label="关键词">
              <a-input
                v-model:value="searchParams.keyword"
                allow-clear
                placeholder="主题名称"
                style="width: 200px"
              />
            </a-form-item>
            <a-form-item label="类型">
              <a-select
                v-model:value="searchParams.type"
                :options="THEME_TYPE_OPTIONS"
                allow-clear
                placeholder="请选择"
                style="width: 140px"
              />
            </a-form-item>
            <a-form-item label="状态">
              <a-select
                v-model:value="searchParams.status"
                allow-clear
                placeholder="请选择"
                style="width: 120px"
              >
                <a-select-option :value="1">已发布</a-select-option>
                <a-select-option :value="0">草稿</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item>
              <a-button
                type="primary"
                @click="
                  () => {
                    pagination.current = 1;
                    loadData(searchParams);
                  }
                "
              >
                搜索
              </a-button>
              <a-button class="ml-2" @click="resetSearch">重置</a-button>
            </a-form-item>
          </a-form>

          <a-spin :spinning="loading">
            <a-empty
              v-if="previewThemeCards.length === 0"
              class="theme-preview-empty"
              description="暂无主题"
            />
            <div v-else class="theme-preview-grid">
              <article
                v-for="record in previewThemeCards"
                :key="record.id"
                class="theme-preview-card"
              >
                <div class="theme-preview-card__head">
                  <div>
                    <strong>{{ record.name }}</strong>
                    <span>{{
                      typeMap[record.type]?.label || record.type
                    }}</span>
                  </div>
                  <a-space :size="4">
                    <a-tag v-if="isSystemTheme(record)">系统</a-tag>
                    <a-tag :color="record.status === 1 ? 'green' : 'default'">
                      {{ record.status === 1 ? '已发布' : '草稿' }}
                    </a-tag>
                  </a-space>
                </div>
                <ClientPhonePreview
                  kind="home"
                  size="compact"
                  current-path="/pages/index/index"
                  :theme-tokens="getThemeTokens(record)"
                />
                <div class="theme-preview-card__summary token-summary">
                  <div
                    v-for="key in SUMMARY_TOKEN_KEYS"
                    :key="key"
                    class="token-chip"
                  >
                    <i
                      :style="{
                        backgroundColor: record.tokens?.[key] || '#999999',
                      }"
                    ></i>
                    <span>{{ tokenDefMap[key]?.label || key }}</span>
                    <em>{{ record.tokens?.[key] || '-' }}</em>
                  </div>
                </div>
                <div class="theme-preview-card__actions">
                  <a-space v-if="isSystemTheme(record)">
                    <a-tag>系统内置</a-tag>
                    <a-button
                      type="link"
                      size="small"
                      @click="handleCopy(record)"
                    >
                      复制
                    </a-button>
                  </a-space>
                  <a-space v-else wrap>
                    <a-button
                      type="link"
                      size="small"
                      @click="handleEdit(record)"
                    >
                      编辑
                    </a-button>
                    <a-button
                      type="link"
                      size="small"
                      @click="handleCopy(record)"
                    >
                      复制
                    </a-button>
                    <a-button
                      v-if="record.status !== 1"
                      type="link"
                      size="small"
                      @click="handlePublish(record)"
                    >
                      发布
                    </a-button>
                    <a-button
                      type="link"
                      danger
                      size="small"
                      @click="handleDelete(record)"
                    >
                      删除
                    </a-button>
                  </a-space>
                </div>
              </article>
            </div>
          </a-spin>

          <div v-if="pagination.total > 0" class="theme-pagination">
            <a-pagination
              :current="pagination.current"
              :page-size="pagination.pageSize"
              :show-size-changer="pagination.showSizeChanger"
              :show-total="pagination.showTotal"
              :total="pagination.total"
              @change="handlePreviewPageChange"
              @show-size-change="handlePreviewPageChange"
            />
          </div>
        </a-card>
      </a-tab-pane>
    </a-tabs>

    <a-modal
      v-model:open="modalVisible"
      :confirm-loading="modalLoading"
      :title="modalTitle"
      width="1120px"
      @ok="handleSubmit"
    >
      <div class="theme-modal-grid">
        <a-form
          ref="formRef"
          :label-col="{ style: { width: '100px' } }"
          :model="formData"
          :rules="formRules"
          class="pt-4"
        >
          <a-form-item label="主题名称" name="name">
            <a-input
              v-model:value="formData.name"
              allow-clear
              placeholder="请输入主题名称"
            />
          </a-form-item>
          <a-form-item label="主题类型">
            <a-tag color="purple">自定义主题</a-tag>
            <span class="type-hint">
              系统浅色/深色为内置主题，复制后可改为自定义主题。
            </span>
          </a-form-item>
          <a-form-item label="排序" name="sort">
            <a-input-number
              v-model:value="formData.sort"
              :min="0"
              :max="9999"
              class="w-full"
            />
          </a-form-item>
          <a-form-item label="状态" name="status">
            <a-radio-group v-model:value="formData.status">
              <a-radio :value="0">草稿</a-radio>
              <a-radio :value="1">已发布</a-radio>
            </a-radio-group>
          </a-form-item>
          <a-form-item label="主题颜色">
            <div class="token-editor">
              <div
                v-for="(row, index) in formData.tokenRows"
                :key="`${row.key}_${index}`"
                class="token-row"
              >
                <div class="token-info">
                  <strong>{{ row.label }}</strong>
                  <span>{{ row.desc || row.key || '自定义主题变量' }}</span>
                </div>
                <div class="token-code">{{ row.key }}</div>
                <a-input
                  v-model:value="row.value"
                  allow-clear
                  placeholder="#1677ff"
                >
                  <template #addonAfter>
                    <input
                      v-model="row.value"
                      class="color-input"
                      type="color"
                    />
                  </template>
                </a-input>
                <a-tag color="blue">核心</a-tag>
              </div>
              <a-collapse ghost>
                <a-collapse-panel key="advanced" header="高级自定义颜色">
                  <div class="advanced-hint">
                    仅当客户端页面或组件使用到该变量时才会生效。
                  </div>
                  <div
                    v-for="(row, index) in formData.advancedTokenRows"
                    :key="`${row.key}_${index}`"
                    class="token-row"
                  >
                    <div class="token-info">
                      <strong>{{ row.label || '自定义颜色' }}</strong>
                      <span>{{ row.desc || row.key || '自定义主题变量' }}</span>
                    </div>
                    <a-input
                      v-model:value="row.key"
                      allow-clear
                      placeholder="变量编码，如 colorAccent"
                    />
                    <a-input
                      v-model:value="row.value"
                      allow-clear
                      placeholder="#1677ff"
                    >
                      <template #addonAfter>
                        <input
                          v-model="row.value"
                          class="color-input"
                          type="color"
                        />
                      </template>
                    </a-input>
                    <a-button
                      danger
                      size="small"
                      @click="removeTokenRow(index)"
                    >
                      删除
                    </a-button>
                  </div>
                  <a-button size="small" @click="addTokenRow">
                    添加自定义颜色
                  </a-button>
                </a-collapse-panel>
              </a-collapse>
            </div>
          </a-form-item>
        </a-form>

        <aside class="theme-modal-preview">
          <div class="preview-title">
            <strong>实时预览</strong>
            <span>{{ formData.name || '未命名主题' }}</span>
          </div>
          <ClientPhonePreview
            kind="home"
            size="compact"
            current-path="/pages/index/index"
            :theme-tokens="formPreviewTokens"
          />
        </aside>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
.theme-page {
  min-height: 100%;
  padding: 16px;
}

.setting-grid {
  display: grid;
  grid-template-columns: minmax(420px, 1fr) 360px;
  gap: 24px;
}

.setting-form,
.setting-preview {
  min-width: 0;
}

.setting-preview {
  padding: 18px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

.preview-title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
  font-weight: 600;
}

.preview-title span {
  overflow: hidden;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  font-weight: 400;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.preview-setting {
  margin-top: 16px;
}

.preview-row {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid hsl(var(--border));
}

.preview-row span,
.preview-desc,
.token-info span,
.token-code {
  color: hsl(var(--muted-foreground));
}

.preview-desc {
  margin-top: 12px;
  line-height: 1.7;
}

.theme-card-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.theme-preview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(260px, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}

.theme-preview-card {
  min-width: 0;
  overflow: hidden;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

.theme-preview-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid hsl(var(--border));
}

.theme-preview-card__head > div {
  min-width: 0;
}

.theme-preview-card__head strong,
.theme-preview-card__head span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.theme-preview-card__head span {
  margin-top: 4px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.theme-preview-card :deep(.client-phone-preview) {
  padding: 12px;
  background: hsl(var(--muted) / 28%);
}

.theme-preview-card__summary {
  padding: 12px 14px 0;
}

.theme-preview-card__actions {
  display: flex;
  justify-content: flex-end;
  min-height: 42px;
  padding: 8px 12px 12px;
}

.theme-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 18px;
}

.theme-preview-empty {
  padding: 56px 0;
  border: 1px dashed hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--muted) / 18%);
}

.token-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.token-chip {
  display: inline-flex;
  align-items: center;
  max-width: 190px;
  gap: 5px;
  padding: 4px 8px;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
}

.token-chip i {
  width: 14px;
  height: 14px;
  border: 1px solid hsl(var(--border));
  border-radius: 3px;
}

.token-chip span,
.token-chip em {
  overflow: hidden;
  font-size: 12px;
  font-style: normal;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.token-chip em {
  color: hsl(var(--muted-foreground));
}

.token-editor {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.advanced-hint {
  margin-bottom: 10px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.token-row {
  display: grid;
  grid-template-columns:
    minmax(150px, 0.9fr) minmax(140px, 0.7fr) minmax(180px, 1fr)
    auto;
  gap: 8px;
  align-items: center;
  padding: 10px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.token-info {
  min-width: 0;
}

.token-info strong,
.token-info span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.token-info span {
  margin-top: 3px;
  font-size: 12px;
}

.token-code {
  overflow: hidden;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.color-input {
  width: 28px;
  height: 24px;
  padding: 0;
  border: 0;
  background: transparent;
}

.type-hint {
  margin-left: 8px;
  color: hsl(var(--muted-foreground));
}

.theme-modal-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: 18px;
  align-items: start;
}

.theme-modal-preview {
  position: sticky;
  top: 0;
  min-width: 0;
  padding: 14px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

@media (max-width: 980px) {
  .setting-grid,
  .theme-modal-grid,
  .token-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 1500px) {
  .theme-preview-grid {
    grid-template-columns: repeat(2, minmax(260px, 1fr));
  }
}

@media (max-width: 980px) {
  .theme-preview-grid {
    grid-template-columns: 1fr;
  }
}
</style>
